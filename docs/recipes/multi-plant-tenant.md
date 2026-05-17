---
eyebrow: 'Docs · Recipes'
lede:    'Multi-tenant Symfony apps with per-tenant OPC UA endpoints, isolated trust stores, scoped persistence, and Doctrine global filters.'

see_also:
  - { href: '../using-the-client/named-connections.md',   meta: '5 min' }
  - { href: '../using-the-client/ad-hoc-connections.md',  meta: '5 min' }
  - { href: '../security/trust-store.md',                 meta: '6 min' }

prev: { label: 'Mercure real-time dashboard',  href: './mercure-realtime-dashboard.md' }
next: { label: 'Using companion specs',         href: './using-companion-specs.md' }
---

# Multi-plant tenant

A single Symfony app serving many plants, each with its own
OPC UA infrastructure. Four hard parts:

1. Per-tenant connection config.
2. Per-tenant credentials and trust stores.
3. Tenant-aware listeners (data, alarms).
4. Per-tenant data isolation in Doctrine.

## Tenancy model

This recipe assumes you've adopted a tenancy strategy —
[`hakam/multi-tenancy-bundle`](https://github.com/RamyHakam/multi_tenancy_bundle),
a custom Doctrine filter, or per-tenant Symfony kernels. The
patterns adapt; the bundle stays the same.

## Per-tenant connection — static config

For 10-100 known tenants:

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    connections:
        plc-tenant-acme:
            endpoint:           '%env(OPCUA_ACME_ENDPOINT)%'
            security_policy:    Basic256Sha256
            security_mode:      SignAndEncrypt
            client_certificate: '/etc/opcua/tenants/acme/cert.pem'
            client_key:         '/etc/opcua/tenants/acme/cert.key'
            username:           '%env(OPCUA_ACME_USER)%'
            password:           '%env(secret:OPCUA_ACME_PASS)%'
            trust_store_path:   '/var/lib/opcua/tenants/acme/trust'
        plc-tenant-globex:
            # ... same shape
```
<!-- @endcode-block -->

A resolver service:

<!-- @code-block language="php" label="src/Opcua/ConnectionResolver.php" -->
```php
namespace App\Opcua;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class ConnectionResolver
{
    public function __construct(private TokenStorageInterface $tokens) {}

    public function forCurrentTenant(): string
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof User) {
            throw new \DomainException('No authenticated user');
        }
        return 'plc-tenant-' . $user->getTenant()->getSlug();
    }
}
```
<!-- @endcode-block -->

Controllers use it:

<!-- @code-block language="php" label="usage" -->
```php
public function read(ConnectionResolver $r): JsonResponse
{
    $conn = $r->forCurrentTenant();
    $dv = $this->opcua->connect($conn)->read('ns=2;s=Speed');
    return $this->json(['value' => $dv->getValue()]);
}
```
<!-- @endcode-block -->

## Per-tenant — dynamic via Doctrine

For 100+ tenants from a `tenant_plc_configs` table:

<!-- @code-block language="php" label="src/Entity/TenantPlcConfig.php" -->
```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TenantPlcConfig
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\OneToOne(targetEntity: Tenant::class)]
    public Tenant $tenant;

    #[ORM\Column(type: 'string')]
    public string $endpoint;

    #[ORM\Column(type: 'string', length: 50)]
    public string $securityPolicy = 'Basic256Sha256';

    #[ORM\Column(type: 'string', length: 50)]
    public string $securityMode = 'SignAndEncrypt';

    #[ORM\Column(type: 'string')]
    public string $certPath;

    #[ORM\Column(type: 'string')]
    public string $keyPath;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    public ?string $username = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[ORM\Column(type: 'string', options: ['comment' => 'encrypted via doctrine extension'])]
    public ?string $passwordEncrypted = null;

    public function toOpcuaConfig(\App\Security\Encryptor $encryptor): array
    {
        return [
            'endpoint'           => $this->endpoint,
            'security_policy'    => $this->securityPolicy,
            'security_mode'      => $this->securityMode,
            'client_certificate' => $this->certPath,
            'client_key'         => $this->keyPath,
            'username'           => $this->username,
            'password'           => $this->passwordEncrypted ? $encryptor->decrypt($this->passwordEncrypted) : null,
            'trust_store_path'   => "/var/lib/opcua/tenants/{$this->tenant->getSlug()}/trust",
            'timeout'            => 8.0,
        ];
    }
}
```
<!-- @endcode-block -->

Fleet reader service:

<!-- @code-block language="php" label="src/Service/FleetReader.php" -->
```php
namespace App\Service;

use App\Repository\TenantPlcConfigRepository;
use App\Security\Encryptor;
use PhpOpcua\SymfonyOpcua\OpcuaManager;

final class FleetReader
{
    public function __construct(
        private OpcuaManager $opcua,
        private TenantPlcConfigRepository $repo,
        private Encryptor $encryptor,
    ) {}

    public function speedFor(string $tenantSlug): ?float
    {
        $config = $this->repo->findOneByTenantSlug($tenantSlug);
        if ($config === null) return null;

        $client = $this->opcua->connectTo(
            $config->endpoint,
            $config->toOpcuaConfig($this->encryptor),
            as: "tenant:$tenantSlug",
        );

        return (float) $client->read('ns=2;s=Speed')->getValue();
    }
}
```
<!-- @endcode-block -->

## Per-tenant trust stores

<!-- @code-block language="text" label="filesystem" -->
```text
/var/lib/opcua/tenants/
├── acme/
│   └── trust/
│       └── <fingerprint>.pem
├── globex/
│   └── trust/
└── soylent/
    └── trust/
