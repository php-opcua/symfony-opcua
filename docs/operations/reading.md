---
eyebrow: 'Docs · Operations'
lede:    'Reading values — single, batched, non-Value attributes — and the DataValue shape every Symfony service will reach for.'

see_also:
  - { href: '../using-the-client/using-builders.md', meta: '5 min' }
  - { href: '../reference/exceptions.md',            meta: '4 min' }
  - { href: '../recipes/persistent-tag-history.md',  meta: '7 min' }

prev: { label: 'Using builders', href: '../using-the-client/using-builders.md' }
next: { label: 'Writing',        href: './writing.md' }
---

# Reading

The most common operation. Through the autowired client:

<!-- @code-block language="php" label="basic read" -->
```php
use PhpOpcua\Client\OpcUaClientInterface;

final class SpeedService
{
    public function __construct(private OpcUaClientInterface $client) {}

    public function current(): ?float
    {
        $dv = $this->client->read('ns=2;s=Speed');

        return $dv->statusCode === 0 ? (float) $dv->getValue() : null;
    }
}
```
<!-- @endcode-block -->

`read()` returns a `DataValue` — value + status + timestamps.

## The DataValue shape

| Field              | Type                  | Meaning                                |
| ------------------ | --------------------- | -------------------------------------- |
| `value`            | mixed                 | Decoded value — PHP type per `BuiltinType` |
| `statusCode`       | int                   | 0 = good, otherwise per-spec failure   |
| `sourceTimestamp`  | `?DateTimeImmutable`   | When the device produced the value     |
| `serverTimestamp`  | `?DateTimeImmutable`   | When the OPC UA server stamped it      |
| `type`             | `BuiltinType` enum     | Wire-level type                        |
| `dimensions`       | `?array<int>`          | Array dims if multi-dimensional        |

`getValue()` returns the value; `statusCode === 0` is "Good".

## Batch reads

For more than ~3 nodes, batch:

<!-- @code-block language="php" label="batch" -->
```php
$values = $this->client->readBuilder()
    ->node('ns=2;s=Speed')
    ->node('ns=2;s=Temperature')
    ->node('ns=2;s=Pressure')
    ->executeMany();

// $values is an array of DataValue, same order as nodes
```
<!-- @endcode-block -->

Order preserved. One round-trip — much faster than N sequential
`read()` calls. The client chunks to the server's
`MaxNodesPerRead` automatically.

## Non-Value attributes

OPC UA nodes have 22 attributes. To read others:

<!-- @code-block language="php" label="non-Value" -->
```php
use PhpOpcua\Client\Types\AttributeId;

$display = $this->client->readBuilder()
    ->node('ns=2;s=Speed')
    ->attribute(AttributeId::DisplayName)
    ->execute()
    ->getValue();        // "Speed"

$access = $this->client->readBuilder()
    ->node('ns=2;s=Speed')
    ->attribute(AttributeId::AccessLevel)
    ->execute()
    ->getValue();        // byte bitmask
```
<!-- @endcode-block -->

Common non-Value attributes:

| `AttributeId`        | Returns                                  | Use case                           |
| -------------------- | ----------------------------------------- | ---------------------------------- |
| `DisplayName`         | Localized text                            | UI labels                           |
| `Description`         | Localized text                            | Tag tooltips                        |
| `DataType`            | NodeId                                    | Inferring writeable BuiltinType     |
| `NodeClass`           | int (enum)                                | Browse-filtering                    |
| `AccessLevel`         | byte bitmask                              | Is the node writeable / historizable? |
| `Historizing`         | bool                                      | History recording flag              |
| `ArrayDimensions`     | array of int                              | Array sizing                        |

## Cast PHP types

What you get back per `BuiltinType`:

| BuiltinType         | PHP type                                |
| ------------------- | --------------------------------------- |
| `Boolean`            | `bool`                                  |
| `SByte`, `Byte`      | `int`                                   |
| `Int16`...`UInt32`   | `int`                                   |
| `Int64`, `UInt64`    | `int` (on 64-bit PHP)                  |
| `Float`, `Double`    | `float`                                 |
| `String`             | `string`                                |
| `DateTime`           | `DateTimeImmutable`                     |
| `Guid`               | `string` (UUID format)                  |
| `ByteString`         | `string` (binary)                       |
| `LocalizedText`      | array `{locale, text}`                  |
| `QualifiedName`      | array `{ns, name}`                      |

When persisting via Doctrine, cast explicitly:

<!-- @code-block language="php" label="persist" -->
```php
$reading = new PlcReading();
$reading->setNodeId('ns=2;s=Speed');
$reading->setValue((float) $dv->getValue());
$reading->setStatus($dv->statusCode);
$reading->setSourceAt($dv->sourceTimestamp);

$this->em->persist($reading);
$this->em->flush();
```
<!-- @endcode-block -->

## Status code handling

Two common patterns:

### Treat bad as failure

<!-- @code-block language="php" label="fail on bad" -->
```php
$dv = $this->client->read('ns=2;s=Speed');
if ($dv->statusCode !== 0) {
    throw new \RuntimeException(
        sprintf('Bad read: status=0x%X', $dv->statusCode)
    );
}
$speed = $dv->getValue();
```
<!-- @endcode-block -->

