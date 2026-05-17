---
eyebrow: 'Docs · Using the client'
lede:    'connectTo($endpoint, $config) for endpoints that aren''t in YAML. Fleet patterns, security caveats, and the persistence rules.'

see_also:
  - { href: './named-connections.md',           meta: '5 min' }
  - { href: '../configuration/connections.md',  meta: '7 min' }
  - { href: './connection-lifecycle.md',        meta: '5 min' }

prev: { label: 'Named connections',  href: './named-connections.md' }
next: { label: 'Connection lifecycle', href: './connection-lifecycle.md' }
---

# Ad-hoc connections

`$opcua->connectTo($endpoint, $config, $as)` opens a connection
without a YAML entry. Use it when endpoints are discovered at
runtime — fleet registries, per-tenant DB rows, user-provided
URLs.

## The basic shape

<!-- @code-block language="php" label="connectTo" -->
```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

final class FleetService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function speed(string $serial): ?float
    {
        $client = $this->opcua->connectTo(
            "opc.tcp://plc-{$serial}.factory.local:4840",
            [
                'security_policy'  => 'Basic256Sha256',
                'security_mode'    => 'SignAndEncrypt',
                'client_certificate' => '/etc/opcua/client.pem',
                'client_key'         => '/etc/opcua/client.key',
                'username'           => 'integrations',
                'password'           => $this->vaultPassword($serial),
                'timeout'            => 8.0,
            ],
            as: "fleet:{$serial}",   // optional cache name
        );

        return (float) $client->read('ns=2;s=Speed')->getValue();
    }
}
```
<!-- @endcode-block -->

The config array accepts the same keys as a YAML
`connections.*` entry. Missing keys take sensible defaults
(`security_policy: None`, anonymous, default timeout).

## When YAML, when ad-hoc

| Scenario                                            | Use                  |
| --------------------------------------------------- | -------------------- |
| 3 PLCs, fixed layout                                | YAML                  |
| Per-tenant servers, declared statically              | YAML                  |
| 200-PLC fleet from a database                        | Ad-hoc               |
| User-provided endpoint in an admin tool              | Ad-hoc                |
| Edge gateway connecting to whichever PLC is online    | Ad-hoc                |

The line: **does the config live in code (YAML) or in data
(database, registry, request)?**

## Caching identity

`connectTo` caches the resulting client under the key you pass
as `$as` (or a hash of the config if you omit it). Two calls
with the **same key** return the **same client**:

<!-- @code-block language="php" label="cache" -->
```php
$a = $opcua->connectTo($url, $config, as: 'fleet:plc-001');
$b = $opcua->connectTo($url, $config, as: 'fleet:plc-001');
// $a === $b within the same OpcuaManager instance
```
<!-- @endcode-block -->

Use stable `$as` values across the request lifecycle to share a
single client.

## Fleet pattern with a registry

<!-- @code-block language="php" label="src/Opcua/FleetReader.php" -->
```php
namespace App\Opcua;

use App\Repository\PlcUnitRepository;
use PhpOpcua\SymfonyOpcua\OpcuaManager;

final class FleetReader
{
    public function __construct(
        private OpcuaManager $opcua,
        private PlcUnitRepository $repo,
    ) {}

    public function readSpeed(string $serial): ?float
    {
        $unit = $this->repo->findOneBySerial($serial);
        if ($unit === null) {
            return null;
        }

        $client = $this->opcua->connectTo(
            $unit->getEndpoint(),
            [
                'security_policy'    => $unit->getSecurityPolicy(),
                'security_mode'      => $unit->getSecurityMode(),
                'client_certificate' => $unit->getCertPath(),
                'client_key'         => $unit->getKeyPath(),
                'username'           => $unit->getUsername(),
                'password'           => $unit->getDecryptedPassword(),
                'timeout'            => 8.0,
            ],
            as: "fleet:{$serial}",
        );

        return (float) $client->read('ns=2;s=Speed')->getValue();
    }
}
```
<!-- @endcode-block -->

`PlcUnit` is an arbitrary Doctrine entity — see
[Recipes · Persistent tag history](../recipes/persistent-tag-history.md)
for a related Doctrine pattern.

## Credentials in the config array

Anything in `$config` (passwords, certs paths) is **in memory
only** — the bundle never logs config arrays. But:

- **Don't serialize a config array** into a Messenger message.
  Send the registry **key** (serial number, tenant ID) and
  re-resolve inside the handler:

<!-- @code-block language="php" label="messenger handler" -->
```php
#[AsMessageHandler]
final class SampleFleetHandler
{
    public function __construct(private FleetReader $reader) {}

    public function __invoke(SampleFleet $message): void
    {
        // Resolves credentials from DB at handle time
        $speed = $this->reader->readSpeed($message->serial);
        // ... persist
    }
}
```
<!-- @endcode-block -->

The message holds `$message->serial`; the handler resolves
credentials from the secured DB. No secret hits the queue.

## User-provided endpoints

If the endpoint URL comes from a user (admin form, API
parameter), **validate** before passing to `connectTo()`:

<!-- @code-block language="php" label="validation" -->
```php
use Symfony\Component\Validator\Constraints as Assert;

#[Assert\Regex(
    pattern: '/^opc\.tcp:\/\/[a-zA-Z0-9.-]+\.factory\.local:\d{1,5}$/',
    message: 'Endpoint must point inside *.factory.local',
)]
public string $endpoint;
```
<!-- @endcode-block -->

Or a constraint class for richer validation. The bundle doesn't
sandbox connections — if you pass an endpoint, the bundle tries
to open it. Network-level egress filtering is the right place
for the hard guarantee.

## Disconnecting an ad-hoc connection

<!-- @code-block language="php" label="disconnect ad-hoc" -->
```php
$opcua->disconnect('fleet:plc-001');
// or
$opcua->disconnectAll();
```
<!-- @endcode-block -->

`disconnect` accepts either the `$as` name or the client
instance itself.

## Mixed style

Named and ad-hoc coexist on the same manager:

<!-- @code-block language="php" label="mixed" -->
```php
$historian = $opcua->connect('historian');                 // named
$plc1      = $opcua->connectTo($url, $config, 'fleet:plc-001'); // ad-hoc
```
<!-- @endcode-block -->

The cache holds both, the lifecycle rules are the same.

## Pre-validating the endpoint

Symfony's `OptionsResolver` is a nice place to validate a config
array before passing it to `connectTo`:

<!-- @code-block language="php" label="OptionsResolver" -->
```php
use Symfony\Component\OptionsResolver\OptionsResolver;

$resolver = new OptionsResolver();
$resolver->setRequired(['endpoint']);
$resolver->setDefaults([
    'security_policy' => 'Basic256Sha256',
    'security_mode'   => 'SignAndEncrypt',
    'timeout'         => 8.0,
]);
$resolver->setAllowedValues('security_policy', [
    'None','Basic128Rsa15','Basic256','Basic256Sha256',
    'Aes128Sha256RsaOaep','Aes256Sha256RsaPss',
    'ECC_nistP256','ECC_nistP384',
    'ECC_brainpoolP256r1','ECC_brainpoolP384r1',
]);

$normalised = $resolver->resolve($rawConfig);

$client = $opcua->connectTo($url, $normalised, as: $key);
```
<!-- @endcode-block -->

This catches typos at the application layer before the bundle
tries to interpret the values.

## Where to read next

- [Connection lifecycle](./connection-lifecycle.md) — what happens
  between `connectTo()` and the eventual disconnect.
- [Recipes · Multi-plant tenant](../recipes/multi-plant-tenant.md) —
  per-tenant ad-hoc connection patterns.
