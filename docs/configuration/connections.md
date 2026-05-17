---
eyebrow: 'Docs · Configuration'
lede:    'Per-connection keys in depth — endpoint, security, identity, timeouts, trust, logging, subscriptions. Patterns for multi-line, multi-tenant, and ad-hoc connections.'

see_also:
  - { href: './bundle-yaml.md',                          meta: '6 min' }
  - { href: './environment-variables.md',                meta: '5 min' }
  - { href: '../using-the-client/named-connections.md',  meta: '5 min' }
  - { href: '../using-the-client/ad-hoc-connections.md', meta: '5 min' }

prev: { label: 'Bundle YAML',           href: './bundle-yaml.md' }
next: { label: 'Environment variables', href: './environment-variables.md' }
---

# Connections

Each connection is one entry under `connections.*`. The name
becomes the argument to `$opcua->connect('<name>')`.

## Anatomy

<!-- @code-block language="text" label="full connection example" -->
```text
php_opcua_symfony_opcua:
    connections:
        plc-line-a:
            endpoint:           'opc.tcp://plc-a.factory.local:4840'

            # Security
            security_policy:    Basic256Sha256
            security_mode:      SignAndEncrypt

            # User identity (one or the other)
            username:           '%env(OPCUA_LINE_A_USER)%'
            password:           '%env(OPCUA_LINE_A_PASS)%'
            # user_certificate: /etc/opcua/users/operator.pem
            # user_key:         /etc/opcua/users/operator.key

            # Application certificate (channel-layer identity)
            client_certificate: '%env(OPCUA_CLIENT_CERT)%'
            client_key:         '%env(OPCUA_CLIENT_KEY)%'
            ca_certificate:     '%env(OPCUA_CA_CERT)%'

            # Behaviour
            timeout:            8.0
            auto_retry:         3
            batch_size:         null         # null = server-advertised
            browse_max_depth:   8
            auto_detect_write_type: true
            read_metadata_cache:    true

            # Observability
            log_channel:        plc-line-a

            # Trust store (managed mode forwards via setTrustStorePath)
            trust_store_path:   '%kernel.project_dir%/var/opcua-trust'
            trust_policy:       fingerprint+expiry
            auto_accept:        false
            auto_accept_force:  false

            # Daemon-only — see Session manager · Auto-publish
            auto_connect:       false
            subscriptions:      []
```
<!-- @endcode-block -->

Most deployments only fill in `endpoint`, `security_policy` /
`security_mode`, the credentials block, and `log_channel`.

## Per-key reference

### `endpoint` (required)

The OPC UA server URL. Always `opc.tcp://host:port`. No trailing
slash. Use env vars so it changes per environment:

<!-- @code-block language="text" label="endpoint" -->
```text
endpoint: '%env(OPCUA_ENDPOINT)%'
```
<!-- @endcode-block -->

### `security_policy` / `security_mode`

See [Security · Policies and modes](../security/policies-and-modes.md).
Defaults are both `None` — fine for local dev, not for prod.

### `username` / `password`

User-identity flow at the session layer. Use the secrets vault
for passwords in production:

<!-- @code-block language="text" label="secrets" -->
```text
username: '%env(OPCUA_USERNAME)%'
password: '%env(secret:OPCUA_PASSWORD)%'
```
<!-- @endcode-block -->

### `user_certificate` / `user_key`

The X.509-based alternative to username/password. Either is fine
— if both are set, the user cert wins.

### `client_certificate` / `client_key` / `ca_certificate`

Application certificate for the secure-channel layer. Required
once `security_mode` is anything but `None`. See
[Security · Certificates](../security/certificates.md).

### `timeout`

Default per-call timeout in seconds. `5.0` is the default. Raise
for slow servers (8.0–15.0); keep low for fast LAN PLCs.

### `auto_retry`

Number of retries on transient `ConnectionException`. `null` (no
auto retry) by default. Setting `3` makes the client retry up to
3 times with backoff before bubbling the exception.

### `batch_size`

Override the server-advertised `MaxNodesPerRead` / `MaxNodesPerWrite`.
Default `null` (use whatever the server says). Lower it for
servers that lie or for testing client-side batching code.

### `browse_max_depth`

Default depth for `browseRecursive()`. `10` is generous; raise
for very deep address spaces.

### `log_channel`

Per-connection Monolog channel name. The bundle resolves
`monolog.logger.<name>` at compile time. See
[Observability · Logging](../observability/logging.md).

### Trust-store keys

| Key                  | Effect                                                    |
| -------------------- | --------------------------------------------------------- |
| `trust_store_path`    | Local PKI directory for pinned server certs                |
| `trust_policy`        | `fingerprint`, `fingerprint+expiry`, `full`               |
| `auto_accept`         | TOFU mode — accept unknown server certs on first contact   |
| `auto_accept_force`   | Re-accept previously rejected certs                        |

