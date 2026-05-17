---
eyebrow: 'Docs · Configuration'
lede:    'Security-relevant YAML keys grouped by purpose — policy, mode, app identity, user identity, trust store. The configuration tour; the deep dives are under Security.'

see_also:
  - { href: '../security/policies-and-modes.md',  meta: '6 min' }
  - { href: '../security/credentials.md',         meta: '5 min' }
  - { href: '../security/certificates.md',        meta: '7 min' }
  - { href: '../security/trust-store.md',         meta: '6 min' }

prev: { label: 'Environment variables', href: './environment-variables.md' }
next: { label: 'Session manager',       href: './session-manager.md' }
---

# Security configuration

The security-relevant keys in
`config/packages/php_opcua_symfony_opcua.yaml`, grouped by
purpose. Each one points at its dedicated page under
[Security](../security/policies-and-modes.md) for the **why** —
this page is the **where**.

## Policy and mode

<!-- @code-block language="text" label="security & mode" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            security_policy: '%env(OPCUA_SECURITY_POLICY)%'
            security_mode:   '%env(OPCUA_SECURITY_MODE)%'
```
<!-- @endcode-block -->

| Key               | Values                                                  |
| ----------------- | ------------------------------------------------------- |
| `security_policy`  | `None`, `Basic128Rsa15`, `Basic256`, `Basic256Sha256`, `Aes128Sha256RsaOaep`, `Aes256Sha256RsaPss`, `ECC_nistP256`, `ECC_nistP384`, `ECC_brainpoolP256r1`, `ECC_brainpoolP384r1` |
| `security_mode`    | `None`, `Sign`, `SignAndEncrypt`                         |

Defaults: both `None`. Anything beyond `None` requires
`client_certificate` and `client_key`.

See [Security · Policies and modes](../security/policies-and-modes.md).

## Application identity

<!-- @code-block language="text" label="client cert" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            client_certificate: '%env(OPCUA_CLIENT_CERT)%'
            client_key:         '%env(OPCUA_CLIENT_KEY)%'
            ca_certificate:     '%env(OPCUA_CA_CERT)%'
```
<!-- @endcode-block -->

The client cert identifies the **Symfony application** to the
OPC UA server at the secure-channel layer. Most servers require
the cert's fingerprint or DN to be in their trust store.

See [Security · Certificates](../security/certificates.md).

## User identity

OPC UA's session layer carries a **user identity** independent
of the channel cert. Two options:

### Username + password

<!-- @code-block language="text" label="username" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            username: '%env(OPCUA_USERNAME)%'
            password: '%env(secret:OPCUA_PASSWORD)%'
```
<!-- @endcode-block -->

### X.509 user certificate

<!-- @code-block language="text" label="user cert" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            user_certificate: '%env(OPCUA_USER_CERT)%'
            user_key:         '%env(OPCUA_USER_KEY)%'
```
<!-- @endcode-block -->

If both are present, the user cert wins. If neither, the session
is anonymous.

See [Security · Credentials](../security/credentials.md).

## Trust store

<!-- @code-block language="text" label="trust store" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            trust_store_path:    '%env(OPCUA_TRUST_STORE_PATH)%'
            trust_policy:        '%env(OPCUA_TRUST_POLICY)%'
            auto_accept:         '%env(bool:OPCUA_AUTO_ACCEPT)%'
            auto_accept_force:   false
```
<!-- @endcode-block -->

| Key                | Values                                                       |
| ------------------ | ------------------------------------------------------------ |
| `trust_store_path`  | Filesystem path to the PKI store                              |
| `trust_policy`      | `fingerprint`, `fingerprint+expiry`, `full`                  |
| `auto_accept`       | `true`/`false` — TOFU mode                                    |
| `auto_accept_force` | Re-accept previously rejected certs                          |

In production, set `auto_accept` to `false` and pin server
certs explicitly. See [Security · Trust store](../security/trust-store.md).

## Daemon-side security

The daemon has its own auth-token gate. Set this in production:

<!-- @code-block language="text" label="auth token" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        auth_token: '%env(secret:OPCUA_AUTH_TOKEN)%'
```
<!-- @endcode-block -->

Generate a value with:

<!-- @code-block language="bash" label="terminal" -->
```bash
php -r 'echo bin2hex(random_bytes(32));'
# Then:
php bin/console secrets:set OPCUA_AUTH_TOKEN
```
<!-- @endcode-block -->

See [Session manager · Production supervisor](../session-manager/production-supervisor.md).

## Allowed certificate directories

The daemon can restrict where it'll load certs from:

<!-- @code-block language="text" label="allowed cert dirs" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        allowed_cert_dirs:
            - /etc/opcua/certs
            - '%kernel.project_dir%/var/opcua-pki'
```
<!-- @endcode-block -->

Prevents an IPC peer from asking the daemon to read arbitrary
filesystem paths via the `open` command.

## Complete secured connection

<!-- @code-block language="text" label="prod-shape" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        enabled:           true
        auth_token:        '%env(secret:OPCUA_AUTH_TOKEN)%'
        allowed_cert_dirs: ['/etc/opcua/certs']

    connections:
        plc:
            endpoint:           '%env(OPCUA_ENDPOINT)%'
            timeout:            10.0
            security_policy:    Basic256Sha256
            security_mode:      SignAndEncrypt
            client_certificate: '%env(OPCUA_CLIENT_CERT)%'
            client_key:         '%env(OPCUA_CLIENT_KEY)%'
            ca_certificate:     '%env(OPCUA_CA_CERT)%'
            username:           '%env(OPCUA_USERNAME)%'
            password:           '%env(secret:OPCUA_PASSWORD)%'
            trust_store_path:   '%kernel.project_dir%/var/opcua-trust'
            trust_policy:       fingerprint+expiry
            auto_accept:        false
```
<!-- @endcode-block -->

<!-- @callout type="warning" -->
**`auto_accept: true` is for dev.** It pins every server cert
on first contact. In production this disables server-cert
validation the first time you connect.
<!-- @endcallout -->

## What lives where

| Concern                          | This file (config)                    | Deep dive                                       |
| -------------------------------- | ------------------------------------- | ----------------------------------------------- |
| Choosing a policy / mode         | `security_policy`, `security_mode`     | [Policies and modes](../security/policies-and-modes.md) |
| Generating the client cert        | (path only)                           | [Certificates](../security/certificates.md)      |
| Pinning server certs              | (path only)                           | [Trust store](../security/trust-store.md)        |
| Username / user-cert identity      | `username`/`password`/`user_*`        | [Credentials](../security/credentials.md)         |

## Where to read next

- [Session manager](./session-manager.md) — the daemon-side
  config.
- [Security · Policies and modes](../security/policies-and-modes.md) —
  pick the right combination.
- [Recipes · Production deployment](../recipes/production-deployment.md) —
  putting it together.
