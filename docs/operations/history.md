---
eyebrow: 'Docs · Operations'
lede:    'Reading historical values from a HistoryServer. Time-range queries, aggregates, continuation, and the Doctrine bridge pattern.'

see_also:
  - { href: './reading.md',                          meta: '6 min' }
  - { href: '../recipes/persistent-tag-history.md',  meta: '7 min' }

prev: { label: 'Subscriptions',     href: './subscriptions.md' }
next: { label: 'Session manager · Overview', href: '../session-manager/overview.md' }
---

# History

Historized tags (most modern OPC UA servers do this for some
subset) keep a time series. The `historyRead` service queries
it.

<!-- @callout type="note" -->
**Not every server is a historian.** Check the node's
`Historizing` attribute or the server's `AccessHistoryData`
capability bit before assuming history is available.
<!-- @endcallout -->

## Raw history read

<!-- @code-block language="php" label="raw history" -->
```php
use PhpOpcua\Client\OpcUaClientInterface;

final class HistoryService
{
    public function __construct(private OpcUaClientInterface $client) {}

    public function lastHour(string $node): array
    {
        return $this->client->historyBuilder()
            ->node($node)
            ->between(
                new \DateTimeImmutable('-1 hour'),
                new \DateTimeImmutable(),
            )
            ->execute();
    }
}
```
<!-- @endcode-block -->

Returns a chronologically-ordered list of `DataValue` — same
shape as a live `read()`.

## Continuation

Servers cap returned values per call. The client handles
continuation points transparently — a 50 000-value range with a
1 000-value server cap becomes 50 internal calls.

For **very large** ranges, paginate manually to avoid building
huge in-memory arrays:

<!-- @code-block language="php" label="manual paging" -->
```php
$cursor = new \DateTimeImmutable('-1 day');
$end    = new \DateTimeImmutable();

while ($cursor < $end) {
    $chunkEnd = min(
        $cursor->modify('+1 hour'),
        $end
    );

    $values = $this->client->historyBuilder()
        ->node('ns=2;s=Speed')
        ->between($cursor, $chunkEnd)
        ->execute();

    foreach ($values as $dv) {
        $reading = (new PlcReading())
            ->setNodeId('ns=2;s=Speed')
            ->setValue($dv->getValue())
            ->setSourceAt($dv->sourceTimestamp);
        $this->em->persist($reading);
    }
    $this->em->flush();
    $this->em->clear();   // free entity memory

    $cursor = $chunkEnd;
}
```
<!-- @endcode-block -->

Hour-sized chunks fit most usage.

## Aggregates

Most servers support server-side aggregates over time buckets.
The wire savings are large — let the server collapse 30 000 raw
values into 60 minute-buckets:

<!-- @code-block language="php" label="aggregate" -->
```php
$buckets = $this->client->historyBuilder()
    ->node('ns=2;s=Speed')
    ->between(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable())
    ->aggregate('Average', interval: '1 hour')
    ->execute();
```
<!-- @endcode-block -->

Supported aggregates (server-dependent):

- `Average` — arithmetic mean
- `Total` — integral over time
- `Minimum` / `Maximum`
- `Count`
- `TimeAverage` — time-weighted mean (better for sparse signals)
- `StandardDeviationSample`

Check the server's
`Server.ServerCapabilities.AggregateFunctions` for the supported
list.

## At-time reads

For a single point at a specific moment:

<!-- @code-block language="php" label="at-time" -->
```php
$dv = $this->client->historyBuilder()
    ->node('ns=2;s=Speed')
    ->at(new \DateTimeImmutable('2026-05-15 10:30:00'))
    ->execute();
```
<!-- @endcode-block -->

The server interpolates to that moment.

## Multi-node history

<!-- @code-block language="php" label="multi-node" -->
```php
$results = $this->client->historyBuilder()
    ->node('ns=2;s=Speed')
    ->node('ns=2;s=Temperature')
    ->node('ns=2;s=Pressure')
    ->between(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable())
    ->executeMany();

// $results['ns=2;s=Speed']        => array of DataValue
// $results['ns=2;s=Temperature']  => array of DataValue
// ...
```
<!-- @endcode-block -->

## Combining live + history

A common analytics pattern: last 24 hours + current value:

<!-- @code-block language="php" label="last 24h + current" -->
```php
$series = $this->client->historyBuilder()
    ->node('ns=2;s=Speed')
    ->between(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable())
    ->aggregate('Average', interval: '5 minutes')
    ->execute();

$series[] = $this->client->read('ns=2;s=Speed');

return $this->json(['series' => $series, 'latest' => end($series)]);
```
<!-- @endcode-block -->

## When the server isn't a historian

Build your own using a live subscription persisting to Doctrine
— see [Recipes · Persistent tag history](../recipes/persistent-tag-history.md).

Trade-offs:

| Approach                        | Best for                                          |
| ------------------------------- | ------------------------------------------------- |
| Server-side history             | Long retention, large data, low ops surface        |
| Subscription → Doctrine          | Custom retention, Symfony-native queries           |
| Both                             | Short-term in Doctrine + long-term server-side    |

The third option is most common in mature plants.

## Performance

| Query                              | Approx duration                       |
| ---------------------------------- | ------------------------------------- |
| 1 node, 1 hour raw (~3600 vals)     | 200-500 ms                            |
| 1 node, 1 day raw                   | 1-3 s                                 |
| 1 node, 1 day @ 1 min average        | 100-300 ms                           |
| 100 nodes, 1 hour raw                | 1-5 s                                 |

For analytics dashboards, **always** use server-side aggregates.

## In Messenger handlers

History reads are slow and bursty — dispatch via Messenger
rather than running in the request:

<!-- @code-block language="php" label="src/Message/FetchDailyHistory.php" -->
```php
namespace App\Message;

final readonly class FetchDailyHistory
{
    public function __construct(
        public string $nodeId,
        public string $day,           // 'YYYY-MM-DD'
    ) {}
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="handler" -->
```php
namespace App\MessageHandler;

use App\Entity\DailyPlcAggregate;
use App\Message\FetchDailyHistory;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\OpcUaClientInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FetchDailyHistoryHandler
{
    public function __construct(
        private OpcUaClientInterface $client,
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(FetchDailyHistory $message): void
    {
        $start = new \DateTimeImmutable($message->day . ' 00:00:00');
        $end   = $start->modify('+1 day');

        $buckets = $this->client->historyBuilder()
            ->node($message->nodeId)
            ->between($start, $end)
            ->aggregate('Average', interval: '1 hour')
            ->execute();

        foreach ($buckets as $dv) {
            $agg = (new DailyPlcAggregate())
                ->setNodeId($message->nodeId)
                ->setHour($dv->sourceTimestamp)
                ->setAverage((float) $dv->getValue());
            $this->em->persist($agg);
        }
        $this->em->flush();
    }
}
```
<!-- @endcode-block -->

Schedule daily via Symfony Scheduler:

<!-- @code-block language="php" label="schedule" -->
```php
#[AsSchedule('plc-history')]
final class PlcHistorySchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        return (new Schedule())
            ->add(RecurringMessage::cron('0 1 * * *', new FetchDailyHistory('ns=2;s=Speed',       $yesterday)))
            ->add(RecurringMessage::cron('0 1 * * *', new FetchDailyHistory('ns=2;s=Temperature', $yesterday)));
    }
}
```
<!-- @endcode-block -->

## Where to read next

You've finished **Operations**. Continue with [Session manager ·
Overview](../session-manager/overview.md) for the daemon deep-dive,
or jump to [Events · Overview](../events/overview.md) for the
listener side in managed mode.
