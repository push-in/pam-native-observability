<?php

declare(strict_types=1);

namespace App;

use LogicException;
use Pam\Native\Component;
use Pam\Native\Element;
use Pam\Native\Observability\ExporterStatus;
use Pam\Native\Observability\Observability;
use Pam\Native\Observability\Severity;
use Pam\Native\Style;
use Pam\Native\UI\Button;
use Pam\Native\UI\Column;
use Pam\Native\UI\SafeAreaView;
use Pam\Native\UI\Screen;
use Pam\Native\UI\Text;
use RuntimeException;

final class DiagnosticsDemo extends Component
{
    /** Paste your Sentry DSN (https://<public-key>@<host>/<project-id>). Empty disables the exporter. */
    public const string DSN = '';

    private string $status = 'Tap "Exporter status"';

    /** @var list<string> */
    private array $log = [];

    public function render(): Element
    {
        return Screen::make(
            SafeAreaView::make(
                Column::make(
                    Text::make('Observability')->style(new Style(fontSize: 24, fontWeight: 700)),
                    Text::make($this->status),
                    Button::make('Exporter status')->onPress($this->exporterStatus(...)),
                    Button::make('Send test event')->onPress($this->testEvent(...)),
                    Button::make('Capture handled error')->onPress($this->captureHandled(...)),
                    Button::make('Throw in a press handler')->onPress($this->throwUnhandled(...)),
                    Button::make('Sign in as user 42')->onPress($this->signIn(...)),
                    ...array_map(static fn (string $line): Text => Text::make($line), array_slice($this->log, -8)),
                )->style(new Style(flexGrow: 1, padding: 24, gap: 12)),
            ),
        );
    }

    public function exporterStatus(): void
    {
        Observability::exporterStatus(function (ExporterStatus $status): void {
            $this->status = $status->enabled
                ? sprintf('Sentry %s · %s · ANR %s · native %s · last %s', $status->host, $status->platform, $status->anr ? 'on' : 'off', $status->nativeCrashes ? 'on' : 'off', $status->lastEventId ?? '-')
                : 'Exporter disabled '.$status->error;
        });
    }

    public function testEvent(): void
    {
        Observability::exporterTest(function (bool $ok, string $idOrError): void {
            $this->log[] = $ok ? 'Test event '.$idOrError : 'Test failed: '.$idOrError;
        });
    }

    public function captureHandled(): void
    {
        Observability::breadcrumb('Pressed "Capture handled error"', 'ui');
        try {
            throw new RuntimeException('Feed request failed', previous: new LogicException('Cursor expired'));
        } catch (RuntimeException $error) {
            $sent = Observability::capture($error, tags: ['screen' => 'diagnostics'], then: function (?string $id): void {
                $this->log[] = 'Captured '.($id ?? '(dropped)');
            });
            if (!$sent) {
                $this->log[] = 'Not sent: no exporter, duplicate within 60 s or rate limited';
            }
        }
        Observability::message('Diagnostics opened', Severity::Info);
    }

    public function throwUnhandled(): void
    {
        // Reaches App::onError() in index.php, then the error overlay (debug builds).
        throw new RuntimeException('Unhandled error from a press handler');
    }

    public function signIn(): void
    {
        Observability::user('42', username: 'demo');
        Observability::tag('plan', 'free');
        $this->log[] = 'User 42 attached to future events';
    }
}
