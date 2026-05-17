---
eyebrow: 'Docs · Security'
lede:    'Username/password vs user-cert identity, the Symfony secrets vault, and rotation patterns.'

see_also:
  - { href: './policies-and-modes.md',                meta: '5 min' }
  - { href: './certificates.md',                      meta: '7 min' }
  - { href: '../configuration/environment-variables.md', meta: '5 min' }

prev: { label: 'Policies and modes',  href: './policies-and-modes.md' }
next: { label: 'Certificates',        href: './certificates.md' }
---

# Credentials

OPC UA's session layer carries a **UserIdentityToken**. Three
shapes:

1. **Anonymous** — no identity.
2. **Username/password** — like a DB login.
3. **X.509 certificate** — like a user-level mTLS cert.

This is **distinct from** the application certificate (channel
layer). Most deployments use both: an app cert at the channel
layer, a user identity at the session layer.

## Configuring

<!-- @code-block language="text" label="username/password" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            username: '%env(OPCUA_USERNAME)%'
            password: '%env(secret:OPCUA_PASSWORD)%'
```
<!-- @endcode-block -->

…or:

<!-- @code-block language="text" label="user X.509" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            user_certificate: '%env(OPCUA_USER_CERT)%'
            user_key:         '%env(OPCUA_USER_KEY)%'
```
<!-- @endcode-block -->

…or nothing (anonymous):

<!-- @code-block language="text" label="anonymous" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            # No username, no user_certificate set
```
<!-- @endcode-block -->

If both username and user-cert are present, user-cert wins.
Most servers reject ambiguous identity.

## Where credentials should live

**Never** in code or version control:

| Location                          | Production-ready? | Why                                       |
| --------------------------------- | ----------------- | ----------------------------------------- |
| `.env` (committed)                 | **No**            | Visible to anyone with repo access         |
| `.env.local` (gitignored)          | OK in dev         | On-disk, readable by other processes      |
| Symfony secrets vault              | **Yes**           | Encrypted at rest                          |
| External secrets manager → env     | **Best**          | No on-disk plaintext                       |

### Symfony secrets vault

<!-- @code-block language="bash" label="terminal" -->
```bash
# One-time per env:
php bin/console secrets:generate-keys

# Set a secret:
php bin/console secrets:set OPCUA_PASSWORD
php bin/console secrets:set OPCUA_AUTH_TOKEN
```
<!-- @endcode-block -->

Values land in `config/secrets/<env>/`. The encryption key
(`<env>.encrypt.key`) is committable; the decryption key
(`<env>.decrypt.private.php`) is **not** — keep out of git.

In YAML:

<!-- @code-block language="text" label="vault usage" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            username: '%env(OPCUA_USERNAME)%'
            password: '%env(secret:OPCUA_PASSWORD)%'
```
<!-- @endcode-block -->

The `secret:` processor consults the vault first.

