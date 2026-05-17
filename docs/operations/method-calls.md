---
eyebrow: 'Docs · Operations'
lede:    'Calling OPC UA methods from Symfony — recipe loads, alarm acknowledgements, lifecycle transitions. The right tool when a single write isn''t enough.'

see_also:
  - { href: './writing.md',                       meta: '5 min' }
  - { href: '../recipes/alarm-routing.md',        meta: '7 min' }

prev: { label: 'Browsing',       href: './browsing.md' }
next: { label: 'Subscriptions',  href: './subscriptions.md' }
---

# Method calls

OPC UA method nodes are functions the server exposes. They take
typed input arguments, return typed outputs, and run atomically
server-side. The right tool when a single `write()` isn't
enough — recipe loads, batch acknowledgements, lifecycle
transitions.

## Anatomy of a call

A method call needs:

1. The **object** — the parent node.
2. The **method** — the method node itself.
3. **Input arguments** — matching the method's `InputArguments`.

Returns:

- A **call status** (`int` — 0 = good).
- An array of **output arguments**.

## Basic call

<!-- @code-block language="php" label="basic call" -->
```php
use PhpOpcua\Client\OpcUaClientInterface;

final class RecipeService
{
    public function __construct(private OpcUaClientInterface $client) {}

    public function load(string $recipeName, int $line): bool
    {
        [$status, $outputs] = $this->client->callMethod(
            objectNodeId: 'ns=2;s=Recipe',
            methodNodeId: 'ns=2;s=Recipe.Load',
            inputs: [$recipeName, $line],
        );

        if ($status !== 0) {
            throw new \RuntimeException(
                sprintf('Recipe load failed: 0x%X', $status)
            );
        }

        return (bool) $outputs[0];
    }
}
```
<!-- @endcode-block -->

The return is `[int $status, array $outputs]` — destructure
inline.

## Builder form

<!-- @code-block language="php" label="builder" -->
```php
[$status, $outputs] = $this->client->callBuilder()
    ->method('ns=2;s=Recipe', 'ns=2;s=Recipe.Load')
    ->input($recipeName)
    ->input($line)
    ->execute();
```
<!-- @endcode-block -->

For explicit-typed inputs:

<!-- @code-block language="php" label="typed inputs" -->
```php
use PhpOpcua\Client\Types\BuiltinType;

[$status, $outputs] = $this->client->callBuilder()
    ->method('ns=2;s=Recipe', 'ns=2;s=Recipe.Load')
    ->input($recipeName, BuiltinType::String)
    ->input($line,       BuiltinType::UInt32)
    ->execute();
```
<!-- @endcode-block -->

## Discovering signatures

Method nodes carry `InputArguments` and `OutputArguments`
property nodes:

<!-- @code-block language="php" label="discover" -->
```php
$inputs = $this->client->read('ns=2;s=Recipe.Load.InputArguments')->getValue();
foreach ($inputs as $arg) {
    echo "{$arg->name} : {$arg->dataType} ({$arg->description})\n";
}
```
<!-- @endcode-block -->

Always read signatures when integrating with a new method — argument
naming and ordering vary by server.

## Alarm acknowledgement — Notifier integration

A common UI flow: operator clicks "Acknowledge" → Symfony Notifier
broadcasts → method call confirms server-side.

<!-- @code-block language="php" label="src/Service/AlarmAcknowledgeService.php" -->
```php
namespace App\Service;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\BuiltinType;

final class AlarmAcknowledgeService
{
    public function __construct(private OpcUaClientInterface $client) {}

    public function acknowledge(string $eventIdHex, string $comment): bool
    {
        $eventId = hex2bin($eventIdHex);

        [$status, ] = $this->client->callBuilder()
            ->method('ns=0;i=2782', 'ns=0;i=9111')   // ConditionType, Acknowledge
            ->input($eventId, BuiltinType::ByteString)
            ->input(['locale' => 'en', 'text' => $comment])
            ->execute();

        if ($status !== 0) {
            throw new \RuntimeException(
                sprintf('Ack failed: 0x%X', $status)
            );
        }
        return true;
    }
}
```
<!-- @endcode-block -->

In the controller:

<!-- @code-block language="php" label="controller" -->
```php
#[Route('/api/alarms/{eventId}/ack', methods: ['POST'])]
public function ack(
    string $eventId,
    Request $request,
    AlarmAcknowledgeService $svc,
): JsonResponse {
    $this->denyAccessUnlessGranted('alarm.ack');

    $comment = (string) json_decode($request->getContent(), true)['comment'] ?? '';
    $svc->acknowledge($eventId, $comment);

    return $this->json(['status' => 'acked']);
}
```
<!-- @endcode-block -->

