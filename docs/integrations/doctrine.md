---
eyebrow: 'Docs · Integrations'
lede:    'Persisting OPC UA data with Doctrine ORM. Entity shapes, batched inserts, partitioning, and repository patterns for tag history and alarms.'

see_also:
  - { href: './messenger.md',                          meta: '7 min' }
  - { href: '../recipes/persistent-tag-history.md',    meta: '7 min' }
  - { href: '../recipes/alarm-routing.md',             meta: '7 min' }

prev: { label: 'Mercure',  href: './mercure.md' }
next: { label: 'Twig',     href: './twig.md' }
---

# Doctrine

Most OPC UA-driven apps persist some subset of the data —
readings, alarms, audit logs, fleet metadata. Doctrine ORM is
the standard Symfony persistence layer; this page covers
practical patterns.

## A tag-history entity

The canonical shape:

<!-- @code-block language="php" label="src/Entity/PlcReading.php" -->
```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'plc_readings', indexes: [
    new ORM\Index(name: 'idx_node_sourceAt', columns: ['node_id', 'source_at']),
    new ORM\Index(name: 'idx_connection_at', columns: ['connection', 'source_at']),
])]
class PlcReading
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    public string $connection = 'default';

    #[ORM\Column(type: 'string', length: 255)]
    public string $nodeId;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 6, nullable: true)]
    public ?string $valueNumeric = null;

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $valueText = null;

    #[ORM\Column(type: 'integer')]
    public int $statusCode;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $sourceAt;

    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    public \DateTimeImmutable $createdAt;
}
```
<!-- @endcode-block -->

Two value columns (numeric + text) capture any `BuiltinType`
without loss. The `(node_id, source_at)` composite index is the
dominant query.

## Migration

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```
<!-- @endcode-block -->

For PostgreSQL, consider **partitioning** large tag tables by
`source_at` — see [Partitioning](#partitioning) below.

## Repository

<!-- @code-block language="php" label="src/Repository/PlcReadingRepository.php" -->
```php
namespace App\Repository;

use App\Entity\PlcReading;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlcReading>
 */