### Store both value and quality

<!-- @code-block language="php" label="store status" -->
```php
$reading = (new PlcReading())
    ->setNodeId('ns=2;s=Speed')
    ->setValue($dv->getValue())
    ->setStatusCode($dv->statusCode)
    ->setGood($dv->statusCode === 0)
    ->setSourceAt($dv->sourceTimestamp);

$this->em->persist($reading);
```
<!-- @endcode-block -->

Production apps usually store both — the value with a quality
marker is more useful than the value alone.

## Status helpers

<!-- @code-block language="php" label="status helpers" -->
```php
use PhpOpcua\Client\Types\StatusCode;

if (StatusCode::isGood($dv->statusCode))      { /* ... */ }
if (StatusCode::isUncertain($dv->statusCode)) { /* ... */ }
if (StatusCode::isBad($dv->statusCode))       { /* ... */ }

$name = StatusCode::name($dv->statusCode);  // 'Good' / 'BadCommunicationError' / …
```
<!-- @endcode-block -->

## Error handling

Per failure mode:

| Exception                       | Trigger                                | Right response                    |
| ------------------------------- | --------------------------------------- | --------------------------------- |
| `ConnectionException`            | TCP layer issue                         | Retry with backoff                |
| `InactiveSessionException`       | Server-side session timeout              | Retry (transparent reconnect)     |
| `ServiceException`               | Server returned `Bad_*`                  | Surface to caller (likely permanent) |
| `EncodingException`              | Wire decode failed                      | Bug — report                      |

<!-- @code-block language="php" label="resilient read" -->
```php
namespace App\Service;

use PhpOpcua\Client\Exception\{ConnectionException, InactiveSessionException};
use PhpOpcua\Client\OpcUaClientInterface;
use Psr\Log\LoggerInterface;

final class ResilientSpeedReader
{
    public function __construct(
        private OpcUaClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    public function read(): ?float
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $dv = $this->client->read('ns=2;s=Speed');
                return $dv->statusCode === 0 ? (float) $dv->getValue() : null;
            } catch (ConnectionException | InactiveSessionException $e) {
                $this->logger->warning('Speed read retry', [
                    'attempt' => $attempt, 'error' => $e->getMessage(),
                ]);
                usleep(200_000 * $attempt);
            }
        }

        $this->logger->error('Speed read failed after 3 attempts');
        return null;
    }
}
```
<!-- @endcode-block -->

For app-wide retry, set `auto_retry` per connection in YAML —
see [Connections](../configuration/connections.md).

## Reading across connections

<!-- @code-block language="php" label="plant-wide" -->
```php
$plant = [];
foreach (array_keys($this->opcuaConfig['connections']) as $name) {
    try {
        $dv = $this->opcua->connect($name)->read('ns=2;s=Speed');
        $plant[$name] = [
            'speed' => $dv->getValue(),
            'good'  => $dv->statusCode === 0,
        ];
    } catch (\Throwable $e) {
        $plant[$name] = ['error' => $e->getMessage()];
    }
}
```
<!-- @endcode-block -->

For very wide plants (50+ connections), parallelise with
Messenger — see [Integrations · Messenger](../integrations/messenger.md).

## Caching read results

**Don't** cache OPC UA read values in your application cache
unless you genuinely want stale data — values **are** the truth
of the device.

If you need cheap polling, use a **subscription** that
maintains the latest value in Symfony's cache:

<!-- @code-block language="php" label="subscription → cache" -->
```php
// In an event listener on DataChangeReceived:
$cache->save(
    $cache->getItem('opcua.latest.' . $event->nodeId)
        ->set([
            'value'  => $event->dataValue->getValue(),
            'status' => $event->dataValue->statusCode,
            'at'     => $event->dataValue->sourceTimestamp?->format('c'),
        ])
        ->expiresAfter(300)
);
```
<!-- @endcode-block -->

See [Recipes · Persistent tag history](../recipes/persistent-tag-history.md)
and [Events · Data events](../events/data-events.md).

## A read endpoint

<!-- @code-block language="php" label="controller" -->
```php
namespace App\Controller;

use PhpOpcua\Client\Exception\OpcUaException;
use PhpOpcua\Client\OpcUaClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class TagController extends AbstractController
{
    public function __construct(private OpcUaClientInterface $client) {}

    #[Route('/api/tags/{node}', methods: ['GET'], requirements: ['node' => '.+'])]
    public function show(string $node): JsonResponse
    {
        try {
            $dv = $this->client->read($node);
        } catch (OpcUaException $e) {
            return $this->json(['error' => $e->getMessage()], 502);
        }

        return $this->json([
            'node_id'    => $node,
            'value'      => $dv->getValue(),
            'status'     => $dv->statusCode,
            'good'       => $dv->statusCode === 0,
            'source_at'  => $dv->sourceTimestamp?->format('c'),
        ]);
    }
}
```
<!-- @endcode-block -->

## Where to read next

- [Writing](./writing.md) — the dual operation with
  authorisation, audit, and rate-limiting patterns.
- [Subscriptions](./subscriptions.md) — read the same value
  repeatedly without polling.
- [Recipes · Persistent tag history](../recipes/persistent-tag-history.md) —
  full Doctrine persistence pattern.