See the [Symfony secrets documentation](https://symfony.com/doc/current/configuration/secrets.html).

### External secrets manager → env

For Vault, AWS Secrets Manager, Doppler, etc., load secrets
into the **process environment** at boot. The bundle's
`%env(...)` placeholders consume them transparently.

<!-- @code-block language="bash" label="loaded by deploy script" -->
```bash
export OPCUA_PASSWORD="$(vault kv get -field=password secret/plc)"
export OPCUA_AUTH_TOKEN="$(vault kv get -field=token secret/opcua-daemon)"

exec php bin/console opcua:session
```
<!-- @endcode-block -->

The bundle doesn't read Vault directly — Symfony's env-var
plumbing does.

## What the bundle logs

The bundle **never** writes credentials to logs.

| Surface                              | Credentials redacted?      |
| ------------------------------------ | -------------------------- |
| Connection-opened log line            | Yes                        |
| Exception messages                    | Yes (sanitised)            |
| Daemon `list` IPC response             | Yes (since v4.3.0)         |
| `bin/console debug:config php_opcua_*` | No — visible to debug user |

In `debug:config`, you see the secret resolved to its actual
value. That's by design — debug is for the developer, not for
logs.

## URL credential sanitisation

The bundle's exception sanitiser rewrites three patterns:

| Input                                       | After sanitisation                          |
| ------------------------------------------- | ------------------------------------------- |
| `opc.tcp://user:pass@host:4840`              | `opc.tcp://[redacted]:[redacted]@host:4840`  |
| `/etc/opcua/client.key` (in messages)         | `[path]`                                     |
| `C:\Users\me\client.pem`                       | `[path]`                                     |

This runs in exception messages, not your own logs. If a
listener does `$logger->error($e->getMessage())`, the message is
already sanitised.

## Per-environment credentials

<!-- @code-block language="bash" label=".env" -->
```bash
# Committed defaults
APP_ENV=dev
OPCUA_ENDPOINT=opc.tcp://localhost:4840
# Anonymous in local dev
```
<!-- @endcode-block -->

<!-- @code-block language="bash" label=".env.staging.local" -->
```bash
APP_ENV=staging
OPCUA_ENDPOINT=opc.tcp://staging-plc.internal:4840
OPCUA_USERNAME=integrations-staging
# Loaded from external secrets manager at deploy time
OPCUA_PASSWORD=
```
<!-- @endcode-block -->

<!-- @code-block language="bash" label=".env.prod.local (or secrets vault)" -->
```bash
APP_ENV=prod
OPCUA_ENDPOINT=opc.tcp://prod-plc.internal:4840
OPCUA_USERNAME=integrations-prod
# Resolved by Symfony's vault via secret: prefix
OPCUA_PASSWORD=
```
<!-- @endcode-block -->

## Rotation procedure

Quarterly rotation of OPC UA passwords:

1. **Generate the new password** in your secrets manager.
2. **Update the OPC UA server's user database** (server-side).
3. **Update the secrets manager / vault** with the new value.
4. **Trigger a deploy** that picks up the new env var.
5. **Restart the daemon** — `systemctl restart opcua-session-manager`.

Steps 2 and 3 must be close together — anything in between
fails to connect.

For **zero-downtime rotation**:

1. Add the **new** user to the server (keep the old).
2. Update vault → new value.
3. Deploy and restart.
4. Verify new identity works.
5. Remove the old user from the server.

## User-cert identity

Same X.509 format as application certs (see
[Certificates](./certificates.md)) but tied to a **user**
server-side.

When to use over username/password:

| Scenario                              | Pick                  |
| ------------------------------------- | --------------------- |
| Many users, simple management         | Username/password     |
| Audit per-application identity        | User-cert             |
| Hardware-bound identity (HSM, TPM)    | User-cert             |
| Per-machine identity in a fleet       | User-cert             |
| Per-Symfony-deployment audit trail    | User-cert             |

## Per-tenant identities

Multi-tenant deployment with one user-cert per tenant:

<!-- @code-block language="text" label="per-tenant" -->
```text
php_opcua_symfony_opcua:
    connections:
        plc-tenant-acme:
            endpoint:         'opc.tcp://plc.acme.local:4840'
            user_certificate: '/etc/opcua/users/acme.pem'
            user_key:         '/etc/opcua/users/acme.key'
        plc-tenant-globex:
            endpoint:         'opc.tcp://plc.globex.local:4840'
            user_certificate: '/etc/opcua/users/globex.pem'
            user_key:         '/etc/opcua/users/globex.key'
```
<!-- @endcode-block -->

Server-side audit logs distinguish each tenant.

## Anonymous

Anonymous is for **read-only, public-data** endpoints. Rare in
production — most plants have a user layer.

## CI check — accidentally committed secrets

Add a CI step that flags `.env` containing credentials:

<!-- @code-block language="text" label=".github/workflows/secret-check.yml" -->
```text
- name: Check for hardcoded OPC UA password
  run: |
    if grep -r 'OPCUA_PASSWORD=' --include='.env*' . | grep -v '^$' | grep -v '^[^:]*:OPCUA_PASSWORD=$'; then
      echo "Hardcoded password detected"
      exit 1
    fi
```
<!-- @endcode-block -->

Or use a proper secret scanner (gitleaks, trufflehog).

## When credentials fail

| Error                                       | Cause                                           |
| ------------------------------------------- | ----------------------------------------------- |
| `AuthenticationException — wrong password`   | Password typo or rotation drift                  |
| `AuthenticationException — user unknown`     | Username doesn't exist on the server             |
| `AuthenticationException — locked out`       | Too many failed attempts; server lockout         |
| `Bad_IdentityTokenRejected`                   | User-cert not in the server's user trust list   |
| `Bad_IdentityTokenInvalid`                    | Cert expired or malformed                        |

See [Debugging](../observability/debugging.md).

## Where to read next

- [Certificates](./certificates.md) — the application-cert side.
- [Trust store](./trust-store.md) — server-side cert side.