final class PlcReadingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlcReading::class);
    }

    /** @return PlcReading[] */
    public function findForNode(string $nodeId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.nodeId = :node')
            ->andWhere('r.sourceAt BETWEEN :from AND :to')
            ->setParameter('node', $nodeId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.sourceAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function latestFor(string $nodeId): ?PlcReading
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.nodeId = :node')
            ->setParameter('node', $nodeId)
            ->orderBy('r.sourceAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
```
<!-- @endcode-block -->

## Batched insert via Messenger

Per-event insert is fine for low volume. For high-frequency
subscriptions, batch:

<!-- @code-block language="php" label="batched handler" -->
```php
namespace App\MessageHandler;

use App\Entity\PlcReading;
use App\Message\StoreReadingBatch;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StoreReadingBatchHandler
{
    public function __construct(private Connection $db) {}

    public function __invoke(StoreReadingBatch $message): void
    {
        $sql = "INSERT INTO plc_readings
                (connection, node_id, value_numeric, value_text, status_code, source_at, created_at)
                VALUES " . implode(',', array_fill(0, count($message->readings), '(?,?,?,?,?,?,NOW())'));

        $params = [];
        foreach ($message->readings as $r) {
            $isNumeric = is_numeric($r['value']);
            $params[] = $r['connection'];
            $params[] = $r['nodeId'];
            $params[] = $isNumeric ? $r['value'] : null;
            $params[] = $isNumeric ? null         : (string) $r['value'];
            $params[] = $r['statusCode'];
            $params[] = $r['sourceAt']->format('Y-m-d H:i:s.u');
        }

        $this->db->executeStatement($sql, $params);
    }
}
```
<!-- @endcode-block -->

Bulk-INSERT via DBAL is dramatically faster than `persist + flush`
for 100+ rows. See [Recipes · Persistent tag history](../recipes/persistent-tag-history.md).

## Aggregation

For long retention without exploding storage, aggregate to
1-minute (or 1-hour) buckets:

<!-- @code-block language="php" label="aggregation entity" -->
```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'plc_reading_aggregates_1m')]
#[ORM\UniqueConstraint(name: 'uniq_node_bucket', columns: ['node_id', 'bucket_at'])]
class PlcReadingAggregate1m
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    public string $nodeId;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $bucketAt;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 6)]
    public string $avgValue;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 6)]
    public string $minValue;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 6)]
    public string $maxValue;

    #[ORM\Column(type: 'integer')]
    public int $sampleCount;
}
```
<!-- @endcode-block -->

Aggregation job (runs every minute):

<!-- @code-block language="php" label="aggregate command" -->
```php
#[AsCommand(name: 'app:plc:aggregate-1m')]
final class Aggregate1mCommand extends Command
{
    public function __construct(private Connection $db) { parent::__construct(); }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $this->db->executeStatement(<<<SQL
            INSERT INTO plc_reading_aggregates_1m
                (node_id, bucket_at, avg_value, min_value, max_value, sample_count)
            SELECT
                node_id,
                DATE_TRUNC('minute', source_at) AS bucket_at,
                AVG(value_numeric),
                MIN(value_numeric),
                MAX(value_numeric),
                COUNT(*)
            FROM plc_readings
            WHERE source_at >= NOW() - INTERVAL '2 minutes'
              AND source_at <  NOW() - INTERVAL '1 minute'
              AND value_numeric IS NOT NULL
            GROUP BY node_id, bucket_at
            ON CONFLICT (node_id, bucket_at) DO UPDATE
                SET avg_value = EXCLUDED.avg_value,
                    min_value = EXCLUDED.min_value,
                    max_value = EXCLUDED.max_value,
                    sample_count = EXCLUDED.sample_count
        SQL);

        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

PostgreSQL-flavoured SQL — adapt for MySQL/MariaDB.

## Retention — pruning

<!-- @code-block language="php" label="prune command" -->
```php
#[AsCommand(name: 'app:plc:prune-readings')]
final class PruneReadingsCommand extends Command
{
    public function __construct(private Connection $db) { parent::__construct(); }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $cutoff = (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');

        do {
            $deleted = $this->db->executeStatement(
                'DELETE FROM plc_readings WHERE source_at < ? LIMIT 10000',
                [$cutoff],
            );
            sleep(1);
        } while ($deleted > 0);

        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Chunked deletes avoid long table locks.

Schedule daily:

<!-- @code-block language="php" label="prune schedule" -->
```php
RecurringMessage::cron('0 2 * * *', new RunCommandMessage('app:plc:prune-readings'))
```
<!-- @endcode-block -->

## Partitioning

For very high-volume tag tables (>10 M rows), partition by
month:

<!-- @code-block language="text" label="PostgreSQL partition" -->
```text
CREATE TABLE plc_readings (
    id BIGSERIAL,
    ...
    source_at TIMESTAMP NOT NULL,
    PRIMARY KEY (id, source_at)
) PARTITION BY RANGE (source_at);

CREATE TABLE plc_readings_2026_05 PARTITION OF plc_readings
    FOR VALUES FROM ('2026-05-01') TO ('2026-06-01');
```
<!-- @endcode-block -->

Pre-create partitions for the next 12 months. Old partitions
can be dropped with `DROP TABLE` (fast) vs `DELETE FROM` (slow).

## Multi-tenant scope

For per-tenant data, add a `tenant_id` column and use a global
Doctrine filter:

<!-- @code-block language="php" label="tenant filter" -->
```php
namespace App\Doctrine\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

final class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $target, string $alias): string
    {
        if (!$target->hasField('tenantId')) {
            return '';
        }
        $tenantId = (int) $this->getParameter('tenantId');
        return "$alias.tenant_id = $tenantId";
    }
}
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="doctrine.yaml" -->
```text
doctrine:
    orm:
        filters:
            tenant:
                class: App\Doctrine\Filter\TenantFilter
                enabled: false   # enabled per-request
```
<!-- @endcode-block -->

In a request listener:

<!-- @code-block language="php" label="enable filter" -->
```php
$filter = $em->getFilters()->enable('tenant');
$filter->setParameter('tenantId', $user->getTenantId());
```
<!-- @endcode-block -->

See [Recipes · Multi-plant tenant](../recipes/multi-plant-tenant.md).

## Where to read next

- [Twig](./twig.md) — rendering OPC UA data.
- [Recipes · Persistent tag history](../recipes/persistent-tag-history.md) —
  full pipeline.
- [Recipes · Alarm routing](../recipes/alarm-routing.md) — the
  alarm-table sibling.