See [Recipes · Alarm routing](../recipes/alarm-routing.md).

## When status != 0

| Status code              | Likely cause                                              |
| ------------------------ | --------------------------------------------------------- |
| `Bad_NodeIdInvalid`       | Wrong method node ID                                       |
| `Bad_MethodInvalid`       | Method exists but invalid in this calling context          |
| `Bad_ArgumentsMissing`    | Fewer inputs than the method expects                       |
| `Bad_TypeMismatch`        | An input's type doesn't match `InputArguments`             |
| `Bad_OutOfRange`          | Input value out of range                                   |
| `Bad_UserAccessDenied`    | Session lacks permission                                   |
| `Bad_NotExecutable`       | Method's `Executable` attribute is `false`                  |

The bundle's `ServiceException` wraps the status with a
human-readable message. Catch and surface.

## Read first, then call

For state-dependent methods:

<!-- @code-block language="php" label="state-gated" -->
```php
$state = $this->client->read('ns=2;s=Line.State')->getValue();
if ($state !== 'Standby') {
    throw new \DomainException("Can't load recipe while line is $state");
}

$this->client->callBuilder()
    ->method('ns=2;s=Recipe', 'ns=2;s=Recipe.Load')
    ->input('NewRecipe')
    ->execute();
```
<!-- @endcode-block -->

This pair is **not atomic** — the state could change between the
read and the call. Accept the race or use a Symfony Lock to
serialise:

<!-- @code-block language="php" label="serialised with Lock" -->
```php
use Symfony\Component\Lock\LockFactory;

public function load(string $recipeName, LockFactory $locks): void
{
    $lock = $locks->createLock('plc-recipe-load', ttl: 60);
    $lock->acquire(blocking: true);

    try {
        $state = $this->client->read('ns=2;s=Line.State')->getValue();
        if ($state !== 'Standby') {
            throw new \DomainException("State is $state");
        }
        $this->client->callBuilder()
            ->method('ns=2;s=Recipe', 'ns=2;s=Recipe.Load')
            ->input($recipeName)
            ->execute();
    } finally {
        $lock->release();
    }
}
```
<!-- @endcode-block -->

See [Symfony Lock documentation](https://symfony.com/doc/current/lock.html).

## Concurrency at the device

Many PLCs serialise method execution device-side. Two concurrent
`Recipe.Load` calls don't run in parallel — the second waits or
returns `Bad_ResourceBusy`. Test the device's actual behaviour
before optimising.

## In Messenger handlers

For long-running operations (recipe loads can take seconds),
dispatch via Messenger:

<!-- @code-block language="php" label="src/Message/LoadRecipe.php" -->
```php
namespace App\Message;

final readonly class LoadRecipe
{
    public function __construct(
        public string $recipeName,
        public int $lineId,
    ) {}
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="src/MessageHandler/LoadRecipeHandler.php" -->
```php
namespace App\MessageHandler;

use App\Message\LoadRecipe;
use PhpOpcua\Client\OpcUaClientInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class LoadRecipeHandler
{
    public function __construct(private OpcUaClientInterface $client) {}

    public function __invoke(LoadRecipe $message): void
    {
        [$status, ] = $this->client->callBuilder()
            ->method('ns=2;s=Recipe', 'ns=2;s=Recipe.Load')
            ->input($message->recipeName)
            ->input($message->lineId)
            ->execute();

        if ($status !== 0) {
            throw new \RuntimeException(sprintf('Recipe load failed: 0x%X', $status));
        }
    }
}
```
<!-- @endcode-block -->

Don't retry the message — method calls aren't idempotent. Use
`#[AsMessageHandler]` with `WithMonologChannel` or a per-handler
retry strategy of 1.

## Browsing for methods

Discover the methods on an object:

<!-- @code-block language="php" label="browse methods" -->
```php
$methods = $this->client->browseBuilder()
    ->from('ns=2;s=Recipe')
    ->nodeClass('Method')
    ->execute();
```
<!-- @endcode-block -->

For each, read `InputArguments` / `OutputArguments` to build a
complete signature map.

## Where to read next

- [Subscriptions](./subscriptions.md) — react to method-induced
  state changes.
- [Recipes · Alarm routing](../recipes/alarm-routing.md) — full
  pipeline with the Notifier component.
