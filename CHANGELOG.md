# Changelog

## Unreleased

- Add certified OTLP/HTTP JSON encoding for traces, logs, crashes, delta
  counters, and gauges with signal-specific endpoints.
- Preserve the schema 1 PAM JSON wire protocol as the default compatibility
  mode through the sequential integer-backed `WireProtocol` enum.
- Add bounded Collector responses, partial-success rejection, full-batch
  restoration, loopback-only HTTP, opt-in exception details, PHPStan level 9,
  a reproducible dependency lock, and official-Collector CI certification.

## 0.1.0 - 2026-08-01

- Initial public release of the documented PAM Native package contract.
- Add bounded input validation, sequential integer protocol enums, automated
  package tests, and PHP 8.4/8.5 continuous integration.