See [Security · Trust store](../security/trust-store.md).

### `auto_detect_write_type` / `read_metadata_cache`

Library-level performance flags. Defaults are sensible — change
only if you understand the implications. See
[Operations · Writing](../operations/writing.md) and
[Operations · Reading](../operations/reading.md).

### `auto_connect` / `subscriptions`

Daemon-side declarative subscriptions, only relevant when
`session_manager.auto_publish = true`. See
[Session manager · Auto-publish](../session-manager/auto-publish.md).

## Patterns

### One connection per production line

<!-- @code-block language="text" label="multi-line" -->
```text
php_opcua_symfony_opcua:
    connections:
        plc-line-a:
            endpoint: '%env(OPCUA_LINE_A_ENDPOINT)%'
        plc-line-b:
            endpoint: '%env(OPCUA_LINE_B_ENDPOINT)%'
        plc-line-c:
            endpoint: '%env(OPCUA_LINE_C_ENDPOINT)%'
```
<!-- @endcode-block -->

`.env`:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_LINE_A_ENDPOINT=opc.tcp://plc-a.factory.local:4840
OPCUA_LINE_B_ENDPOINT=opc.tcp://plc-b.factory.local:4840
OPCUA_LINE_C_ENDPOINT=opc.tcp://plc-c.factory.local:4840
```
<!-- @endcode-block -->

Switch at the call site:

<!-- @code-block language="php" label="usage" -->
```php
$dvA = $opcua->connect('plc-line-a')->read('ns=2;s=Speed');
$dvB = $opcua->connect('plc-line-b')->read('ns=2;s=Speed');
```
<!-- @endcode-block -->

### Historian + live separation

<!-- @code-block language="text" label="historian + live" -->
```text
php_opcua_symfony_opcua:
    default: live
    connections:
        live:
            endpoint:        '%env(OPCUA_LIVE_ENDPOINT)%'
            read_metadata_cache: false   # always fresh
            timeout:         5.0
        historian:
            endpoint:        '%env(OPCUA_HISTORIAN_ENDPOINT)%'
            read_metadata_cache: true    # metadata stable
            timeout:         15.0
            browse_max_depth: 12
```
<!-- @endcode-block -->

### Multi-tenant (per-tenant endpoint)

<!-- @code-block language="text" label="multi-tenant" -->
```text
php_opcua_symfony_opcua:
    connections:
        plc-tenant-acme:
            endpoint:           'opc.tcp://plc.acme.local:4840'
            username:           '%env(OPCUA_ACME_USER)%'
            password:           '%env(secret:OPCUA_ACME_PASS)%'
            trust_store_path:   '%kernel.project_dir%/var/trust/acme'
        plc-tenant-globex:
            endpoint:           'opc.tcp://plc.globex.local:4840'
            username:           '%env(OPCUA_GLOBEX_USER)%'
            password:           '%env(secret:OPCUA_GLOBEX_PASS)%'
            trust_store_path:   '%kernel.project_dir%/var/trust/globex'
```
<!-- @endcode-block -->

See [Recipes · Multi-plant tenant](../recipes/multi-plant-tenant.md)
for the controller / service patterns that pair with this.

## Naming conventions

| Style              | Example              | When                                           |
| ------------------ | -------------------- | ---------------------------------------------- |
| Role-based         | `plc-line-a`          | Stable infrastructure layout                    |
| Location-based     | `factory-paris-l1`    | Multi-site deployments                          |
| Tenant-based       | `plc-tenant-acme`     | Multi-tenant apps                                |
| Functional          | `historian`, `mes`    | Heterogeneous infrastructure                     |

Lowercase, kebab-case is the project's convention. Stay within
`[a-z0-9-]+` to play well with shell tools and log filtering.

## Default connection

`$opcua->connect()` (no arg) returns the `default` connection:

<!-- @code-block language="text" label="setting default" -->
```text
php_opcua_symfony_opcua:
    default: plc-line-a
    connections:
        plc-line-a:
            endpoint: '%env(OPCUA_LINE_A_ENDPOINT)%'
        plc-line-b:
            endpoint: '%env(OPCUA_LINE_B_ENDPOINT)%'
```
<!-- @endcode-block -->

If `default` is omitted, it falls back to the literal string
`'default'` — so a single-connection app with the connection
**named** `default` works zero-config.

## Inline overrides (ad-hoc)

For connections you can't declare in YAML (per-tenant, per-PLC
fleet), use `$opcua->connectTo($endpoint, $config)` — see
[Ad-hoc connections](../using-the-client/ad-hoc-connections.md).

## Where to read next

- [Environment variables](./environment-variables.md) — the env
  vars referenced above.
- [Named connections](../using-the-client/named-connections.md) —
  the call-site API.
- [Security](./security.md) — the security keys cross-referenced.
