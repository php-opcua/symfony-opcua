---
eyebrow: 'Docs · Recipes'
lede:    'End-to-end persistent tag-history pipeline: Doctrine schema, Messenger-routed subscriptions, batched inserts, retention, and a query controller.'

see_also:
  - { href: '../operations/subscriptions.md',                meta: '7 min' }
  - { href: '../events/data-events.md',                      meta: '6 min' }
  - { href: '../integrations/messenger.md',                  meta: '7 min' }

prev: { label: 'Exceptions',         href: '../reference/exceptions.md' }
next: { label: 'Alarm routing',      href: './alarm-routing.md' }
---

# Persistent tag history

When the OPC UA server isn't a historian — or you want
short-term history in your Symfony app for fast queries — this
is the canonical pattern.

## What you get

- Every tag change persisted in a `plc_readings` table.
- Batched inserts so throughput scales.
- Auto-purging old rows per retention policy.
- A query controller (`/api/tags/{node}/history`).
- ~80 lines of Symfony code on top of the bundle.

## Doctrine entity

<!-- @code-block language="php" label="src/Entity/PlcReading.php" -->
```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'plc_readings', indexes: [
    new ORM\Index(name: 'idx_node_at',       columns: ['node_id', 'source_at']),
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

## Subscribe + dispatch listener

<!-- @code-block language="php" label="src/EventListener/BufferReading.php" -->
```php
namespace App\EventListener;

use App\Message\StoreReading;
use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

final class BufferReading
{
    public function __construct(private MessageBusInterface $bus) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $this->bus->dispatch(new StoreReading(
            connection: $event->connection,
            nodeId:     $event->nodeId,
            value:      $event->dataValue->getValue(),
            statusCode: $event->dataValue->statusCode,
            sourceAt:   $event->dataValue->sourceTimestamp ?? new \DateTimeImmutable(),
        ));
    }
}
```
<!-- @endcode-block -->

## The message

<!-- @code-block language="php" label="src/Message/StoreReading.php" -->
```php
namespace App\Message;

final readonly class StoreReading
{
    public function __construct(
        public string $connection,
        public string $nodeId,
        public mixed $value,
        public int $statusCode,
        public \DateTimeImmutable $sourceAt,
    ) {}
}
```
<!-- @endcode-block -->

## Batched handler

For high throughput, the handler buffers into Redis and flushes
in 100-row batches:

<!-- @code-block language="php" label="src/MessageHandler/StoreReadingHandler.php" -->
```php
namespace App\MessageHandler;

use App\Message\StoreReading;
use Doctrine\DBAL\Connection;
use Predis\ClientInterface as Redis;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StoreReadingHandler
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private Redis $redis,
        private Connection $db,
    ) {}

    public function __invoke(StoreReading $msg): void
    {
        if ($msg->statusCode !== 0) return;

        $this->redis->rpush('plc.batch', json_encode([
            'connection' => $msg->connection,
            'nodeId'     => $msg->nodeId,
            'value'      => $msg->value,
            'statusCode' => $msg->statusCode,
            'sourceAt'   => $msg->sourceAt->format('Y-m-d H:i:s.u'),
        ]));

        if ($this->redis->llen('plc.batch') >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $items = $this->redis->multi()
            ->lrange('plc.batch', 0, -1)
            ->del('plc.batch')
            ->exec()[0];

        if (empty($items)) return;

        $rows = array_map(fn(string $j) => json_decode($j, true), $items);

        $values = [];
        $params = [];
        foreach ($rows as $r) {
            $isNum = is_numeric($r['value']);
            $values[] = '(?, ?, ?, ?, ?, ?, NOW())';
            $params[] = $r['connection'];
            $params[] = $r['nodeId'];
            $params[] = $isNum ? $r['value'] : null;
            $params[] = $isNum ? null         : (string) $r['value'];
            $params[] = $r['statusCode'];
            $params[] = $r['sourceAt'];
        }

        $sql = 'INSERT INTO plc_readings
            (connection, node_id, value_numeric, value_text, status_code, source_at, created_at)
            VALUES ' . implode(',', $values);

        $this->db->executeStatement($sql, $params);
    }
}
```
<!-- @endcode-block -->

## Periodic flush

A scheduled command drains every 5 s even if the threshold
isn't hit:

<!-- @code-block language="php" label="src/Command/FlushReadingsCommand.php" -->
```php
namespace App\Command;

use App\MessageHandler\StoreReadingHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:plc:flush-readings')]
final class FlushReadingsCommand extends Command
{
    public function __construct(private StoreReadingHandler $handler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->handler->flush();
        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Schedule:

<!-- @code-block language="php" label="src/Scheduler/PlcSchedule.php" -->
```php
use Symfony\Component\Console\Messenger\RunCommandMessage;

RecurringMessage::every('5 seconds', new RunCommandMessage('app:plc:flush-readings'))
```
<!-- @endcode-block -->

`withoutOverlapping` is handled by the `LockableTrait` — add it
to the command if your flush could overrun the schedule.

## Retention

<!-- @code-block language="php" label="src/Command/PruneReadingsCommand.php" -->
```php
#[AsCommand(name: 'app:plc:prune')]
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

Daily schedule:

<!-- @code-block language="php" label="prune schedule" -->
```php
RecurringMessage::cron('0 2 * * *', new RunCommandMessage('app:plc:prune'))
```
<!-- @endcode-block -->

## Query controller

<!-- @code-block language="php" label="src/Controller/PlcHistoryController.php" -->
```php
namespace App\Controller;

use App\Entity\PlcReading;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

final class PlcHistoryController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/api/tags/{node}/history', methods: ['GET'], requirements: ['node' => '.+'])]
    public function show(string $node, Request $request): JsonResponse
    {
        $from = new \DateTimeImmutable($request->query->get('from', '-1 hour'));
        $to   = new \DateTimeImmutable($request->query->get('to',   'now'));

        $rows = $this->em->getRepository(PlcReading::class)
            ->createQueryBuilder('r')
            ->andWhere('r.nodeId = :node')
            ->andWhere('r.sourceAt BETWEEN :from AND :to')
            ->setParameter('node', $node)
            ->setParameter('from', $from)
            ->setParameter('to',   $to)
            ->orderBy('r.sourceAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->json(array_map(fn($r) => [
            'value'  => $r->valueNumeric !== null ? (float) $r->valueNumeric : $r->valueText,
            'status' => $r->statusCode,
            'at'     => $r->sourceAt->format('c'),
        ], $rows));
    }
}
```
<!-- @endcode-block -->

## Throughput characteristics

| Subscription rate    | Per-batch size     | Drain interval | Sustained?  |
| -------------------- | ------------------ | --------------- | ----------- |
| 100 events / sec     | 100                | 5 s             | Yes         |
| 500 events / sec     | 100                | 1 s             | Yes         |
| 1000 events / sec    | 100                | 1 s             | Tight — consider larger batches |
| 5000 events / sec    | 500                | 1 s             | Pre-allocate Redis, tune DB    |

## Aggregation for longer retention

See [Doctrine integration](../integrations/doctrine.md#aggregation)
for the 1-minute bucket pattern. Combine with hourly / daily
aggregates and your retention can grow indefinitely without
table-size pain.

## Where to read next

- [Alarm routing](./alarm-routing.md) — the alarms-table
  sibling.
- [Mercure real-time dashboard](./mercure-realtime-dashboard.md) —
  reading from this table live.
- [Production deployment](./production-deployment.md) — shipping
  the pipeline.
