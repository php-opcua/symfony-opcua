---
eyebrow: 'Docs · Configuration'
lede:    'The complete config/packages/php_opcua_symfony_opcua.yaml tree — every key, every default, every accepted value, with the corresponding env-var pattern.'

see_also:
  - { href: './connections.md',                        meta: '7 min' }
  - { href: './environment-variables.md',              meta: '5 min' }
  - { href: './session-manager.md',                    meta: '6 min' }

prev: { label: 'Upgrading',     href: '../getting-started/upgrading.md' }
next: { label: 'Connections',   href: './connections.md' }
---

# Bundle YAML

The full config tree lives under the `php_opcua_symfony_opcua`
root in `config/packages/php_opcua_symfony_opcua.yaml`. Symfony's
`TreeBuilder` validates it — typos and missing required keys
fail at compile time, not at runtime.

## Three sections

| Section             | Purpose                                                |
| ------------------- | ------------------------------------------------------ |
| `default`            | Connection name returned by `$opcua->connect()` with no arg |
| `session_manager`    | Daemon endpoint, lifecycle, security                    |
| `connections`        | Named OPC UA endpoints — autowireable by name          |

## The full reference

<!-- @code-block language="text" label="config/packages/php_opcua_symfony_opcua.yaml" -->
```text
php_opcua_symfony_opcua:
    # Which connection $opcua->connect() returns by default.
    default: default

    # ────────────────────────────────────────────────────────
    # Session manager (daemon) settings — read by:
    #   • OpcuaManager (to decide direct vs managed mode)
    #   • SessionCommand (when running `php bin/console opcua:session`)
    # ────────────────────────────────────────────────────────
    session_manager:
        # Master switch. false → always direct mode.
        enabled: true

        # IPC endpoint. Three forms:
        #   unix://<absolute/path.sock>
        #   tcp://127.0.0.1:<port>
        #   <plain path>  (treated as unix://)
        socket_path: '%kernel.project_dir%/var/opcua-session-manager.sock'

        # Idle session timeout (seconds) on the daemon side.
        timeout: 600

        # Reaper loop interval (seconds).
        cleanup_interval: 30

        # Shared-secret token. Required in production; null in dev.
        auth_token: null

        # Concurrent OPC UA sessions cap. Per-daemon.
        max_sessions: 100

        # Unix socket file mode (ignored on tcp:// endpoints).
        socket_mode: 0600

        # Restrict certificate paths the daemon will load.
        # Empty = any path.
        allowed_cert_dirs: []

        # Daemon-side Monolog channel. null → 'logger' service.
        log_channel: null

        # Symfony cache pool used by the daemon.
        cache_pool: 'cache.app'

        # Daemon auto-publishes subscriptions to PSR-14 events
        # (which flow through Symfony's EventDispatcher).
        auto_publish: false

    # ────────────────────────────────────────────────────────
    # Named connections. At least one entry is required.
    # ────────────────────────────────────────────────────────
    connections:
        default:
            endpoint:           '%env(OPCUA_ENDPOINT)%'    # required
            security_policy:    None                       # see security.md
            security_mode:      None                       # None | Sign | SignAndEncrypt
            username:           null
            password:           null
            client_certificate: null
            client_key:         null
            ca_certificate:     null
            user_certificate:   null
            user_key:           null
            timeout:            5.0
            auto_retry:         null
            batch_size:         null
            browse_max_depth:   10
            log_channel:        null                       # per-connection Monolog channel
            trust_store_path:   null
            trust_policy:       null                       # fingerprint | fingerprint+expiry | full
            auto_accept:        false                      # TOFU mode
            auto_accept_force:  false
            auto_detect_write_type: true
            read_metadata_cache: false
            auto_connect:       false                      # daemon-side, auto-publish only
            subscriptions:      []                          # declarative subscriptions
```
<!-- @endcode-block -->

Everything that has a default can be omitted. Only `endpoint`
is required on a connection.

## The five most-edited keys

| Key                                              | Why edit it                                       |
| ------------------------------------------------ | ------------------------------------------------- |
| `connections.<name>.endpoint`                     | Point at your OPC UA server                       |
| `connections.<name>.security_policy` / `_mode`    | Switch from `None` to `Basic256Sha256 + SignAndEncrypt` for prod |
| `connections.<name>.username` / `password`        | Username/password identity                         |
| `session_manager.socket_path`                     | Match your daemon's socket location                |
| `session_manager.auth_token`                      | Required in production                             |

Everything else is reasonable to keep at its default for most
deployments.

## Env-var pattern

The bundle uses Symfony's `%env(...)%` placeholders extensively.
Two reasons:

1. **Secrets stay in `.env` / vault**, not in YAML.
2. **Per-environment overrides** via `.env.local`, `.env.test`,
   `.env.production.local`.

For example:

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
            username: '%env(OPCUA_USERNAME)%'
            password: '%env(secret:OPCUA_PASSWORD)%'
```
<!-- @endcode-block -->

The `secret:` prefix pulls the value from Symfony's secrets
vault (`bin/console secrets:*`), not from `.env`. See
[Environment variables](./environment-variables.md).

## Booleans and ints in YAML

Symfony's YAML parser is strict:

| Value      | Result                              |
| ---------- | ----------------------------------- |
| `true`     | `bool(true)`                        |
| `'true'`   | `string('true')` — silently wrong   |
| `0600`     | `int(384)` — octal!                  |
| `'0600'`   | `string('0600')`                    |

`socket_mode: 0600` is **octal** (file permission `rw-------`).
Wrap in quotes only if you intend a string.

## Three runtime checks

<!-- @code-block language="bash" label="terminal" -->
```bash
# Validate the YAML against the tree
php bin/console debug:config php_opcua_symfony_opcua

# See the autowired services
php bin/console debug:autowiring opcua

# Lint the YAML file specifically
php bin/console lint:yaml config/packages/php_opcua_symfony_opcua.yaml
```
<!-- @endcode-block -->

Run all three after every config edit until they're muscle
memory.

## Where the schema lives

The full schema is in
`PhpOpcuaSymfonyOpcuaBundle::configure(DefinitionConfigurator)`.
Reading it is the most authoritative answer when you doubt
whether a key is supported.

## Where to read next

- [Connections](./connections.md) — the per-connection key
  reference in depth.
- [Environment variables](./environment-variables.md) — how
  `OPCUA_*` env keys plug into the config tree.
- [Security](./security.md) — the security-related YAML keys
  cross-referenced with their network behaviour.
