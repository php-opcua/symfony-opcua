---
eyebrow: 'Docs · Configuration'
lede:    'Every OPCUA_* env var the bundle reads, the %env(...)% patterns to wire them in, and Symfony''s secrets vault for production credentials.'

see_also:
  - { href: './bundle-yaml.md',                    meta: '6 min' }
  - { href: './connections.md',                    meta: '7 min' }
  - { href: '../security/credentials.md',          meta: '5 min' }
  - { href: '../recipes/production-deployment.md', meta: '7 min' }

prev: { label: 'Connections',  href: './connections.md' }
next: { label: 'Security',     href: './security.md' }
---

# Environment variables

The bundle doesn't read any env var directly — every value flows
through `%env(...)%` placeholders in YAML. This page lists the
**conventional** env vars used in the docs and shows the YAML
plumbing.

## Conventional names

| Variable                              | Used in                                                    |
| ------------------------------------- | ---------------------------------------------------------- |
| `OPCUA_ENDPOINT`                       | `connections.default.endpoint`                              |
| `OPCUA_USERNAME` / `OPCUA_PASSWORD`    | `connections.default.username` / `password`                 |
| `OPCUA_CLIENT_CERT` / `OPCUA_CLIENT_KEY` | App cert paths                                          |
| `OPCUA_CA_CERT`                        | CA cert path                                                |
| `OPCUA_USER_CERT` / `OPCUA_USER_KEY`   | User-identity cert / key paths                              |
| `OPCUA_TRUST_STORE_PATH`               | Local PKI trust dir                                         |
| `OPCUA_AUTH_TOKEN`                     | Daemon IPC shared secret                                     |
| `OPCUA_SOCKET_PATH`                    | Daemon endpoint (unix://, tcp://, or path)                  |
| `OPCUA_LOG_LEVEL`                      | Monolog filter for the OPC UA channel                       |
| `OPCUA_AUTO_PUBLISH`                   | Daemon auto-publish flag                                     |
| `OPCUA_TIMEOUT`                        | Per-call timeout                                             |

For multi-connection apps, suffix per connection:

| Variable                            | Used in                                       |
| ----------------------------------- | --------------------------------------------- |
| `OPCUA_LINE_A_ENDPOINT`              | `connections.plc-line-a.endpoint`              |
| `OPCUA_LINE_A_USER` / `_PASS`        | Line A credentials                            |
| `OPCUA_LINE_B_ENDPOINT`              | `connections.plc-line-b.endpoint`              |

Naming is a convention — the bundle doesn't enforce it.

## Wiring in YAML

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        socket_path: '%env(OPCUA_SOCKET_PATH)%'
        auth_token:  '%env(secret:OPCUA_AUTH_TOKEN)%'
        auto_publish: '%env(bool:OPCUA_AUTO_PUBLISH)%'

    connections:
        default:
            endpoint:   '%env(OPCUA_ENDPOINT)%'
            username:   '%env(OPCUA_USERNAME)%'
            password:   '%env(secret:OPCUA_PASSWORD)%'
            timeout:    '%env(float:OPCUA_TIMEOUT)%'
```
<!-- @endcode-block -->

`bool:` and `float:` are Symfony env-var **processors** — they
coerce the string value to the right PHP type.

## A complete `.env` for development

<!-- @code-block language="bash" label=".env" -->
```bash
APP_ENV=dev
APP_DEBUG=true

# OPC UA
OPCUA_ENDPOINT=opc.tcp://127.0.0.1:4840
OPCUA_USERNAME=admin
OPCUA_PASSWORD=admin123
OPCUA_TIMEOUT=5.0

# Session manager (optional in dev)
OPCUA_SOCKET_PATH=var/opcua-session-manager.sock
OPCUA_AUTH_TOKEN=
OPCUA_AUTO_PUBLISH=false

# Logging
OPCUA_LOG_LEVEL=debug
```
<!-- @endcode-block -->

Anything secret stays empty in `.env` and is supplied via
`.env.local` or the secrets vault.

## A complete `.env.local` for staging

<!-- @code-block language="bash" label=".env.local (staging)" -->
```bash
APP_ENV=staging

OPCUA_ENDPOINT=opc.tcp://staging-plc.internal:4840
OPCUA_USERNAME=integrations-staging

# Don't put real secrets here — see the secrets vault section
OPCUA_PASSWORD=
OPCUA_AUTH_TOKEN=

OPCUA_TIMEOUT=10.0
OPCUA_AUTO_PUBLISH=true
```
<!-- @endcode-block -->

`.env.local` is `.gitignore`d. Per-environment overrides:
`.env.staging.local`, `.env.prod.local`.

## Symfony secrets vault for production

For production credentials, use the encrypted vault:

<!-- @code-block language="bash" label="terminal — set a secret" -->
```bash
php bin/console secrets:set OPCUA_PASSWORD
# Paste the password when prompted

php bin/console secrets:set OPCUA_AUTH_TOKEN
# Paste a 64-hex-char shared secret
```
<!-- @endcode-block -->

Values land in `config/secrets/<env>/`. The encryption key
lives at `config/secrets/<env>/<env>.encrypt.key` (encrypted)
and `<env>.decrypt.private.php` (the dangerous file — keep
out of git).

In YAML:

<!-- @code-block language="text" label="vault usage" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        auth_token:  '%env(secret:OPCUA_AUTH_TOKEN)%'

    connections:
        default:
            password: '%env(secret:OPCUA_PASSWORD)%'
```
<!-- @endcode-block -->

The `secret:` prefix tells Symfony to consult the vault first,
falling back to a regular env var if no secret is set.

See the [Symfony secrets documentation](https://symfony.com/doc/current/configuration/secrets.html)
for the full vault workflow.

## Per-environment files

Symfony loads `.env` files in this order:

```text
.env                  ← committed defaults
.env.local            ← uncommitted overrides
.env.<env>            ← committed per-env (.env.test, .env.prod)
.env.<env>.local      ← uncommitted per-env overrides
```

Best practice: only commit `.env` and `.env.test`. Everything
else stays out of git.

## Boolean and numeric processors

| Source                         | Processor               | Becomes        |
| ------------------------------ | ----------------------- | -------------- |
| `OPCUA_AUTO_PUBLISH=true`       | `%env(bool:OPCUA_*)%`    | `true`          |
| `OPCUA_AUTO_PUBLISH=1`          | `%env(bool:OPCUA_*)%`    | `true`          |
| `OPCUA_AUTO_PUBLISH=false`      | `%env(bool:OPCUA_*)%`    | `false`         |
| `OPCUA_TIMEOUT=5.5`             | `%env(float:OPCUA_*)%`   | `5.5` (float)   |
| `OPCUA_MAX_SESSIONS=100`        | `%env(int:OPCUA_*)%`     | `100` (int)     |
| `OPCUA_CERT_DIRS=/a,/b,/c`      | `%env(csv:OPCUA_*)%`     | `['/a','/b','/c']` |

Use the right processor — boolean coercion without `bool:`
silently turns `"false"` into the truthy string `"false"`.

## Caching the env vars

Symfony's `kernel.debug=false` mode caches the resolved
container, including all env vars resolved to their values at
boot. After editing `.env`:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console cache:clear
```
<!-- @endcode-block -->

In dev (`APP_DEBUG=true`), Symfony re-reads `.env` on every
request automatically.

## Secret hygiene checklist

- [ ] No real password committed in `.env`.
- [ ] `.env.local` and `.env.<env>.local` are in `.gitignore`.
- [ ] Production passwords come from `secret:` (vault) or from
      an external secrets manager via `EnvironmentFile=` in
      systemd.
- [ ] The OPC UA daemon's `auth_token` is set in production.
- [ ] Long-running workers (Messenger, the daemon) are
      restarted after changing secrets.

## Where to read next

- [Security](./security.md) — the security-specific env vars.
- [Recipes · Production deployment](../recipes/production-deployment.md) —
  systemd + secrets-loaded env.
