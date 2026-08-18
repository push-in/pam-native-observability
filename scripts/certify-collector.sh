#!/usr/bin/env bash
set -euo pipefail

repository=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
image='ghcr.io/open-telemetry/opentelemetry-collector-releases/opentelemetry-collector:0.157.0@sha256:4019ce4d7e7791a1a255fffb2f407af66d5017cc65543469ba565c4f47f795b8'
container="pam-native-otel-cert-${$}"
image_present=0
evidence=$(mktemp -d /tmp/pam-native-otel-evidence.XXXXXX)

if docker image inspect "${image}" >/dev/null 2>&1; then
    image_present=1
fi

cleanup() {
    docker container rm --force "${container}" >/dev/null 2>&1 || true
    if [[ "${image_present}" -eq 0 ]]; then
        docker image rm "${image}" >/dev/null 2>&1 || true
    fi
    find "${evidence}" -maxdepth 1 -type f -delete 2>/dev/null || true
    rmdir "${evidence}" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

docker run --detach --name "${container}" \
    --cap-drop ALL \
    --read-only \
    --security-opt no-new-privileges=true \
    --publish 127.0.0.1::4318 \
    --volume "${repository}/tests/fixtures/otel-collector.yaml:/etc/otelcol/config.yaml:ro" \
    "${image}" --config=/etc/otelcol/config.yaml >/dev/null
port=$(docker port "${container}" 4318/tcp | awk -F: 'NR == 1 { print $NF }')
if [[ ! "${port}" =~ ^[0-9]+$ ]]; then
    printf 'Collector did not publish a valid port\n' >&2
    exit 1
fi

ready=0
for _ in $(seq 1 100); do
    status=$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 1 \
        --header 'content-type: application/json' --data '{"resourceSpans":[]}' \
        "http://127.0.0.1:${port}/v1/traces" || true)
    if [[ "${status}" == '200' ]]; then
        ready=1
        break
    fi
    sleep 0.05
done
if [[ "${ready}" -ne 1 ]]; then
    docker logs "${container}" >&2 || true
    printf 'Collector did not become ready\n' >&2
    exit 1
fi

PAM_OTLP_ENDPOINT="http://127.0.0.1:${port}" php "${repository}/tests/collector.php"
accepted=0
for _ in $(seq 1 100); do
    docker logs "${container}" >"${evidence}/collector.log" 2>&1
    if grep -Fq 'native.feed.load' "${evidence}/collector.log" \
        && grep -Fq 'native-ready' "${evidence}/collector.log" \
        && grep -Fq 'native.render.count' "${evidence}/collector.log" \
        && grep -Fq 'native.memory.bytes' "${evidence}/collector.log"; then
        accepted=1
        break
    fi
    sleep 0.05
done
if [[ "${accepted}" -ne 1 ]]; then
    cat "${evidence}/collector.log" >&2
    printf 'Collector did not expose every PAM Native signal family\n' >&2
    exit 1
fi
if grep -Fq 'must-not-leak' "${evidence}/collector.log"; then
    printf 'private exception message leaked into OTLP\n' >&2
    exit 1
fi
printf 'PAM Native OTLP traces, logs, counters and gauges accepted by the official Collector.\n'
