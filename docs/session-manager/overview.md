---
eyebrow: 'Docs · Session manager'
lede:    'A long-running PHP daemon that holds OPC UA sessions on behalf of short-lived Symfony processes. What it is, when you need it, when you don''t.'

see_also:
  - { href: './starting-the-daemon.md',          meta: '5 min' }
  - { href: './auto-publish.md',                 meta: '5 min' }
  - { href: '../using-the-client/connection-lifecycle.md', meta: '5 min' }

prev: { label: 'History', href: '../operations/history.md' }
next: { label: 'Starting the daemon', href: './starting-the-daemon.md' }
---

# Overview

`opcua-session-manager` is a long-running PHP process that holds
OPC UA sessions in memory. Symfony processes (HTTP workers,
Messenger consumers, scheduler workers) talk to it over local
IPC instead of opening sessions directly.

## Architecture

<!-- @code-block language="text" label="topology" -->
```text
┌──────────────────────────────────────────────────────────┐
│                  Symfony application                     │
│   ┌─────────────┐  ┌──────────────┐  ┌──────────────┐    │
│   │  FPM req 1  │  │  FPM req 2   │  │ Messenger    │    │
│   │             │  │              │  │ consumer     │    │
│   └──────┬──────┘  └──────┬───────┘  └──────┬───────┘    │
│          │                │                 │            │
│          └────────────────┼─────────────────┘            │
│                           │                              │
│                  unix:// / tcp:// IPC                    │
└───────────────────────────┼──────────────────────────────┘
                            │
            ┌───────────────▼────────────────┐
            │       OPC UA Session Manager   │
            │     ┌────────┐ ┌────────┐      │   long-lived
            │     │session1│ │session2│ ...  │
            │     └───┬────┘ └───┬────┘      │
            └─────────┼──────────┼───────────┘
                      │          │
              ┌───────▼─┐    ┌───▼────────┐
              │ PLC #1  │    │  PLC #2    │
              └─────────┘    └────────────┘
```
<!-- @endcode-block -->

## What it solves

### 1 — Session reuse across requests

OPC UA session establishment is expensive — handshake + key
exchange under `Sign` / `SignAndEncrypt`. A typical secured
connection costs 200-500 ms.

In FPM, every request reconnects. Latency adds up fast.

The daemon opens once, reuses many. Two FPM requests targeting
the same server share one server-side session.

### 2 — Subscriptions that outlive requests

A direct-mode `Subscription` lives only as long as the PHP
process that created it. FPM requests are seconds.
Subscriptions need hours.

The daemon owns the subscription. Symfony processes come and go;
the daemon polls publish responses continuously.

### 3 — One identity per `(endpoint, identity)` tuple

OPC UA servers often cap concurrent sessions per identity. With
direct mode, each Symfony worker uses its own session. 50
workers ≈ 50 sessions, breaching the cap.

Daemon-held sessions are pooled. 50 workers, 1 daemon, 1
server-side session per identity.

## When to use it

| Use case                                          | Direct mode | Managed mode    |
| ------------------------------------------------- | ----------- | --------------- |
| Single console command, one-shot read              | ✓           | overkill        |
| Scheduled task every 5 minutes                     | ✓           | overkill        |
| FPM endpoint reading occasionally                   | ✓           | optional        |
| FPM endpoint, many OPC UA calls per request         | optional    | **recommended** |
| Real-time subscription feeding a UI                | hard        | **required**    |
| Production app with 5+ FPM workers                  | optional    | **recommended** |
| Multi-tenant with 100+ connections                   | hard        | **recommended** |
| Server-side session limit < worker count            | breaks      | **required**    |

The rule of thumb: **direct mode for scripts, managed mode for
applications**.

## When NOT to use it

- Single-process scripts where the OPC UA session should die
  with the process.
- Environments where you can't run a sidecar (some PaaS, some
  serverless functions).
- Air-gapped tests where the extra moving part isn't worth it.

## How the bundle picks the mode

At `$opcua->connect()` time:

1. **Is `session_manager.enabled: true`?** If not → direct.
2. **Is the daemon reachable on `socket_path`?**
   - Unix socket: `file_exists()`.
   - TCP loopback: presence is **assumed** — first IPC call
     surfaces the error.
3. **Reachable → managed.** Otherwise → direct.

You can force a mode:

<!-- @code-block language="text" label="force direct" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        enabled: false       # always direct
```
<!-- @endcode-block -->

## ManagedClient — the wrapper

In managed mode, the bundle returns `ManagedClient` instead of
`Client`. Both implement `OpcUaClientInterface`, so application
code doesn't care.

What `ManagedClient` does internally:

1. Talks to the daemon over the configured transport
   (`UnixSocketTransport` or `TcpLoopbackTransport`).
2. Each call (`read`, `write`, ...) serialises to a JSON
   envelope, sends to the daemon, decodes the response.
3. The daemon dispatches to its in-memory session.

The wire protocol is in
[IPC docs](https://github.com/php-opcua/opcua-session-manager/blob/master/docs/ipc/envelope-and-framing.md).

## The daemon's lifecycle

The daemon is **independent of Symfony's runtime**. It's a PHP
CLI process that:

- Listens on a Unix socket or TCP port.
- Holds OPC UA sessions.
- Optionally publishes subscription events (auto-publish).

You launch it with `php bin/console opcua:session` (the
Symfony-wired entry point) or `vendor/bin/opcua-session-manager`
(raw CLI). In production, run under systemd / Supervisor /
Docker — see [Production supervisor](./production-supervisor.md).

## Per-deployment topology

A typical production topology:

| Component             | Process count | Notes                                  |
| --------------------- | ------------- | -------------------------------------- |
| Symfony FPM workers    | 8-32          | Talk to the daemon                     |
| Messenger consumers    | 4-16          | Talk to the daemon                     |
| FrankenPHP workers     | 4-16          | Talk to the daemon                     |
| OPC UA daemon          | **1**         | Single tenant, holds all sessions      |
| Mercure hub             | 1            | Optional, broadcasts subscription data  |

Single daemon, many clients.

## Multi-tenant deployments

For hard-isolation multi-tenant deployments, run **one daemon
per tenant**, each on its own socket. The bundle supports
multiple socket paths via config — see
[Recipes · Multi-plant tenant](../recipes/multi-plant-tenant.md).

For shared deployments where tenant isolation is at the
OPC UA identity layer (different username/cert per tenant
connection), one daemon is sufficient.

## Where to read next

- [Starting the daemon](./starting-the-daemon.md) — the
  `opcua:session` command surface.
- [Auto-publish](./auto-publish.md) — the subscription → events
  bridge.
- [Production supervisor](./production-supervisor.md) —
  systemd / Supervisor / Docker unit files.
