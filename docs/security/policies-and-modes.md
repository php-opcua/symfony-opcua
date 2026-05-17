---
eyebrow: 'Docs · Security'
lede:    'OPC UA security policy and mode — what each combination does, which to pick, and how Symfony apps configure them via YAML + secrets vault.'

see_also:
  - { href: './credentials.md',                       meta: '5 min' }
  - { href: './certificates.md',                      meta: '7 min' }
  - { href: '../configuration/security.md',           meta: '5 min' }

prev: { label: 'Profiler and data collectors',  href: '../observability/profiler-and-data-collectors.md' }
next: { label: 'Credentials',                  href: './credentials.md' }
---

# Policies and modes

OPC UA security has two orthogonal axes:

- **Policy** — the algorithm suite. *What crypto?*
- **Mode** — what those algorithms protect. *Sign, encrypt, both, neither?*

A connection picks one of each.

## Modes

| Mode               | Protection                                                       |
| ------------------ | ---------------------------------------------------------------- |
| `None`             | No signing, no encryption. Cleartext.                            |
| `Sign`             | Every message signed. Integrity + authenticity.                   |
| `SignAndEncrypt`   | Signed + encrypted. Integrity + authenticity + confidentiality.   |

`None` is for dev against an unsecured test server. Production
uses `Sign` (trusted network, integrity matters) or
`SignAndEncrypt` (default for prod).

## Policies

| Policy                       | Status                | Algorithms                              |
| ---------------------------- | --------------------- | --------------------------------------- |
| `None`                       | Required by spec      | No crypto                                |
| `Basic128Rsa15`              | **Deprecated**        | SHA-1, RSA-PKCS1                         |
| `Basic256`                   | **Deprecated**        | SHA-1                                    |
| `Basic256Sha256`             | Stable, widely used    | SHA-256, RSA-PKCS1, AES-256              |
| `Aes128_Sha256_RsaOaep`      | Stable                | SHA-256, RSA-OAEP, AES-128               |
| `Aes256_Sha256_RsaPss`       | Stable                | SHA-256, RSA-PSS, AES-256                |
| `ECC_nistP256`               | New (UA ≥ 1.05)        | ECDSA P-256, ECDH                         |
| `ECC_nistP384`               | New                   | ECDSA P-384, ECDH                         |
| `ECC_brainpoolP256r1`        | New (EU)              | Brainpool P-256                          |
| `ECC_brainpoolP384r1`        | New (EU)              | Brainpool P-384                          |

For production today: **`Basic256Sha256` + `SignAndEncrypt`**.
ECC is the future — adopt when your servers all support it.

## In bundle YAML

<!-- @code-block language="text" label="secured connection" -->
```text
php_opcua_symfony_opcua:
    connections:
        plc:
            endpoint:        '%env(OPCUA_ENDPOINT)%'
            security_policy: Basic256Sha256
            security_mode:   SignAndEncrypt
            client_certificate: '%env(OPCUA_CLIENT_CERT)%'
            client_key:         '%env(OPCUA_CLIENT_KEY)%'
```
<!-- @endcode-block -->

Anything beyond `None` requires `client_certificate` and
`client_key`. The bundle raises `CertificateException` on
connect otherwise.

## How the server negotiates

The OPC UA discovery phase reports `EndpointDescriptions` — one
per `(policy, mode)` the server offers. The bundle picks one
matching your config that the server advertises.

Mismatch → `PolicyException` on connect.

Discover what a server offers:

<!-- @code-block language="bash" label="opcua-cli" -->
```bash
# Sibling package
vendor/bin/opcua-cli discover opc.tcp://plc.factory.local:4840
```
<!-- @endcode-block -->

Or any OPC UA inspector tool (UaExpert, etc.).

## Recommended combinations

| Scenario                                       | Policy                  | Mode             |
| ---------------------------------------------- | ----------------------- | ---------------- |
| Local dev against test server                  | `None`                  | `None`           |
| Trusted plant LAN                              | `Basic256Sha256`        | `SignAndEncrypt` |
| Public network / cross-DC                       | `Aes256_Sha256_RsaPss`  | `SignAndEncrypt` |
| Modern ECC deployment                          | `ECC_nistP256`          | `SignAndEncrypt` |
| Legacy server, old policies only                | `Basic128Rsa15` (warn)  | `Sign`           |

For the legacy-server case, document the exception — those
policies have known weaknesses.

## Sign vs SignAndEncrypt

- **`Sign`** — when network is private and trusted. Saves CPU.
  Server-side wire is plaintext (useful for debugging).
- **`SignAndEncrypt`** — when the network might be observed.
  ~5-15% CPU overhead.

For a Symfony app on the plant LAN with no untrusted peers,
`Sign` is defensible. For everything else, encrypt.

## Per-connection override

Each connection has its own policy/mode. A common mix:

<!-- @code-block language="text" label="mixed policy" -->
```text
php_opcua_symfony_opcua:
    connections:
        plc-line-a:
            endpoint:        '%env(OPCUA_LINE_A)%'
            security_policy: Basic256Sha256
            security_mode:   SignAndEncrypt
        historian:
            # Trusted subnet, high-volume
            endpoint:        '%env(OPCUA_HISTORIAN)%'
            security_policy: None
            security_mode:   None
```
<!-- @endcode-block -->

## ECC client certificates

ECC policies need an **ECC cert** (not RSA). Generate:

<!-- @code-block language="bash" label="ECC cert" -->
```bash
openssl ecparam -name prime256v1 -genkey -noout -out client.key
openssl req -new -x509 -key client.key -out client.pem -days 365 \
    -subj "/CN=Symfony OPC UA Client/O=Acme"
```
<!-- @endcode-block -->

Most servers don't yet support ECC (UA spec 1.05 is new). Check
your server's `Server.ServerCapabilities.SupportedPrivateKeyFormats`
before committing.

## Default policy per environment

<!-- @code-block language="text" label=".env" -->
```text
# dev (.env)
OPCUA_SECURITY_POLICY=None
OPCUA_SECURITY_MODE=None

# prod (.env.prod.local or secrets)
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
```
<!-- @endcode-block -->

Per-environment overrides via `config/packages/<env>/`. See
[Configuration · Parameters and overrides](../configuration/parameters-and-overrides.md).

## Where to read next

- [Credentials](./credentials.md) — username + password +
  user-cert identity.
- [Certificates](./certificates.md) — generating, signing,
  rotating client certs.
- [Trust store](./trust-store.md) — managing pinned server certs.
