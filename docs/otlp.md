# Certified OTLP export

> Moved out of the README; nothing was removed.


`WireProtocol::OtlpHttpJson` maps each signal to the matching OpenTelemetry
Protocol endpoint instead of relabeling PAM's original envelope:

| PAM signal | OTLP payload | Endpoint |
| --- | --- | --- |
| Span | `resourceSpans` | `/v1/traces` |
| Log and crash context | `resourceLogs` | `/v1/logs` |
| Counter and gauge | `resourceMetrics` | `/v1/metrics` |

Counters use delta temporality; integer and floating-point points keep their
wire types. Log severity and span status are translated at the external OTLP
boundary, while PAM's public enums remain sequential integers beginning at
`1`. Batches never mix signal families, and any transport or encoding failure
restores the complete batch to the bounded queue.

Remote endpoints require HTTPS. Loopback HTTP is accepted only for local
Collector development and certification. The cURL transport refuses redirects
to HTTP, limits response bodies to 64 KiB, and treats OTLP `partialSuccess`
rejections as failed delivery.

The official-Collector contract is reproducible:

```bash
pam composer install
scripts/certify-collector.sh
```

CI verifies the Collector's Sigstore identity and immutable image digest before
testing traces, logs, counters, gauges, parent span lineage, and exception-data
redaction.


