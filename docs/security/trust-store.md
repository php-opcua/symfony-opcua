---
eyebrow: 'Docs · Security'
lede:    'Managing pinned OPC UA server certificates. The trust-store policy choice, file-system layout, and Symfony command to add/list/remove.'

see_also:
  - { href: './certificates.md',                meta: '7 min' }
  - { href: './policies-and-modes.md',          meta: '5 min' }
  - { href: '../configuration/security.md',     meta: '5 min' }

prev: { label: 'Certificates',  href: './certificates.md' }
next: { label: 'PHPUnit and Pest setup', href: '../testing/phpunit-and-pest-setup.md' }
---

# Trust store

The mirror image of `client_certificate`. The **trust store**
is a local directory of OPC UA server certificates your Symfony
app considers legitimate. The bundle consults it on every
secure-channel handshake.

## The directory

| OS         | Default                                         |
| ---------- | ----------------------------------------------- |
| POSIX      | `~/.opcua/trust/`                                |
| Windows    | `%APPDATA%\opcua\trust\`                         |
| Override   | `OPCUA_TRUST_STORE_PATH` env                     |

For production, override to a known location:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_TRUST_STORE_PATH=/var/lib/opcua/trust
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            trust_store_path: '%env(OPCUA_TRUST_STORE_PATH)%'
            trust_policy:     '%env(OPCUA_TRUST_POLICY)%'
            auto_accept:      '%env(bool:OPCUA_AUTO_ACCEPT)%'
```
<!-- @endcode-block -->

## Trust policies

Three policies, three trade-offs:

| Policy                 | Checked on connect                                       | Pro                                            | Con                                                     |
| ---------------------- | -------------------------------------------------------- | ---------------------------------------------- | ------------------------------------------------------- |
| `fingerprint`          | SHA-256 of cert is in trust store                         | Simplest. No CA chain                          | Rotation = manual repinning                              |
| `fingerprint+expiry`   | Fingerprint match + cert `NotAfter` is in the future      | Catches expiry-related issues                   | Same rotation cost                                       |
| `full`                 | Full X.509 chain validation against trust store as CAs    | CA-based rotation = no per-server changes      | Need a CA infrastructure                                  |

For 1-50 PLCs: `fingerprint+expiry`. For 50+ behind a CA: `full`.

## Adding a server cert

The bundle ships a Symfony command for trust-store management
(in v4.3+):

<!-- @code-block language="bash" label="opcua:trust:add" -->
```bash
php bin/console opcua:trust:add opc.tcp://plc.factory.local:4840
```
<!-- @endcode-block -->

The command:

1. Opens an unsecured discovery connection.
2. Fetches the server's cert.
3. Shows subject, SAN, fingerprint, expiry.
4. Asks for confirmation.
5. Writes the cert to the trust-store dir.

Output:

<!-- @code-block language="text" label="output" -->
```text
Fetched cert from opc.tcp://plc.factory.local:4840
  Subject:      CN=OpenUaServer, O=Foo
  SAN:          URI:urn:foo:open-ua-server
  Fingerprint:  SHA256:a1:b2:c3:...
  Valid until:  2027-05-15

Pin this cert? [yes/no]: yes

Wrote /var/lib/opcua/trust/a1b2c3...-OpenUaServer.pem
```
<!-- @endcode-block -->

## Listing pinned certs

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console opcua:trust:list
```
<!-- @endcode-block -->

`--json` for machine-readable output.

## Removing a cert

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console opcua:trust:remove a1b2c3...
# or
php bin/console opcua:trust:remove --subject="OpenUaServer"
```
<!-- @endcode-block -->

## TOFU mode (`auto_accept`)

For development:

<!-- @code-block language="bash" label=".env (dev)" -->
```bash
OPCUA_AUTO_ACCEPT=true
```
<!-- @endcode-block -->

First connection: cert auto-pinned, connection succeeds, bundle
logs `notice` "Auto-accepted server cert fingerprint=...".
Subsequent connections validate against the pinned cert.

<!-- @callout type="warning" -->
**`auto_accept: true` in production is equivalent to disabling
server-cert validation.** Anyone who MitMs the first connection
establishes a permanent trust. Use only in dev, or behind an
admin gate.
<!-- @endcallout -->

For a production-safe variant, one-time bootstrap command:

<!-- @code-block language="php" label="src/Command/TrustBootstrapCommand.php" -->
```php
#[AsCommand(name: 'app:opcua:trust:bootstrap')]
final class TrustBootstrapCommand extends Command
{
    public function __construct(
        private OpcuaManager $opcua,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Operator runs this once per server
        $this->opcua->connect()->read('i=2256');   // forces a connection + cert pin

        $output->writeln('Cert pinned. Verify with: php bin/console opcua:trust:list');
        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Wrap with auth so only an admin can run it.

## Cert rotation procedure

The server's cert expires; service stays up:

1. **Server-side**: generate new cert. Install it. Most servers
   support multiple valid certs.
2. **Symfony-side**: `php bin/console opcua:trust:add <endpoint>`.
3. **Switch over** server-side: server starts presenting the
   new cert. Both are trusted.
4. **Symfony-side**: `php bin/console opcua:trust:remove <old-fingerprint>`.
5. Confirm: `opcua:trust:list`.

## CI / deployment

<!-- @code-block language="text" label="deploy step" -->
```text
- name: Pin OPC UA server certs
  run: |
    for endpoint in $(cat ./opcua-endpoints.txt); do
      php bin/console opcua:trust:add --force "$endpoint"
    done
  env:
    OPCUA_TRUST_STORE_PATH: /var/lib/opcua/trust
```
<!-- @endcode-block -->

`--force` skips the confirmation. Use only when input is
known-good (in repo, not user-provided).

## Multi-tenant trust stores

Per-tenant isolation:

<!-- @code-block language="text" label="per-tenant trust" -->
```text
php_opcua_symfony_opcua:
    connections:
        plc-tenant-acme:
            trust_store_path: '/var/lib/opcua/trust/acme'
        plc-tenant-globex:
            trust_store_path: '/var/lib/opcua/trust/globex'
```
<!-- @endcode-block -->

A breach of one tenant's trust store doesn't compromise others.

## Permissions

<!-- @code-block language="bash" label="terminal" -->
```bash
sudo mkdir -p /var/lib/opcua/trust
sudo chown www-data:www-data /var/lib/opcua/trust
sudo chmod 0750 /var/lib/opcua/trust
```
<!-- @endcode-block -->

Files inside are mode `0640` — readable by user and group.

## What's in a trust-store file

Each pinned cert is a PEM file:

<!-- @code-block language="text" label="layout" -->
```text
/var/lib/opcua/trust/
├── a1b2c3...-OpenUaServer.pem
├── d4e5f6...-KEPServerEX.pem
└── revoked/
    └── (old certs for audit)
```
<!-- @endcode-block -->

The filename embeds the fingerprint; the bundle matches on
fingerprint, not filename.

## Cache implications

The bundle caches trust-store hashes (5-min TTL). The bundle's
trust-store commands invalidate this cache. If you bypass the
command (drop files in manually), either wait for TTL or flush
the cache pool.

## Where to read next

You've finished **Security**. Next:
[Testing · PHPUnit and Pest setup](../testing/phpunit-and-pest-setup.md).
