---
eyebrow: 'Docs · Operations'
lede:    'Writes mutate physical equipment. Type detection, explicit types, batch writes, the authorisation/audit/rate-limit patterns Symfony apps reach for.'

see_also:
  - { href: './reading.md',                meta: '6 min' }
  - { href: '../reference/exceptions.md',  meta: '4 min' }
  - { href: '../security/credentials.md',  meta: '5 min' }

prev: { label: 'Reading',   href: './reading.md' }
next: { label: 'Browsing',  href: './browsing.md' }
---

# Writing

`$client->write($node, $value)` sends a value to a writable OPC
UA node.

<!-- @callout type="warning" -->
**Writes mutate physical equipment.** Treat them with the same
care as `DELETE FROM` in SQL. Authorisation, audit, rate-limiting
belong at the application layer — Symfony has all three out of
the box.
<!-- @endcallout -->

## Basic write

<!-- @code-block language="php" label="write" -->
```php
use PhpOpcua\Client\OpcUaClientInterface;

final class SetpointService
{
    public function __construct(private OpcUaClientInterface $client) {}

    public function setSpeed(float $rpm): void
    {
        $this->client->write('ns=2;s=Setpoint', $rpm);
    }
}
```
<!-- @endcode-block -->

Returns `true` on success. Throws on failure:

- `ServiceException` — server rejected (`Bad_TypeMismatch`,
  `Bad_NotWritable`, `Bad_AccessDenied`).
- `ConnectionException` / `InactiveSessionException` — transport.

## Type detection

The client infers `BuiltinType` from the PHP value:

| PHP value                  | Detected BuiltinType    |
| -------------------------- | ----------------------- |
| `true` / `false`            | `Boolean`               |
| `int` ∈ `[-2^31, 2^31)`     | `Int32`                 |
| `int` outside that range    | `Int64`                 |
| `float`                     | `Double`                |
| `string`                    | `String`                |
| `DateTimeImmutable`         | `DateTime`              |
| `array<int>`                | `Int32` array           |
| `array<float>`              | `Double` array          |

This covers ~90% of cases. The remaining 10% need an explicit
type override.

## Explicit types

When the server rejects an auto-detected write with
`Bad_TypeMismatch`:

<!-- @code-block language="php" label="explicit Float" -->
```php
use PhpOpcua\Client\Types\BuiltinType;

$this->client->writeBuilder()
    ->value('ns=2;s=Setpoint', 75.0, BuiltinType::Float)
    ->execute();
```
<!-- @endcode-block -->

Common overrides:

| Server expects   | PHP value you have       | Type to pass                |
| ---------------- | ------------------------ | --------------------------- |
| `Float`           | `float`                  | `BuiltinType::Float`         |
| `Int16`           | `int`                    | `BuiltinType::Int16`         |
| `UInt32`          | `int`                    | `BuiltinType::UInt32`        |
| `Byte`            | small `int`              | `BuiltinType::Byte`          |
| `String` for a numeric node | numeric `string`  | `BuiltinType::String`        |

## Batch write

<!-- @code-block language="php" label="batch" -->
```php
$this->client->writeBuilder()
    ->value('ns=2;s=Setpoint',  75.0)
    ->value('ns=2;s=Mode',      'Auto')
    ->value('ns=2;s=Run',       true)
    ->execute();
```
<!-- @endcode-block -->

Returns per-write status. Non-atomic — successful writes within
the batch stand even if others fail. For atomic semantics, call
a method on the server side — see
[Method calls](./method-calls.md).

## Authorisation — Symfony Voter

<!-- @code-block language="php" label="src/Security/PlcVoter.php" -->
```php
namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, string>
 */
final class PlcVoter extends Voter
{
    public const WRITE = 'plc.write';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::WRITE && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $nodeId, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if ($user === null) return false;

        if (str_contains($nodeId, 'Setpoint') && $token->getUser()->hasRole('ROLE_OPERATOR')) {
            return true;
        }
        if (str_contains($nodeId, 'Run') && $token->getUser()->hasRole('ROLE_SUPERVISOR')) {
            return true;
        }
        return false;
    }
}
```
<!-- @endcode-block -->

In the controller:

<!-- @code-block language="php" label="authorising in controller" -->
```php
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/plc/{node}', methods: ['PUT'], requirements: ['node' => '.+'])]
public function write(
    string $node,
    Request $request,
    OpcUaClientInterface $client,
): JsonResponse {
    $this->denyAccessUnlessGranted('plc.write', $node);

    $value = json_decode($request->getContent(), true)['value'] ?? null;
    if ($value === null) {
        return $this->json(['error' => 'missing value'], 400);
    }

    $client->write($node, $value);
    return $this->json(['status' => 'ok']);
}
```
<!-- @endcode-block -->

## Audit log — Doctrine entity

