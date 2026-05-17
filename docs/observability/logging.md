---
eyebrow: 'Docs · Observability'
lede:    'How the bundle logs, what it logs, and how to wire its log channel into Monolog. Per-connection channels, runtime overrides, and the structured-logging pattern.'

see_also:
  - { href: './debugging.md',                          meta: '5 min' }
  - { href: '../session-manager/monitoring-the-daemon.md', meta: '5 min' }

prev: { label: 'Async listeners with Messenger',  href: '../events/async-listeners-with-messenger.md' }
next: { label: 'Caching',                          href: './caching.md' }
---

# Logging

The bundle logs through Symfony's `LoggerInterface`. Every log
message is in **one of two surfaces**:

1. **Client-side** — your Symfony process emits log lines for
   connection events, errors, warnings.
2. **Daemon-side** — `php bin/console opcua:session` emits lines
   for session lifecycle, IPC errors, subscription state.

Both go through Monolog, so the same channel configuration
applies.

## Default channel

Without configuration, the bundle logs to Symfony's default
`logger` service. Lines mix in with the rest of the app:

<!-- @code-block language="text" label="default log" -->
```text
[2026-05-15T10:15:23] request.INFO: User logged in
[2026-05-15T10:15:24] app.INFO: OPCUA connection opened plc-line-a
[2026-05-15T10:15:25] request.WARNING: Throttled
[2026-05-15T10:15:26] app.ERROR: OPCUA connection failed plc-line-b
```
<!-- @endcode-block -->

Fine for low-volume use. For production, dedicate a channel.

## Dedicated channel

`config/packages/monolog.yaml`:

<!-- @code-block language="text" label="monolog config" -->
```text
monolog:
    channels: ['opcua', 'opcua_data']

    handlers:
        opcua_file:
            type: rotating_file
            path: '%kernel.logs_dir%/opcua.log'
            max_files: 14
            level: '%env(default:opcua_default_level:OPCUA_LOG_LEVEL)%'
            channels: ['opcua']
            formatter: monolog.formatter.json

        opcua_data_file:
            type: rotating_file
            path: '%kernel.logs_dir%/opcua-data.log'
            max_files: 7
            level: info
            channels: ['opcua_data']
```
<!-- @endcode-block -->

In `services.yaml`:

<!-- @code-block language="text" label="services.yaml" -->
```text
parameters:
    opcua_default_level: info
```
<!-- @endcode-block -->

Bundle config to use it:

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        log_channel: opcua
    connections:
        plc-line-a:
            endpoint:    '%env(OPCUA_LINE_A_ENDPOINT)%'
            log_channel: opcua
        plc-line-b:
            endpoint:    '%env(OPCUA_LINE_B_ENDPOINT)%'
            log_channel: opcua            # same channel
```
<!-- @endcode-block -->

Or different channels per connection — see [Per-connection
channels](#per-connection-channels) below.

## What the bundle logs

| Level     | When                                                | Example                                          |
| --------- | --------------------------------------------------- | ------------------------------------------------ |
| `debug`   | Per-call detail (with `APP_DEBUG=1`)                 | "Calling read for nodeId='ns=2;s=Speed'"          |
| `info`    | Connection lifecycle, daemon state changes           | "Connection plc-line-a opened"                   |
| `notice`  | Recoverable degradation (managed → direct fallback)  | "Daemon unreachable, falling back to direct"     |
| `warning` | Transient failures with auto-recovery                | "Read retry 2/3 for ns=2;s=Speed"                |
| `error`   | Failed operations surfaced as exceptions             | "Write rejected: Bad_TypeMismatch"               |
| `critical` | Failed-state daemon, lost subscriptions             | "Daemon publish loop stuck"                      |

The bundle **never** logs:

- Passwords or auth tokens.
- Cert private keys.
- Raw OPC UA values from data changes.

For value logging, add an explicit listener and log through
your channel.

## Per-connection channels

For per-line log separation:

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    connections:
        plc-line-a:
            endpoint:    '%env(OPCUA_LINE_A_ENDPOINT)%'
            log_channel: plc-line-a
        plc-line-b:
            endpoint:    '%env(OPCUA_LINE_B_ENDPOINT)%'
            log_channel: plc-line-b
```
<!-- @endcode-block -->

…with matching channel definitions:

