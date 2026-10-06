<?php

declare(strict_types=1);

/*
 * Sentry exporter contracts. Included by tests/run.php (shares test(), same(),
 * truth() and throws()); uses the official PAM Native fake module transport.
 */

use Pam\Native\Internal\Wire;
use Pam\Native\Observability\ExporterStatus;
use Pam\Native\Observability\Observability;
use Pam\Native\Observability\ObservabilityConfig;
use Pam\Native\Observability\SentryExporter;
use Pam\Native\Observability\SentryForwarder;
use Pam\Native\Observability\Severity;
use Pam\Native\Testing\FakeNativeModuleTransport;
use Pam\Native\Testing\NativeTestHarness;

$frameworkRoots = [
    'Pam\\Native\\Testing\\' => dirname(__DIR__, 2).'/pam-native-testing/src/',
    'Pam\\Native\\' => dirname(__DIR__, 2).'/../pam-native/packages/native/src/',
];
if (!class_exists(NativeTestHarness::class)) {
    spl_autoload_register(static function (string $class) use ($frameworkRoots): void {
        if (str_starts_with($class, 'Pam\\Native\\Observability\\')) {
            return;
        }
        foreach ($frameworkRoots as $prefix => $root) {
            if (str_starts_with($class, $prefix)) {
                $file = $root.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (is_file($file)) {
                    require $file;
                }

                return;
            }
        }
    });
}

/** @return array<array-key, mixed> */
function decodedField(string $payload, string $field): array
{
    $text = Wire::decodeMap($payload)[$field] ?? '';
    $decoded = json_decode(is_string($text) ? $text : '', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException("Expected a JSON object in {$field}");
    }

    return $decoded;
}

/**
 * @param array<array-key, mixed> $value
 * @param list<int|string> $path
 */
function valueAt(array $value, array $path): mixed
{
    $current = $value;
    foreach ($path as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            throw new RuntimeException('Missing '.implode('.', $path));
        }
        $current = $current[$key];
    }

    return $current;
}

/** @return list<array<array-key, mixed>> */
function sentryEvents(FakeNativeModuleTransport $fake): array
{
    $events = [];
    foreach ($fake->calls() as $call) {
        if ($call->method === 'sentryCapture') {
            $events[] = decodedField($call->payload, 'event');
        }
    }

    return $events;
}

function sentryBoot(?SentryExporter $exporter = null): FakeNativeModuleTransport
{
    SentryForwarder::reset();
    $fake = NativeTestHarness::install();
    $fake->succeed('observability', 'sentryInit');
    Observability::exporter($exporter ?? SentryExporter::dsn('https://public@sentry.example.test/14')->capturePhpErrors(false));

    return $fake;
}

function throwFromDepth(int $depth): never
{
    if ($depth === 0) {
        throw new DomainException('Chat not found', 0, new RuntimeException('HTTP 404'));
    }
    throwFromDepth($depth - 1);
}

test('Sentry exporter validates and serializes its configuration', static function (): void {
    $fake = NativeTestHarness::install();
    $fake->succeed('observability', 'sentryInit');
    $ok = null;
    Observability::exporter(
        SentryExporter::dsn('https://7877af@sentry.pushin.test/14')
            ->environment('production')
            ->release('zechat@2.4.0+118')
            ->dist('118')
            ->sampleRate(1.0)
            ->tracesSampleRate(0.25)
            ->profilesSampleRate(0.05)
            ->tags(['app' => 'zechat'])
            ->anr(true, 5000)
            ->nativeCrashes()
            ->capturePhpErrors(false),
        static function (bool $success) use (&$ok): void { $ok = $success; },
    );
    $config = decodedField($fake->lastCall()->payload ?? '', 'config');
    same('https://7877af@sentry.pushin.test/14', $config['dsn'] ?? null, 'dsn');
    same('zechat@2.4.0+118', $config['release'] ?? null, 'release');
    same(0.25, $config['tracesSampleRate'] ?? null, 'traces');
    same(0.05, $config['profilesSampleRate'] ?? null, 'profiles');
    same(false, array_key_exists('profilesSampleRate', SentryExporter::dsn('https://k@sentry.pushin.test/1')->toWire()), 'profiling is opt-in');
    throws(static fn () => SentryExporter::dsn('https://k@sentry.pushin.test/1')->profilesSampleRate(1.5), 'profiles rate above 1 accepted');
    same(['app' => 'zechat'], $config['tags'] ?? null, 'tags');
    same([true, true, true], [$config['nativeCrashes'] ?? null, $config['anr'] ?? null, $config['persist'] ?? null], 'native capture defaults');
    same(false, $config['sendDefaultPii'] ?? null, 'no PII by default');
    same(true, $ok, 'init completion');
    foreach (['https://sentry.example.test/14', 'http://key@sentry.example.test/14', 'https://key@sentry.example.test/project', 'ftp://key@h/1'] as $invalid) {
        throws(static fn () => SentryExporter::dsn($invalid), 'invalid DSN accepted: '.$invalid);
    }
    SentryExporter::dsn('http://key@127.0.0.1:9000/3');
    NativeTestHarness::uninstall();
});