```
<!-- @endcode-block -->

Per-tenant pin:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console opcua:trust:add opc.tcp://acme-plc.factory.local:4840 \
    --store=/var/lib/opcua/tenants/acme/trust
```
<!-- @endcode-block -->

Permissions:

<!-- @code-block language="bash" label="perms" -->
```bash
sudo chown -R www-data:www-data /var/lib/opcua/tenants/
sudo chmod 750 /var/lib/opcua/tenants/
sudo find /var/lib/opcua/tenants -type d -exec chmod 750 {} \;
sudo find /var/lib/opcua/tenants -type f -exec chmod 640 {} \;
```
<!-- @endcode-block -->

A compromised tenant trust store doesn't affect others.

## Tenant-aware listeners

The event arrives without tenant context — resolve from the
connection name:

<!-- @code-block language="php" label="tenant-aware listener" -->
```php
namespace App\EventListener;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class StoreTenantReading
{
    public function __construct(
        private TenantRepository $tenants,
        private EntityManagerInterface $em,
    ) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $tenant = $this->tenantFromConnection($event->connection);
        if ($tenant === null) {
            return;
        }

        $reading = (new \App\Entity\PlcReading())
            ->setTenant($tenant)
            ->setNodeId($event->nodeId)
            ->setValue($event->dataValue->getValue())
            ->setStatusCode($event->dataValue->statusCode)
            ->setSourceAt($event->dataValue->sourceTimestamp);

        $this->em->persist($reading);
        $this->em->flush();
    }

    private function tenantFromConnection(string $connection): ?Tenant
    {
        if (preg_match('/^plc-tenant-(.+)$/', $connection, $m)) {
            return $this->tenants->findOneBy(['slug' => $m[1]]);
        }
        if (preg_match('/^tenant:(.+)$/', $connection, $m)) {
            return $this->tenants->findOneBy(['slug' => $m[1]]);
        }
        return null;
    }
}
```
<!-- @endcode-block -->

## Per-tenant data isolation — Doctrine filter

<!-- @code-block language="php" label="src/Doctrine/Filter/TenantFilter.php" -->
```php
namespace App\Doctrine\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

final class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $target, string $alias): string
    {
        if (!$target->hasAssociation('tenant')) {
            return '';
        }
        $tenantId = (int) $this->getParameter('tenantId');
        return "$alias.tenant_id = $tenantId";
    }
}
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="config/packages/doctrine.yaml" -->
```text
doctrine:
    orm:
        filters:
            tenant:
                class: App\Doctrine\Filter\TenantFilter
                enabled: false
```
<!-- @endcode-block -->

Enable per-request:

<!-- @code-block language="php" label="kernel listener" -->
```php
namespace App\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class EnableTenantFilter
{
    public function __construct(
        private EntityManagerInterface $em,
        private TokenStorageInterface $tokens,
    ) {}

    #[AsEventListener(priority: -10)]
    public function __invoke(RequestEvent $event): void
    {
        $user = $this->tokens->getToken()?->getUser();
        if ($user === null || !method_exists($user, 'getTenant')) {
            return;
        }

        $filter = $this->em->getFilters()->enable('tenant');
        $filter->setParameter('tenantId', $user->getTenant()->getId());
    }
}
```
<!-- @endcode-block -->

## Per-tenant daemons (hard isolation)

For hard tenant isolation, one daemon per tenant:

<!-- @code-block language="text" label="systemd template" -->
```text
# /etc/systemd/system/opcua-session-manager@.service
[Service]
User=opcua-%i
Group=opcua-%i
WorkingDirectory=/var/www/html
EnvironmentFile=/etc/opcua/tenants/%i.env
ExecStart=/usr/bin/php /var/www/html/bin/console opcua:session
```
<!-- @endcode-block -->

`/etc/opcua/tenants/acme.env`:

<!-- @code-block language="bash" label="env" -->
```bash
OPCUA_SOCKET_PATH=/var/run/opcua/acme.sock
OPCUA_AUTH_TOKEN=...
```
<!-- @endcode-block -->

…with the bundle config pointing at `%env(OPCUA_SOCKET_PATH)%`.

Enable per tenant:

<!-- @code-block language="bash" label="enable per tenant" -->
```bash
systemctl enable --now opcua-session-manager@acme.service
systemctl enable --now opcua-session-manager@globex.service
```
<!-- @endcode-block -->

## Onboarding command

<!-- @code-block language="php" label="src/Command/OnboardTenantCommand.php" -->
```php
#[AsCommand(name: 'app:tenant:onboard')]
final class OnboardTenantCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED);
        $this->addArgument('endpoint', InputArgument::REQUIRED);
        $this->addArgument('username', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('slug');
        $base = "/var/lib/opcua/tenants/{$slug}";

        // 1. Create directories
        mkdir("$base/trust", recursive: true);

        // 2. Generate client cert
        $this->generateClientCert("$base/cert.pem", "$base/cert.key", $slug);

        // 3. Pin server cert
        $this->getApplication()->find('opcua:trust:add')->run(
            new ArrayInput([
                'endpoint' => $input->getArgument('endpoint'),
                '--store'  => "$base/trust",
                '--force'  => true,
            ]),
            $output,
        );

        // 4. Persist config to DB ...
        $output->writeln("Tenant $slug onboarded");
        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

## Where to read next

- [Using companion specs](./using-companion-specs.md) —
  type-aware browse for tenant-specific tag taxonomies.
- [Production deployment](./production-deployment.md) — shipping
  the whole multi-tenant pattern.
