---
eyebrow: 'Docs · Session manager'
lede:    'The php bin/console opcua:session command — every option, every wire to Symfony''s logger / cache / event dispatcher.'

see_also:
  - { href: './overview.md',               meta: '5 min' }
  - { href: './production-supervisor.md',   meta: '6 min' }
  - { href: './monitoring-the-daemon.md',   meta: '5 min' }

prev: { label: 'Overview',     href: './overview.md' }
next: { label: 'Auto-publish', href: './auto-publish.md' }
---

# Starting the daemon

The bundle ships a Symfony console command that launches the
daemon with Symfony-side wiring (logger, cache, event
dispatcher) flowing in:

<!-- @code-block language="bash" label="terminal — basic" -->
```bash
php bin/console opcua:session
```
<!-- @endcode-block -->

That reads `session_manager.*` from
`config/packages/php_opcua_symfony_opcua.yaml` and starts the
daemon. Blocks until `Ctrl+C` or signalled.

## Why the console command?

Three things `php bin/console opcua:session` does that
`vendor/bin/opcua-session-manager` doesn't:

1. **Wires `LoggerInterface`** from the configured Monolog
   channel (`session_manager.log_channel` → `monolog.logger.<name>`).
2. **Wires `CacheInterface`** from the configured Symfony cache
   pool (`session_manager.cache_pool` → `cache.app` by default,
   wrapped in `Psr16Cache`).
3. **Wires `EventDispatcherInterface`** — Symfony's dispatcher,
   for [auto-publish](./auto-publish.md).

Run the console command unless you have a specific reason not
to.

## Options

| Option                    | Default                       | Effect                                       |
| ------------------------- | ----------------------------- | -------------------------------------------- |
| `--timeout=<sec>`          | `session_manager.timeout`      | Idle session timeout                          |
| `--cleanup-interval=<sec>`  | `session_manager.cleanup_interval` | Reaper loop period                       |
| `--max-sessions=<n>`        | `session_manager.max_sessions` | Cap on concurrent sessions                   |
| `--socket-mode=<oct>`       | `session_manager.socket_mode`  | Unix socket permissions                       |

CLI flags override YAML config for that one invocation. All
flags accept `--socket-mode=0660` octal form.

## Examples

### Development

<!-- @code-block language="bash" label="dev" -->
```bash
php bin/console opcua:session
```
<!-- @endcode-block -->

Pure defaults. Socket at `var/opcua-session-manager.sock`.

### Custom timeout

<!-- @code-block language="bash" label="custom timeout" -->
```bash
php bin/console opcua:session --timeout=1800 --max-sessions=50
```
<!-- @endcode-block -->

### Production-style

Production typically pulls all settings from YAML; the command
runs with no flags:

<!-- @code-block language="bash" label="prod" -->
```bash
APP_ENV=prod php bin/console opcua:session
```
<!-- @endcode-block -->

…with `config/packages/prod/php_opcua_symfony_opcua.yaml`:

<!-- @code-block language="text" label="prod config" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        socket_path:       /var/run/opcua/sessions.sock
        socket_mode:       0660
        timeout:           600
        cleanup_interval:  30
        max_sessions:      100
        auth_token:        '%env(secret:OPCUA_AUTH_TOKEN)%'
        log_channel:       opcua
        cache_pool:        cache.redis
        auto_publish:      true
        allowed_cert_dirs:
            - /etc/opcua/certs
            - /var/lib/opcua/trust
```
<!-- @endcode-block -->

Run under systemd — see
[Production supervisor](./production-supervisor.md).

## What happens at start

1. **Boot Symfony** (kernel boot, container compile).
2. **Resolve** the log channel and cache pool services.
3. **Resolve** `EventDispatcherInterface` if `auto_publish` is on.
4. **Construct** `SessionManagerDaemon` with the wired deps.
5. **Pre-connect** configured connections if `auto_connect` is on
   on individual connections.
6. **Bind** the socket, set permissions, start listening.
7. **Block** in the event loop.

First log line:

<!-- @code-block language="text" label="startup log" -->
```text
[2026-05-15 10:00:00] opcua.INFO: SessionManagerDaemon started
  socket=/var/run/opcua/sessions.sock mode=0660
  timeout=600 cleanup=30 max_sessions=100 auto_publish=true