<!-- @code-block language="text" label="monolog.yaml" -->
```text
monolog:
    channels: ['plc-line-a', 'plc-line-b']
    handlers:
        plc-a-file:
            type: rotating_file
            path: '%kernel.logs_dir%/plc-a.log'
            channels: ['plc-line-a']
            level: info
        plc-b-file:
            type: rotating_file
            path: '%kernel.logs_dir%/plc-b.log'
            channels: ['plc-line-b']
            level: info
```
<!-- @endcode-block -->

The bundle's `LoggerResolverFactory` builds a `ServiceLocator`
mapping each channel name to the `monolog.logger.<channel>`
service at compile time. Undeclared channels resolve to `null`
and the manager falls back to its default logger — no error.

## Stacked handlers — Slack on errors

<!-- @code-block language="text" label="stacked" -->
```text
monolog:
    handlers:
        opcua_grouped:
            type: group
            members: [opcua_file, opcua_slack]

        opcua_file:
            type: rotating_file
            path: '%kernel.logs_dir%/opcua.log'
            max_files: 14
            channels: ['opcua']
            level: info

        opcua_slack:
            type: slack
            token: '%env(SLACK_BOT_TOKEN)%'
            channel: '#ops-alerts'
            channels: ['opcua']
            level: error
            include_extra: true
```
<!-- @endcode-block -->

File handler gets everything; Slack gets `error` and above.

## Structured logging — JSON

For machine-readable logs (ELK, Loki, CloudWatch):

<!-- @code-block language="text" label="JSON" -->
```text
monolog:
    handlers:
        opcua_json:
            type: stream
            path: '%kernel.logs_dir%/opcua.json'
            channels: ['opcua']
            formatter: monolog.formatter.json
```
<!-- @endcode-block -->

Lines like:

<!-- @code-block language="text" label="JSON output" -->
```text
{"datetime":"2026-05-15T10:15:24+00:00","channel":"opcua","level_name":"INFO","message":"Connection opened","context":{"connection":"plc-line-a","endpoint":"opc.tcp://...","duration_ms":312}}
```
<!-- @endcode-block -->

Ready for ingestion.

## Runtime logger override

For one-off scripts (Symfony commands) that want extra-verbose
output flowing through the OPC UA client:

<!-- @code-block language="php" label="useConsoleLogger" -->
```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    // Forward -v / -vv / -vvv to the client's logger, with a millisecond timestamp prefix
    $this->opcua->useConsoleLogger($output);

    // Or without the timestamp wrap:
    $this->opcua->useConsoleLogger($output, dateFormat: null);

    $dv = $this->opcua->connect()->read('i=2259');
    // ...
}
```
<!-- @endcode-block -->

This runtime override beats both the per-connection
`log_channel` and the default logger — useful for debugging
production behaviour locally without changing config.

## Daemon-side logging

When the daemon runs via `php bin/console opcua:session`, it
inherits the Symfony-wired logger. With
`session_manager.log_channel: opcua`, daemon output lands in
the `monolog.logger.opcua` handler chain.

Without Symfony (raw CLI), the daemon falls back to a
stderr-style logger. Useful only in Docker / containerised
environments where stderr goes to the orchestrator's log
system.

## Log volume — what to expect

| Workload                                       | Lines per minute |
| ---------------------------------------------- | ---------------- |
| 1 connection, no subscriptions                  | 0-5              |
| 5 connections, low-freq reads                   | 5-20             |
| 5 connections, full auto-publish, 100 tags       | 50-200           |
| Subscription drops + reconnect storm             | 1000+ briefly    |

Set `OPCUA_LOG_LEVEL=info` for production. `debug` is too
verbose to sustain.

## Sensitive data in listener logs

Listeners are **your** code. Be deliberate:

<!-- @code-block language="php" label="careful listener" -->
```php
#[AsEventListener]
public function __invoke(DataChangeReceived $event): void
{
    // OK — node id, status, time. No value.
    $this->logger->info('data change', [
        'node'   => $event->nodeId,
        'status' => $event->dataValue->statusCode,
        'at'     => $event->dataValue->sourceTimestamp?->format('c'),
    ]);
}
```
<!-- @endcode-block -->

Logging values is fine when the value's PII risk is zero, volume
is low, and you actually need the data.

## Where to read next

- [Caching](./caching.md) — what the bundle caches.
- [Debugging](./debugging.md) — when logs aren't enough.
- [Profiler and data collectors](./profiler-and-data-collectors.md) —
  request-level observability.
