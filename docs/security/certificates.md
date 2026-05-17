---
eyebrow: 'Docs · Security'
lede:    'Generating, signing, rotating the client (application) certificate. The mTLS-style identity the OPC UA server uses to recognise your Symfony app.'

see_also:
  - { href: './policies-and-modes.md',         meta: '5 min' }
  - { href: './trust-store.md',                meta: '6 min' }
  - { href: '../configuration/security.md',    meta: '5 min' }

prev: { label: 'Credentials',  href: './credentials.md' }
next: { label: 'Trust store',  href: './trust-store.md' }
---

# Certificates

The **client (application) certificate** identifies your Symfony
app to the OPC UA server. Every connection beyond
`security_mode: None` requires one.

It's an X.509 cert with a private key — the same format as TLS
server certs. Conventions differ:

- The subject `CN` is your application name, not a hostname.
- The cert needs an `ApplicationUri` extension matching the URI
  the server expects.
- The server-side trust list is per-application, not per-CA.

## Generating a cert

The simplest case — a self-signed cert:

<!-- @code-block language="bash" label="self-signed RSA" -->
```bash
mkdir -p /etc/opcua

openssl req -x509 -newkey rsa:2048 -keyout /etc/opcua/client.key \
    -out /etc/opcua/client.pem -days 365 -nodes \
    -subj "/CN=My Symfony Client/O=Acme" \
    -addext "subjectAltName=URI:urn:my-symfony:client,DNS:symfony.acme.local" \
    -addext "keyUsage=digitalSignature,keyEncipherment,dataEncipherment" \
    -addext "extendedKeyUsage=serverAuth,clientAuth"
```
<!-- @endcode-block -->

Critical pieces:

- **`URI:urn:my-symfony:client`** — the OPC UA `ApplicationUri`.
- **`extendedKeyUsage=serverAuth,clientAuth`** — both required
  by the OPC UA spec.
- **`keyUsage`** — sign, encipher keys, encipher data.

For ECC:

<!-- @code-block language="bash" label="ECC cert" -->
```bash
openssl ecparam -name prime256v1 -genkey -noout -out /etc/opcua/client.key
openssl req -x509 -key /etc/opcua/client.key \
    -out /etc/opcua/client.pem -days 365 \
    -subj "/CN=My Symfony Client/O=Acme" \
    -addext "subjectAltName=URI:urn:my-symfony:client"
```
<!-- @endcode-block -->

## Wiring up

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_CLIENT_CERT=/etc/opcua/client.pem
OPCUA_CLIENT_KEY=/etc/opcua/client.key
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            client_certificate: '%env(OPCUA_CLIENT_CERT)%'
            client_key:         '%env(OPCUA_CLIENT_KEY)%'
```
<!-- @endcode-block -->

## Trusting the cert server-side

The cert needs to land in the server's **trusted client cert
directory**:

### 1 — First-connection prompt

Many servers default to "reject + move to rejected dir". An
operator manually moves it to the trusted dir.

### 2 — Pre-stage

Drop the cert into the server's trusted dir before first
connection — avoids the "first call fails" UX.

Per-server-product paths:

| Server                  | Trusted cert dir                                       |
| ----------------------- | ------------------------------------------------------ |
| open62541 (default)      | `pki/trusted/certs/`                                    |
| Prosys OPC UA Simulation  | `~/.prosysopc/.../USER_PKI/CA/certs/`                   |
| Siemens S7 PLCs          | TIA Portal config                                       |
| KEPServerEX               | KEPServer Configuration UI                              |

## Cert rotation

Certs expire. Quarterly/annual rotation is standard.

### Zero-downtime

1. **Generate new cert** with same `ApplicationUri`.
2. **Stage in the server** alongside the old. Both are now trusted.
3. **Update Symfony** — `.env` → new paths, deploy.
4. **Restart daemon** — `systemctl restart opcua-session-manager`.
5. **Confirm connections** under the new cert.
6. **Remove old cert** from server.

### With downtime (simpler)

1. Generate new cert.
2. Update server (remove old, add new).
3. Update Symfony.
4. Restart daemon.

Step 2 → step 4 is the downtime window — typically 10-30 s.

## Cert chain — CA-signed

For larger fleets:

<!-- @code-block language="bash" label="CA-signed" -->
```bash
# 1. CA (once)
openssl req -x509 -newkey rsa:4096 -keyout opcua-ca.key \
    -out opcua-ca.pem -days 3650 -nodes \
    -subj "/CN=Acme OPC UA Root CA"

