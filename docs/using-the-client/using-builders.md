---
eyebrow: 'Docs · Using the client'
lede:    'Fluent builders for read, write, browse, methods, subscriptions, history. The same upstream API, used through the autowired Symfony service.'

see_also:
  - { href: '../operations/reading.md',     meta: '6 min' }
  - { href: '../operations/writing.md',     meta: '5 min' }
  - { href: '../operations/subscriptions.md', meta: '7 min' }

prev: { label: 'Connection lifecycle', href: './connection-lifecycle.md' }
next: { label: 'Reading',              href: '../operations/reading.md' }
---

# Using builders

`opcua-client` exposes fluent builders for the major operations.
The Symfony bundle adds nothing on top — you call the same
builders through the autowired client.

## Six builders

| Builder              | One-shot shortcut                    |
| -------------------- | ------------------------------------- |
| `ReadBuilder`         | `$client->read('ns=2;s=Speed')`       |
| `WriteBuilder`        | `$client->write('ns=2;s=Setpoint', 75.0)` |
| `BrowseBuilder`       | `$client->browse('ns=0;i=85')`        |
| `CallBuilder`         | `$client->callMethod(...)`            |
| `SubscriptionBuilder` | `$client->subscribe(...)`             |
| `HistoryBuilder`      | `$client->historyBuilder()->...->execute()` |

Each follows the same pattern: chainable setters → `execute()`
(or operation-named) → typed result.

## Read builder

<!-- @code-block language="php" label="batch read" -->
```php
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\AttributeId;

$client = $this->opcua->connect();

// Batch — many tags, one round-trip
$values = $client->readBuilder()
    ->node('ns=2;s=Speed')
    ->node('ns=2;s=Temperature')
    ->node('ns=2;s=Pressure')
    ->executeMany();

// Non-Value attribute
$displayName = $client->readBuilder()
    ->node('ns=2;s=Speed')
    ->attribute(AttributeId::DisplayName)
    ->execute()
    ->getValue();
```
<!-- @endcode-block -->

`executeMany()` returns an array of `DataValue` in the order
of nodes added. See [Operations · Reading](../operations/reading.md).

## Write builder

<!-- @code-block language="php" label="batch write" -->
```php
use PhpOpcua\Client\Types\BuiltinType;

$client = $this->opcua->connect();

// Batch
$client->writeBuilder()
    ->value('ns=2;s=Setpoint', 75.0)
    ->value('ns=2;s=Mode',     'Auto')
    ->value('ns=2;s=Run',      true)
    ->execute();

// Explicit type override
$client->writeBuilder()
    ->value('ns=2;s=BigCounter', 9_999_999_999, BuiltinType::Int64)
    ->execute();
```
<!-- @endcode-block -->

See [Operations · Writing](../operations/writing.md) for the
auto-detection rules.

## Browse builder

<!-- @code-block language="php" label="browse" -->
```php
$nodes = $client->browseBuilder()
    ->from('ns=2;s=Folder')
    ->referenceType('Organizes')
    ->nodeClass('Variable')
    ->execute();

$tree = $client->browseRecursive('ns=4;s=Tags', maxDepth: 5);
```
<!-- @endcode-block -->

See [Operations · Browsing](../operations/browsing.md).

## Call builder

<!-- @code-block language="php" label="call method" -->
```php
[$status, $outputs] = $client->callBuilder()
    ->method('ns=2;s=Recipe', 'ns=2;s=Recipe.Load')
    ->input('NewRecipe')
    ->input(42)
    ->execute();
```
<!-- @endcode-block -->

See [Operations · Method calls](../operations/method-calls.md).

## Subscription builder

<!-- @code-block language="php" label="subscribe (direct mode)" -->
```php
$sub = $client->subscribe(
    publishingInterval: 500,
    onData: function (string $nodeId, $dv) {
        echo "$nodeId = {$dv->getValue()}\n";
    },
);

$sub->monitor('ns=2;s=Speed');
$sub->run();    // blocks
```
<!-- @endcode-block -->

In managed mode with `auto_publish: true`, subscriptions deliver
via the Symfony EventDispatcher instead — see
[Session manager · Auto-publish](../session-manager/auto-publish.md)
and [Events · Data events](../events/data-events.md).

## History builder

<!-- @code-block language="php" label="history" -->
```php
$values = $client->historyBuilder()
    ->node('ns=2;s=Speed')
    ->between(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable())
    ->aggregate('Average', interval: '5 minutes')
    ->execute();
```
<!-- @endcode-block -->

See [Operations · History](../operations/history.md).

## Chaining with `connect()`

Every builder is rooted in a connection:

<!-- @code-block language="php" label="connect + builder" -->
```php
$values = $this->opcua
    ->connect('historian')
    ->readBuilder()
    ->node('ns=4;s=Tag1')
    ->node('ns=4;s=Tag2')
    ->executeMany();
```
<!-- @endcode-block -->

Reads left-to-right: pick connection → pick builder → set
parameters → execute.

## When to use the builder vs the shortcut

| Operation              | Shortcut available?     | Use the builder when…                          |
| ---------------------- | ----------------------- | ---------------------------------------------- |
| Read one Value         | `$client->read(...)`    | Non-Value attribute, batch, type info           |
| Write one Value        | `$client->write(...)`   | Explicit type, batch                            |
| Browse one folder      | `$client->browse(...)`  | Filtering, recursive                            |
| Subscribe              | (none)                  | Always — subscriptions are stateful             |
| Method call            | (none)                  | Always                                          |
| History                | (none)                  | Always — history is parameter-heavy             |

For the most common single-node operations, the shortcut is
fine; for everything else, the builder.

## Builders are stateless across calls

Each builder is an instance with its own state. Don't reuse a
configured builder across iterations — start fresh:

<!-- @code-block language="php" label="don't reuse" -->
```php
$builder = $client->readBuilder();
foreach ($tags as $node) {
    $builder->node($node);    // accumulates state!
}
$values = $builder->executeMany();  // OK once

// NOT this — second iteration leaks state:
foreach ($groups as $group) {
    $builder = $client->readBuilder();
    foreach ($group as $node) {
        $builder->node($node);
    }
    $values = $builder->executeMany();
}
```
<!-- @endcode-block -->

Each `readBuilder()` call returns a fresh builder.

## Static analysis

Both PHPStan and Psalm resolve builder return types from the
fluent setters. `$client->readBuilder()->node('...')` is
`ReadBuilder`, `->execute()` is `DataValue`. No type
annotations needed.

## Async / queued builders

There's no async builder. PHP-OPC-UA is synchronous. To run an
operation off the request thread, dispatch a Messenger message
that uses the builder inside the handler:

<!-- @code-block language="php" label="queued read" -->
```php
#[AsMessageHandler]
final class SampleBatchHandler
{
    public function __construct(private OpcUaClientInterface $client) {}

    public function __invoke(SampleBatch $message): void
    {
        $builder = $this->client->readBuilder();
        foreach ($message->nodeIds as $node) {
            $builder->node($node);
        }
        $values = $builder->executeMany();
        // ...
    }
}
```
<!-- @endcode-block -->

See [Integrations · Messenger](../integrations/messenger.md).

## Reference

The builder classes themselves are documented in the upstream
[`opcua-client` docs](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/reading-attributes.md).
The Symfony bundle adds nothing — the API is the same.

## Where to read next

You've finished **Using the client**. Continue with
[Operations · Reading](../operations/reading.md) for per-operation
detail.