test('captured throwables carry PHP frames and chained causes', static function (): void {
    $fake = sentryBoot();
    $fake->succeed('observability', 'sentryCapture', ['eventId' => 'abc123']);
    $id = null;
    try {
        throwFromDepth(2);
    } catch (DomainException $error) {
        Observability::capture($error, tags: ['screen' => 'chat'], extra: ['chat' => 42, 'muted' => false], then: static function (?string $eventId) use (&$id): void { $id = $eventId; });
    }
    $event = sentryEvents($fake)[0];
    same('abc123', $id, 'event id');
    same(Severity::Error->value, $event['level'], 'level');
    same(['screen' => 'chat'], $event['tags'], 'tags');
    same(['chat' => '42', 'muted' => 'false'], $event['extra'], 'extra');
    same(['RuntimeException', 'DomainException'], [valueAt($event, ['exceptions', 0, 'type']), valueAt($event, ['exceptions', 1, 'type'])], 'root cause first');
    $frames = objectAt($event, ['exceptions', 1, 'frames']);
    same('{main}', valueAt($frames, [0, 'function']), 'oldest frame first');
    $last = count($frames) - 1;
    same('throwFromDepth', valueAt($frames, [$last, 'function']), 'throw site function');
    same(__FILE__, valueAt($frames, [$last, 'file']), 'throw site file');
    same(true, valueAt($frames, [$last, 'inApp']), 'in app');
    same('pam.php.capture', valueAt($event, ['exceptions', 1, 'mechanism']), 'mechanism');
    NativeTestHarness::uninstall();
});

test('forwarding is de-duplicated, rate limited and silent without an exporter', static function (): void {
    SentryForwarder::reset();
    truth(Observability::capture(new LogicException('nothing configured')) === false, 'no exporter, no native call');
    $fake = sentryBoot(SentryExporter::dsn('https://public@sentry.example.test/14')->capturePhpErrors(false)->rateLimit(3));
    for ($i = 0; $i < 3; $i++) {
        $fake->succeed('observability', 'sentryCapture', ['eventId' => "id-{$i}"]);
    }
    $error = new LogicException('same');
    truth(Observability::capture($error), 'first');
    truth(!Observability::capture($error), 'duplicate suppressed');
    truth(Observability::message('one', Severity::Warning), 'message');
    truth(Observability::capture(new LogicException('other')), 'third');
    truth(!Observability::capture(new LogicException('fourth')), 'rate limited');
    same(3, count(sentryEvents($fake)), 'three native events');
    same('one', sentryEvents($fake)[1]['message'], 'message event');
    NativeTestHarness::uninstall();
});

test('PHP errors and the telemetry crash pipeline are forwarded', static function (): void {
    $fake = sentryBoot(SentryExporter::dsn('https://public@sentry.example.test/14')->capturePhpErrors(true, E_USER_WARNING));
    $fake->succeed('observability', 'sentryCapture', ['eventId' => 'w']);
    $fake->succeed('observability', 'sentryCapture', ['eventId' => 'c']);
    $previous = error_reporting(E_ALL);
    @trigger_error('ignored while silenced', E_USER_WARNING);
    trigger_error('Disk almost full', E_USER_WARNING);
    error_reporting($previous);
    $telemetry = new Observability(new ObservabilityConfig('https://collector.example', 'app'), new FakeTransport());
    $telemetry->crash(new RuntimeException('render failed'));
    $events = sentryEvents($fake);
    same(2, count($events), 'warning + crash');
    same('ErrorException', valueAt($events[0], ['exceptions', 0, 'type']), 'error converted');
    same('Disk almost full', valueAt($events[0], ['exceptions', 0, 'value']), 'error message');
    same(Severity::Warning->value, $events[0]['level'], 'warning level');
    same('pam.php.error_handler', valueAt($events[0], ['exceptions', 0, 'mechanism']), 'error mechanism');
    same(false, $events[1]['handled'], 'unhandled crash');
    same(Severity::Fatal->value, $events[1]['level'], 'fatal level');
    NativeTestHarness::uninstall();
});

test('breadcrumbs, user, tags, guard, status and test event', static function (): void {
    $fake = sentryBoot();
    $fake->succeed('observability', 'sentryBreadcrumb');
    $fake->succeed('observability', 'sentryUser');
    $fake->succeed('observability', 'sentryTag');
    $fake->succeed('observability', 'sentryCapture', ['eventId' => 'g']);
    $fake->succeed('observability', 'sentryStatus', ['status' => json_encode(['enabled' => true, 'host' => 'sentry.example.test', 'environment' => 'production', 'nativeCrashes' => true, 'anr' => true, 'lastEventId' => 'g', 'platform' => 'Android 36'])]);
    $fake->succeed('observability', 'sentryTest', ['eventId' => 'diag']);
    $fake->succeed('observability', 'sentryStop');
    Observability::breadcrumb('Opened chat 42', 'navigation', ['chat' => 42]);
    Observability::user('42', username: 'ana');
    Observability::tag('screen', 'chat');
    throws(static fn () => Observability::guard(static fn () => throw new UnexpectedValueException('guarded')), 'guard rethrows');
    same('ok', Observability::guard(static fn (): string => 'ok'), 'guard returns');
    $status = null;
    Observability::exporterStatus(static function (ExporterStatus $s) use (&$status): void { $status = $s; });
    $diagnostic = null;
    Observability::exporterTest(static function (bool $ok, string $id) use (&$diagnostic): void { $diagnostic = [$ok, $id]; });
    Observability::exporterStop();
    $crumb = decodedField($fake->calls()[1]->payload, 'breadcrumb');
    same(['message' => 'Opened chat 42', 'category' => 'navigation', 'level' => 2, 'data' => ['chat' => '42']], $crumb, 'breadcrumb');
    same(['email' => '', 'id' => '42', 'username' => 'ana'], Wire::decodeMap($fake->calls()[2]->payload), 'user');
    same('pam.php.guard', valueAt(sentryEvents($fake)[0], ['exceptions', 0, 'mechanism']), 'guard mechanism');
    truth($status instanceof ExporterStatus && $status->enabled && $status->nativeCrashes && $status->lastEventId === 'g', 'status');
    same([true, 'diag'], $diagnostic, 'diagnostic event');
    truth(!SentryForwarder::active(), 'stopped');
    $fake->assertSatisfied();
    NativeTestHarness::uninstall();
});
