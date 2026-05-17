---
eyebrow: 'Docs · Getting started'
lede:    'Symfony bundle for OPC UA — autowireable manager, YAML-driven connections, an artisanal session-manager daemon, and PSR-14 events flowing through the Symfony EventDispatcher.'

see_also:
  - { href: './getting-started/installation.md',           meta: '4 min' }
  - { href: './getting-started/quick-start.md',            meta: '5 min' }
  - { href: './getting-started/how-symfony-opcua-fits.md', meta: '6 min' }

next: { label: 'Installation', href: './getting-started/installation.md' }
---

# Overview

`symfony-opcua` is a Symfony bundle that wires
[`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client)
and
[`php-opcua/opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager)
into the Symfony framework with the idioms Symfony developers
already use:

- **YAML semantic configuration** with a typed `TreeBuilder`.
- **Autowireable services** — `OpcuaManager` and
  `OpcUaClientInterface` injectable anywhere.
- **PSR-3 logging** via Symfony's Monolog channels.
- **PSR-16 caching** via Symfony cache pools.
- **PSR-14 events** routed through Symfony's `EventDispatcher`
  with `#[AsEventListener]` support.
- **`php bin/console opcua:session`** — Symfony Console command
  for the session-manager daemon.

The package targets **Symfony 6.4 LTS, 7.x, and the upcoming
8.x**. Everything autowires by default; nothing requires manual
service definitions.

## What this looks like in a controller

<!-- @code-block language="php" label="src/Controller/PlcController.php" -->
```php
namespace App\Controller;

use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class PlcController extends AbstractController
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    #[Route('/api/plc/speed', methods: ['GET'])]
    public function speed(): JsonResponse
    {
        $dv = $this->opcua->connect()->read('ns=2;s=Speed');

        return $this->json([
            'value' => $dv->getValue(),
            'good'  => $dv->statusCode === 0,
            'at'    => $dv->sourceTimestamp?->format('c'),
        ]);
    }
}
```
<!-- @endcode-block -->

That's a complete, working controller. The bundle handled the
container wiring, the YAML schema, the logger injection, the
cache pool — everything but the business logic.

## Two operating modes

`symfony-opcua` switches transparently between two runtime
shapes:

| Mode    | Backed by                                | When it picks itself                                  |
| ------- | ---------------------------------------- | ----------------------------------------------------- |
| Direct   | `Client` from `opcua-client`             | The session-manager daemon socket isn't reachable     |
| Managed  | `ManagedClient` from `opcua-session-manager` | The daemon socket exists and is responding        |

You don't choose explicitly. The `OpcuaManager` probes the
configured socket (Unix-domain or TCP loopback) and routes
traffic accordingly. **The same controller code works in both
modes.**

See [How symfony-opcua fits](./getting-started/how-symfony-opcua-fits.md).

## The address-space model

OPC UA exposes its data through a **hierarchical address space**
of typed nodes. Every node has a stable `NodeId`. Operations
look up nodes by id:

| Operation     | Wire service           | API method              |
| ------------- | ---------------------- | ----------------------- |
| Read value    | `Read`                  | `$client->read('ns=2;s=Speed')` |
| Write value   | `Write`                 | `$client->write('ns=2;s=Setpoint', 75.0)` |
| Browse        | `Browse`                | `$client->browse('ns=0;i=85')` |
| Invoke method | `Call`                  | `$client->callMethod($obj, $method, $args)` |
| Subscribe    | `CreateSubscription` + `CreateMonitoredItems` | `$client->subscribe(...)` |
| History       | `HistoryRead`           | `$client->historyBuilder()->...` |

The full surface — including `addNodes` / `deleteNodes` — is
covered under [Operations](./operations/reading.md).

## Symfony-side wiring at a glance

| Symfony component        | What `symfony-opcua` does with it                          |
| ------------------------ | ---------------------------------------------------------- |
| `framework-bundle`       | Bundle auto-discovered via Flex                            |
| `dependency-injection`   | `OpcuaManager` + `OpcUaClientInterface` autowireable        |
| `config`                 | YAML `TreeBuilder` validates `php_opcua_symfony_opcua.*`    |
| `console`                | `opcua:session` runs the daemon                             |
| `cache`                  | Symfony cache pool wrapped as PSR-16 and injected           |
| `event-dispatcher`       | PSR-14 events from the client dispatched as Symfony events |
| Monolog (via `monolog-bundle`) | Logger injected; per-connection `log_channel` supported |
| Secrets vault            | `%env(secret:OPCUA_PASSWORD)%` works in YAML                |
| Messenger (optional)     | Long-running listeners → async transports                   |
| Mercure (optional)       | Real-time push to the browser                                |
| Profiler (optional)      | OPC UA calls visible in the WebProfilerBundle               |

You only opt into what you need — the bundle's runtime cost is a
single autowired service plus the connection cache.

## How tests look

<!-- @code-block language="php" label="tests/Functional/PlcControllerTest.php" -->
```php
namespace App\Tests\Functional;

use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlcControllerTest extends WebTestCase
{
    public function testReadSpeed(): void
    {
        $client = static::createClient();

        $mock = MockClient::create()
            ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

        static::getContainer()->set(OpcuaManager::class, new class($mock) extends OpcuaManager {
            public function __construct(private MockClient $m) { parent::__construct(['default' => 'd', 'connections' => ['d' => []]]); }
            public function connect(?string $name = null): \PhpOpcua\Client\OpcUaClientInterface { return $this->m; }
        });

        $client->request('GET', '/api/plc/speed');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode(['value' => 75.0, 'good' => true, 'at' => null]),
            $client->getResponse()->getContent(),
        );
    }
}
```
<!-- @endcode-block -->

Full test patterns under [Testing](./testing/phpunit-and-pest-setup.md).

## Where to read next

- [Installation](./getting-started/installation.md) — get the
  bundle running.
- [Quick start](./getting-started/quick-start.md) — first
  connection and command examples.
- [How symfony-opcua fits](./getting-started/how-symfony-opcua-fits.md)
  — the architecture in one diagram.
