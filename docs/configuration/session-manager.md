---
eyebrow: 'Docs · Configuration'
lede:    'The session_manager block in depth. Every key, what the daemon does with it, and the env-driven patterns for dev / staging / production.'

see_also:
  - { href: '../session-manager/overview.md',              meta: '5 min' }
  - { href: '../session-manager/starting-the-daemon.md',    meta: '5 min' }
  - { href: '../session-manager/production-supervisor.md',  meta: '6 min' }

prev: { label: 'Security',                href: './security.md' }
next: { label: 'Parameters and overrides', href: './parameters-and-overrides.md' }
---

# Session manager configuration

The `session_manager` block tells the bundle three things:

1. Whether the daemon should be used at all.
2. Where to reach the daemon when it runs.
3. How the daemon behaves when the `opcua:session` command
   starts it.

## Full block

<!-- @code-block language="text" label="session_manager" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        enabled:           true
        socket_path:       '%kernel.project_dir%/var/opcua-session-manager.sock'
        timeout:           600
        cleanup_interval:  30
        auth_token:        '%env(secret:OPCUA_AUTH_TOKEN)%'
        max_sessions:      100
        socket_mode:       0600
        allowed_cert_dirs: []
        log_channel:       null
        cache_pool:        'cache.app'
        auto_publish:      false
```
<!-- @endcode-block -->

## Per-key reference

### `enabled`

Master switch. `false` = always direct mode regardless of socket
presence. Set this in `.env.test` so tests don't accidentally hit
a daemon socket left over from dev:

<!-- @code-block language="text" label=".env.test" -->
```text
OPCUA_SESSION_MANAGER_ENABLED=false
```
<!-- @endcode-block -->

…paired with:

<!-- @code-block language="text" label="bundle config" -->
```text
session_manager:
    enabled: '%env(bool:OPCUA_SESSION_MANAGER_ENABLED)%'
```
<!-- @endcode-block -->

### `socket_path`

Where the daemon listens and where the managed clients connect.
Three accepted forms:

| Form                            | Use case                                     |
| ------------------------------- | -------------------------------------------- |
| `unix:///abs/path.sock`          | POSIX explicit Unix-domain socket             |
| `tcp://127.0.0.1:9990`           | Windows / cross-process across containers     |
| `<plain path>`                   | Backwards-compat with pre-v4.2 configs       |

The default expands `%kernel.project_dir%` to the project root,
so the socket ends up next to `var/cache/`. On Windows where
Unix sockets aren't available, override to `tcp://127.0.0.1:9990`.

### `timeout`

Daemon-side idle session timeout (seconds). After this many
seconds of inactivity on a managed session, the daemon tears it
down. Default 600 (10 minutes).

Tune **up** for long-running subscriptions; tune **down** when
many short-lived clients fill the `max_sessions` cap.

### `cleanup_interval`

How often the daemon's reaper loop checks for expired sessions
(seconds). Default 30. Rarely needs tuning.

### `auth_token`

Shared secret between Symfony app and daemon. **Set this in
production.** Without it, any local process that can reach the
socket can issue commands.

Generate and store in the secrets vault:

<!-- @code-block language="bash" label="terminal" -->
```bash
TOKEN=$(php -r 'echo bin2hex(random_bytes(32));')
echo "$TOKEN" | php bin/console secrets:set OPCUA_AUTH_TOKEN -
```
<!-- @endcode-block -->

### `max_sessions`

Cap on concurrent daemon-held OPC UA sessions. Default 100. The
daemon refuses to open a 101st with `max_sessions_exceeded`.

Note this is **OPC UA sessions**, not Symfony clients. Many
Symfony workers can share one daemon session if their connection
config matches.

### `socket_mode`

File permissions on the Unix socket. Default `0600` (rw for
owner only). Use `0660` if FPM and `messenger` workers run under
different users but share a group.

Ignored for `tcp://` endpoints.

### `allowed_cert_dirs`

Whitelist of directories the daemon will load certificate files
from. Empty (default) = any path. Set in production to limit the
blast radius of a compromised peer:

<!-- @code-block language="text" label="restricted dirs" -->
```text
session_manager:
    allowed_cert_dirs:
        - /etc/opcua/certs
        - /var/lib/opcua/trust
```
<!-- @endcode-block -->