```
<!-- @endcode-block -->

## Auto-connect on startup

Connections with `auto_connect: true` and a `subscriptions:`
block are connected on the first event loop tick:

<!-- @code-block language="text" label="auto-connect" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        auto_publish: true
    connections:
        plc-line-a:
            endpoint:     '%env(OPCUA_LINE_A)%'
            auto_connect: true
            subscriptions:
                -
                    publishing_interval: 500.0
                    monitored_items:
                        - { node_id: 'ns=2;s=Speed',       client_handle: 1 }
                        - { node_id: 'ns=2;s=Temperature', client_handle: 2 }
```
<!-- @endcode-block -->

The daemon opens the session, creates the subscription, and
starts publishing events to Symfony's EventDispatcher. See
[Auto-publish](./auto-publish.md).

## Stopping the daemon

Send `SIGTERM` for graceful shutdown:

<!-- @code-block language="bash" label="terminal" -->
```bash
sudo systemctl stop opcua-session-manager
# or by PID
kill -TERM <pid>
```
<!-- @endcode-block -->

`SIGINT` (Ctrl+C) does the same. On exit the daemon:

1. Stops accepting new connections.
2. Closes all OPC UA sessions cleanly.
3. Unlinks the Unix socket file.
4. Exits with status 0.

## Restarting

After deploying new code, **restart** the daemon — it caches the
autoload index at boot:

<!-- @code-block language="bash" label="terminal" -->
```bash
sudo systemctl restart opcua-session-manager
```
<!-- @endcode-block -->

This briefly closes all daemon-held sessions. Symfony processes
reconnect on the next IPC call.

## Multiple daemons

The bundle supports multiple daemons via per-connection
`socket_path` overrides. Pattern:

<!-- @code-block language="bash" label="two daemons" -->
```bash
# Tenant A
APP_ENV=prod php bin/console opcua:session \
    --socket-path=/var/run/opcua/tenant-a.sock &

# Tenant B (different .env)
APP_ENV=prod_tenant_b php bin/console opcua:session \
    --socket-path=/var/run/opcua/tenant-b.sock &
```
<!-- @endcode-block -->

The bundle doesn't yet accept `--socket-path` flag — set
`OPCUA_SOCKET_PATH` env var per daemon invocation:

<!-- @code-block language="bash" label="env-per-daemon" -->
```bash
OPCUA_SOCKET_PATH=/var/run/opcua/tenant-a.sock php bin/console opcua:session &
OPCUA_SOCKET_PATH=/var/run/opcua/tenant-b.sock php bin/console opcua:session &
```
<!-- @endcode-block -->

With the bundle config pointing at `%env(OPCUA_SOCKET_PATH)%`.

See [Recipes · Multi-plant tenant](../recipes/multi-plant-tenant.md).

## Running outside Symfony

If you need the daemon without Symfony's runtime:

<!-- @code-block language="bash" label="raw CLI" -->
```bash
vendor/bin/opcua-session-manager \
    --socket /var/run/opcua/sessions.sock \
    --timeout 600 \
    --auth-token "${OPCUA_AUTH_TOKEN}"
```
<!-- @endcode-block -->

You lose the Symfony wiring — no Monolog channel, no Symfony
cache pool, no EventDispatcher. The daemon falls back to a
null logger and in-memory cache. For most production needs the
console command is the right choice.

## Where to read next

- [Auto-publish](./auto-publish.md) — declarative subscriptions
  + EventDispatcher delivery.
- [Production supervisor](./production-supervisor.md) — systemd
  / Supervisor units for the daemon.
- [Monitoring the daemon](./monitoring-the-daemon.md) — liveness,
  metrics, logs.
