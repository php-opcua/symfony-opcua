---
eyebrow: 'Docs · Using the client'
lede:    'Switch between configured connections by name with $opcua->connect("name"). Caching rules, dynamic routing, and the multi-tenant pattern.'

see_also:
  - { href: '../configuration/connections.md',     meta: '7 min' }
  - { href: './ad-hoc-connections.md',             meta: '5 min' }
  - { href: './connection-lifecycle.md',           meta: '5 min' }

prev: { label: 'Manager vs interface',  href: './manager-vs-interface.md' }
next: { label: 'Ad-hoc connections',    href: './ad-hoc-connections.md' }
---

# Named connections

The bundle resolves connection names against
`connections.*` in `config/packages/php_opcua_symfony_opcua.yaml`.
The pattern mirrors `doctrine.connections` and
`messenger.transports`.

## The shape

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    default: plc-line-a
    connections:
        plc-line-a:
            endpoint: 'opc.tcp://plc-a.factory.local:4840'
        plc-line-b:
            endpoint: 'opc.tcp://plc-b.factory.local:4840'
        historian:
            endpoint: 'opc.tcp://historian.factory.local:4841'
```
<!-- @endcode-block -->

## Switching at the call site

<!-- @code-block language="php" label="switching" -->
```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

final class PlantService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function dailyReport(): array
    {
        // Default
        $live = $this->opcua->connect()->read('ns=2;s=Output');

        // Named
        $lineB = $this->opcua->connect('plc-line-b')->read('ns=2;s=Output');
        $hist  = $this->opcua->connect('historian')->historyBuilder()
                    ->node('ns=4;s=DailyTotal')
                    ->between(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable())
                    ->execute();

        return ['live' => $live, 'lineB' => $lineB, 'history' => $hist];
    }
}
```
<!-- @endcode-block -->

`connect()` (no arg) is shorthand for `connect($default)`.

## connect() vs connection()

The bundle exposes two methods that look similar:

| Method                            | What it does                                                |
| --------------------------------- | ----------------------------------------------------------- |
| `$opcua->connection($name)`        | Returns a client. In **managed** mode, doesn't open a session yet. |
| `$opcua->connect($name)`           | Same — but in managed mode, **opens** the session immediately. |

For most application code, `connect()` is what you want.
`connection()` exists for cases where you want to configure the
managed client before opening (e.g., adjusting timeouts at
runtime — though most setters can be applied after `connect()`
too).

In **direct mode**, both behave identically — the client is
already connected by the time it returns.

## Method resolution

`$opcua->read(...)` (without `connect()`) **also works** — the
manager's `__call` proxy forwards to the default connection:

<!-- @code-block language="php" label="implicit proxy" -->
```php
$dv = $opcua->read('ns=2;s=Speed');
// equivalent to
$dv = $opcua->connect()->read('ns=2;s=Speed');
```
<!-- @endcode-block -->

The implicit form is convenient in scripts; the explicit form
(`->connect()->read(...)`) is more readable in controllers.

## Caching

Each connection resolves to **one client instance per `OpcuaManager`
instance**. The first `connect('plc-line-b')` opens it; subsequent
calls return the cached client.

- **HTTP request scope**: typically the Symfony container is
  recreated per request, so the cache is per-request.
- **Long-running workers** (Messenger consumer, daemon): the
  cache persists for the worker's lifetime — desirable for
  performance.

See [Connection lifecycle](./connection-lifecycle.md).

## Disconnect

<!-- @code-block language="php" label="disconnect" -->
```php
$opcua->disconnect('plc-line-b');
$opcua->disconnectAll();
```
<!-- @endcode-block -->

After `disconnect`, the next `connect('plc-line-b')` opens a
fresh client. The `OpcuaManager` registers a shutdown function
to clean up at PHP exit, so explicit `disconnectAll()` is rarely
needed.

## Dynamic routing

For multi-tenant or per-request routing, encapsulate the
"which connection" decision in a service:

<!-- @code-block language="php" label="src/Opcua/ConnectionResolver.php" -->
```php
namespace App\Opcua;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class ConnectionResolver
{
    public function __construct(private TokenStorageInterface $tokens) {}

    public function forCurrentUser(): string
    {
        $user = $this->tokens->getToken()?->getUser();
        if ($user instanceof \App\Entity\User) {
            return 'plc-tenant-' . $user->getTenant()->getSlug();
        }
        return 'default';
    }
}
```
<!-- @endcode-block -->

…then in the controller:

<!-- @code-block language="php" label="controller" -->
```php
final class TagsController
{
    public function __construct(
        private OpcuaManager $opcua,
        private ConnectionResolver $resolver,
    ) {}

    public function show(): JsonResponse
    {
        $name = $this->resolver->forCurrentUser();
        $dv = $this->opcua->connect($name)->read('ns=2;s=Speed');
        return new JsonResponse(['value' => $dv->getValue()]);
    }
}
```
<!-- @endcode-block -->

The resolver is one autowireable class; controllers stay slim.

## Unknown connection names

<!-- @code-block language="php" label="error" -->
```php
$opcua->connect('typo');
// → InvalidArgumentException: OPC UA connection [typo] is not configured.
```
<!-- @endcode-block -->

Validation happens at resolution time, not at config-load — so
typos surface on the first call. In tests, exercise unhappy
paths to catch them in CI.

## Listing configured connections

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console debug:config php_opcua_symfony_opcua connections
```
<!-- @endcode-block -->

Or programmatically:

<!-- @code-block language="php" label="listing" -->
```php
$names = array_keys($container->getParameter('php_opcua_symfony_opcua_connections'));
```
<!-- @endcode-block -->

(The bundle doesn't expose a `getConnectionNames()` helper on
`OpcuaManager` — but the `config` array it was constructed with
holds them under `$config['connections']`.)

## Naming conventions

Recap from [Configuration · Connections](../configuration/connections.md):

- Lowercase, kebab-case (`plc-line-a`, `historian`, `plc-tenant-acme`).
- Role over instance (`plc-line-a` not `plc-192-168-1-10`).
- Include tenancy when relevant (`plc-tenant-acme`).

## Health endpoint for every connection

<!-- @code-block language="php" label="health controller" -->
```php
namespace App\Controller;

use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    public function __construct(
        private OpcuaManager $opcua,
        private array $opcuaConfig,    // injected via container param
    ) {}

    #[Route('/health/opcua', methods: ['GET'])]
    public function opcua(): JsonResponse
    {
        $results = [];
        foreach (array_keys($this->opcuaConfig['connections']) as $name) {
            try {
                $this->opcua->connect($name)->read('i=2256');   // ServerStatus
                $results[$name] = 'ok';
            } catch (\Throwable $e) {
                $results[$name] = 'unhealthy: ' . class_basename($e);
            }
        }
        return $this->json($results);
    }
}
```
<!-- @endcode-block -->

Wire the config array as a service parameter:

<!-- @code-block language="text" label="config/services.yaml" -->
```text
services:
    App\Controller\HealthController:
        arguments:
            $opcuaConfig: '%php_opcua_symfony_opcua.config%'
```
<!-- @endcode-block -->

(That parameter isn't exported by the bundle by default — you
can mirror the config into `services.yaml` `parameters:` or
inject `OpcuaManager` and iterate via your own list.)

## Where to read next

- [Ad-hoc connections](./ad-hoc-connections.md) — when YAML
  can't enumerate every endpoint.
- [Connection lifecycle](./connection-lifecycle.md) — open,
  reuse, close semantics.