# 2. Client CSR
openssl req -new -newkey rsa:2048 -keyout client.key \
    -out client.csr -nodes \
    -subj "/CN=My Symfony Client/O=Acme"

# 3. Sign
openssl x509 -req -in client.csr -CA opcua-ca.pem -CAkey opcua-ca.key \
    -CAcreateserial -out client.pem -days 365 \
    -extfile <(echo "subjectAltName=URI:urn:my-symfony:client") \
    -extfile <(echo "extendedKeyUsage=serverAuth,clientAuth")
```
<!-- @endcode-block -->

Trust the **CA** on the server; any cert it signs gets
accepted. Wire the CA cert too:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_CA_CERT=/etc/opcua/opcua-ca.pem
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="bundle config" -->
```text
ca_certificate: '%env(OPCUA_CA_CERT)%'
```
<!-- @endcode-block -->

## Permissions

<!-- @code-block language="bash" label="terminal" -->
```bash
sudo chown www-data:www-data /etc/opcua/client.{pem,key}
sudo chmod 0640 /etc/opcua/client.pem
sudo chmod 0600 /etc/opcua/client.key      # private — owner only
```
<!-- @endcode-block -->

If FPM and the daemon run under different users, use a shared
group instead of broadening permissions.

## Per-connection certs

Large fleets: one cert per **connection name** for audit:

<!-- @code-block language="text" label="per-connection" -->
```text
php_opcua_symfony_opcua:
    connections:
        plc-line-a:
            client_certificate: '/etc/opcua/line-a.pem'
            client_key:         '/etc/opcua/line-a.key'
        plc-line-b:
            client_certificate: '/etc/opcua/line-b.pem'
            client_key:         '/etc/opcua/line-b.key'
```
<!-- @endcode-block -->

Server-side audit logs distinguish each line.

## Cert expiry monitoring

A scheduled Symfony command:

<!-- @code-block language="php" label="src/Command/CheckCertExpiryCommand.php" -->
```php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

#[AsCommand(name: 'app:opcua:cert:check')]
final class CheckCertExpiryCommand extends Command
{
    public function __construct(
        private array $opcuaConfig,
        private NotifierInterface $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, '', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $threshold = (int) $input->getOption('days');
        $rc = 0;

        foreach ($this->opcuaConfig['connections'] as $name => $conn) {
            $certPath = $conn['client_certificate'] ?? null;
            if ($certPath === null) continue;

            $expiry = $this->certExpiry($certPath);
            $daysLeft = (new \DateTimeImmutable())->diff($expiry)->days;

            if ($daysLeft < $threshold) {
                $output->writeln("<warning>$name expires in $daysLeft days</warning>");
                $this->notifier->send(
                    new \App\Notification\CertExpiringSoon($name, $expiry),
                    new Recipient('ops@example.com'),
                );
                $rc = 1;
            }
        }

        return $rc === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function certExpiry(string $path): \DateTimeImmutable
    {
        $cert = openssl_x509_parse(file_get_contents($path));
        return new \DateTimeImmutable('@' . $cert['validTo_time_t']);
    }
}
```
<!-- @endcode-block -->

Schedule daily via Symfony Scheduler — see
[Console and scheduler](../integrations/console-and-scheduler.md).

## Inspecting a cert

<!-- @code-block language="bash" label="inspect" -->
```bash
openssl x509 -in /etc/opcua/client.pem -noout -text
openssl x509 -in /etc/opcua/client.pem -noout -dates
openssl x509 -in /etc/opcua/client.pem -noout -subject
openssl x509 -in /etc/opcua/client.pem -noout -ext subjectAltName
```
<!-- @endcode-block -->

The SAN should contain `URI:urn:...` for OPC UA.

## What goes wrong

| Symptom                              | Cause                                          |
| ------------------------------------ | ---------------------------------------------- |
| `CertificateException — untrusted`    | Server doesn't have this cert in its trust list |
| `Bad_CertificateInvalid`              | Structure problem (missing extensions, bad URI) |
| `Bad_CertificateUriInvalid`           | `ApplicationUri` doesn't match SAN URI         |
| `Bad_CertificateUseNotAllowed`        | Missing `keyUsage` or `extendedKeyUsage`        |
| `Bad_CertificateTimeInvalid`          | Cert expired or not-yet-valid                   |
| `Bad_SecurityChecksFailed`            | Signature failure — wrong key                   |

## Where to read next

- [Trust store](./trust-store.md) — the **server-cert** side.
- [Recipes · Production deployment](../recipes/production-deployment.md) —
  cert lifecycle in real deployments.