### `log_channel`

The Monolog channel **the daemon logs to** when launched via
`opcua:session`. `null` falls back to the `logger` service.

For a dedicated daemon log file, define the channel in
`config/packages/monolog.yaml`:

<!-- @code-block language="text" label="monolog.yaml" -->
```text
monolog:
    channels: ['opcua']
    handlers:
        opcua:
            type: rotating_file
            path: '%kernel.logs_dir%/opcua-%kernel.environment%.log'
            max_files: 14
            level:   info
            channels: ['opcua']
```
<!-- @endcode-block -->

…then point the bundle at it:

<!-- @code-block language="text" label="bundle config" -->
```text
session_manager:
    log_channel: opcua
```
<!-- @endcode-block -->

### `cache_pool`

Symfony cache pool the daemon's PSR-16 wrapper uses for
metadata caches. Default `cache.app`. For a multi-host
deployment with shared daemon state, point at a Redis pool:

<!-- @code-block language="text" label="config/packages/cache.yaml" -->
```text
framework:
    cache:
        pools:
            cache.opcua_daemon:
                adapter: cache.adapter.redis
                provider: 'redis://redis-host:6379'
                default_lifetime: 3600
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="bundle config" -->
```text
session_manager:
    cache_pool: cache.opcua_daemon
```
<!-- @endcode-block -->

### `auto_publish`

When `true`, the daemon publishes subscription notifications
back to PHP via PSR-14 — which flow through Symfony's
`EventDispatcher`. Lets you declare subscriptions in YAML
(`connections.<name>.subscriptions`) and react with
`#[AsEventListener]`.

<!-- @callout type="note" -->
**Only meaningful in managed mode.** Direct mode subscriptions
deliver via callbacks on the same PHP process.
<!-- @endcallout -->

See [Session manager · Auto-publish](../session-manager/auto-publish.md).

## When the artisan command reads this

`php bin/console opcua:session` is the **only** path where
Symfony-side wiring is fed into the daemon. The command:

1. Reads the `session_manager.*` block.
2. Resolves `log_channel` to a PSR-3 `LoggerInterface`.
3. Resolves `cache_pool` to a PSR-16 cache.
4. Resolves `auth_token` and bakes it into the daemon.
5. Boots `SessionManagerDaemon` with that wiring.

If you run the daemon **outside Symfony** (e.g., directly via
`vendor/bin/opcua-session-manager`), none of this wiring
applies — pass equivalents on the command line.

See [Session manager · Starting the daemon](../session-manager/starting-the-daemon.md).

## Per-environment override pattern

<!-- @code-block language="text" label="dev (.env)" -->
```text
OPCUA_SESSION_MANAGER_ENABLED=true
OPCUA_SOCKET_PATH=var/opcua-session-manager.sock
OPCUA_AUTH_TOKEN=
OPCUA_AUTO_PUBLISH=false
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="staging (.env.staging.local)" -->
```text
OPCUA_SESSION_MANAGER_ENABLED=true
OPCUA_SOCKET_PATH=/var/run/opcua/sessions.sock
OPCUA_AUTH_TOKEN=<from-vault-or-env-injection>
OPCUA_AUTO_PUBLISH=true
```
<!-- @endcode-block -->

…and the bundle config consumes them:

<!-- @code-block language="text" label="bundle config" -->
```text
session_manager:
    enabled:      '%env(bool:OPCUA_SESSION_MANAGER_ENABLED)%'
    socket_path:  '%env(OPCUA_SOCKET_PATH)%'
    auth_token:   '%env(secret:OPCUA_AUTH_TOKEN)%'
    auto_publish: '%env(bool:OPCUA_AUTO_PUBLISH)%'
```
<!-- @endcode-block -->

## Direct mode

If `enabled` is `false` (or the daemon is unreachable), the
bundle operates in direct mode. The `session_manager` block is
ignored on the **client** side, but the values (especially
`socket_path`) remain as documentation of where the daemon
*would* live.

## Where to read next

- [Parameters and overrides](./parameters-and-overrides.md) —
  per-environment patterns + DI overrides.
- [Session manager · Overview](../session-manager/overview.md) —
  what the daemon does and why.
- [Production supervisor](../session-manager/production-supervisor.md) —
  systemd / Supervisor / Docker unit files.
