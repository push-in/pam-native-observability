<?php

declare(strict_types=1);

use App\DiagnosticsDemo;
use Pam\Native\App;
use Pam\Native\Diagnostics\RuntimeError;
use Pam\Native\Observability\Observability;
use Pam\Native\Observability\SentryExporter;

require __DIR__.'/vendor/autoload.php';

// Start crash reporting before the first render (Zé Chat's CrashReporting::start()).
if (DiagnosticsDemo::DSN !== '') {
    Observability::exporter(
        SentryExporter::dsn(DiagnosticsDemo::DSN)
            ->environment('development')
            ->release('observability-demo@0.1.0')
            ->tracesSampleRate(1.0),
    );
}

// Errors thrown inside renders, handlers and module callbacks (PAM Native 1.7+).
App::onError(static function (Throwable $error, RuntimeError $context): void {
    Observability::capture($error, handled: !$context->fatal(), tags: ['phase' => $context->phase->value]);
});

App::theme(\Pam\Native\Theme::pamLab());
App::run(new DiagnosticsDemo());