<!-- @code-block language="php" label="src/Entity/PlcWriteAudit.php" -->
```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class PlcWriteAudit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $userId;

    #[ORM\Column(type: 'string', length: 255)]
    public string $nodeId;

    #[ORM\Column(type: 'json')]
    public mixed $valueBefore = null;

    #[ORM\Column(type: 'json')]
    public mixed $valueRequested;

    #[ORM\Column(type: 'json')]
    public mixed $valueAfter = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $writtenAt;
}
```
<!-- @endcode-block -->

Around the write:

<!-- @code-block language="php" label="audit write" -->
```php
$before = $client->read($node)->getValue();
$client->write($node, $value);
$after  = $client->read($node)->getValue();

$audit = (new PlcWriteAudit())
    ->setUserId((string) $this->getUser()->getUserIdentifier())
    ->setNodeId($node)
    ->setValueBefore($before)
    ->setValueRequested($value)
    ->setValueAfter($after)
    ->setWrittenAt(new \DateTimeImmutable());

$this->em->persist($audit);
$this->em->flush();
```
<!-- @endcode-block -->

## Rate limiter

<!-- @code-block language="text" label="config/packages/rate_limiter.yaml" -->
```text
framework:
    rate_limiter:
        plc_write:
            policy: 'sliding_window'
            limit:  10
            interval: '1 minute'
```
<!-- @endcode-block -->

In the controller:

<!-- @code-block language="php" label="rate-limited write" -->
```php
use Symfony\Component\RateLimiter\RateLimiterFactory;

public function write(
    string $node,
    Request $request,
    RateLimiterFactory $plcWriteLimiter,
    OpcUaClientInterface $client,
): JsonResponse {
    $this->denyAccessUnlessGranted('plc.write', $node);

    $limit = $plcWriteLimiter->create($this->getUser()->getUserIdentifier())->consume();
    if (!$limit->isAccepted()) {
        return $this->json(['error' => 'rate limit'], 429);
    }

    $value = json_decode($request->getContent(), true)['value'] ?? null;
    $client->write($node, $value);

    return $this->json(['status' => 'ok']);
}
```
<!-- @endcode-block -->

## Two-step confirmation flow

For high-impact setpoint changes:

<!-- @code-block language="php" label="confirmation token" -->
```php
// Step 1 — propose
$token = bin2hex(random_bytes(16));
$this->cache->save(
    $this->cache->getItem('plc-write.' . $token)
        ->set(['node' => $node, 'value' => $value, 'user' => $userId])
        ->expiresAfter(300)
);

return $this->json([
    'confirmation_token' => $token,
    'message' => "Setpoint will change from $before to $value. Confirm within 5 minutes.",
]);

// Step 2 — commit
$item = $this->cache->getItem('plc-write.' . $token);
if (!$item->isHit()) {
    return $this->json(['error' => 'token expired or invalid'], 419);
}
$pending = $item->get();
$this->cache->deleteItem('plc-write.' . $token);

if ($pending['user'] !== $userId) {
    return $this->json(['error' => 'token user mismatch'], 419);
}

$client->write($pending['node'], $pending['value']);
return $this->json(['status' => 'applied']);
```
<!-- @endcode-block -->

## Writing arrays

<!-- @code-block language="php" label="array write" -->
```php
$client->write('ns=2;s=RecipeIngredients', [1.5, 2.0, 3.2, 0.8]);

// Explicit element type
use PhpOpcua\Client\Types\BuiltinType;
$client->writeBuilder()
    ->value('ns=2;s=RecipeIngredients', [1.5, 2.0, 3.2, 0.8], BuiltinType::Float)
    ->execute();
```
<!-- @endcode-block -->

## Multi-dimensional arrays

<!-- @code-block language="php" label="2D write" -->
```php
$matrix = [[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]];

$client->writeBuilder()
    ->value('ns=2;s=Matrix', $matrix, BuiltinType::Double)
    ->dimensions([2, 3])
    ->execute();
```
<!-- @endcode-block -->

Row-major flattening is automatic.

## Error recovery

A failed write should **not** be silently retried — setpoint
changes are not idempotent in the operator's mental model.

If retry is needed for **transport** errors only:

<!-- @code-block language="php" label="bounded retry" -->
```php
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceException;

try {
    $client->write($node, $value);
} catch (ConnectionException $e) {
    sleep(1);
    $client->write($node, $value);   // single retry on transport error
} catch (ServiceException $e) {
    // Don't retry — server received and rejected. Surface to operator.
    throw $e;
}
```
<!-- @endcode-block -->

## Where to read next

- [Browsing](./browsing.md) — discovering writable nodes.
- [Method calls](./method-calls.md) — atomic multi-write
  alternatives.
- [Recipes · Alarm routing](../recipes/alarm-routing.md) —
  acknowledgement writes via the Notifier component.
