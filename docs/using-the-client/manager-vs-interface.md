---
eyebrow: 'Docs · Using the client'
lede:    'Inject OpcuaManager or OpcUaClientInterface? Both autowire. Choose by ergonomics — and by what your tests need to do.'

see_also:
  - { href: './named-connections.md',                meta: '5 min' }
  - { href: './connection-lifecycle.md',             meta: '5 min' }
  - { href: '../testing/mocking-the-manager.md',     meta: '5 min' }

prev: { label: 'Parameters and overrides',  href: '../configuration/parameters-and-overrides.md' }
next: { label: 'Named connections',         href: './named-connections.md' }
---

# Manager vs interface

The bundle exposes two autowireable types:

| Type                                  | What you get                                              |
| ------------------------------------- | --------------------------------------------------------- |
| `PhpOpcua\SymfonyOpcua\OpcuaManager`    | The manager — switch connections, open ad-hoc, lifecycle  |
| `PhpOpcua\Client\OpcUaClientInterface`  | A connected client for the **default** connection         |

Both are wired by `PhpOpcuaSymfonyOpcuaBundle`. The choice is
about who reads the code.

## The manager — full control

<!-- @code-block language="php" label="manager injection" -->
```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

final class TagsController
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    public function show(): JsonResponse
    {
        $client = $this->opcua->connect();              // default
        $dv = $client->read('ns=2;s=Speed');

        $line2 = $this->opcua->connect('plc-line-b');  // named
        $dv2 = $line2->read('ns=2;s=Speed');

        return new JsonResponse([
            'lineA' => $dv->getValue(),
            'lineB' => $dv2->getValue(),
        ]);
    }
}
```
<!-- @endcode-block -->

Pros:

- Switch between named connections.
- Open ad-hoc connections (`connectTo`).
- Call `disconnect()` / `disconnectAll()` explicitly.
- Override the logger at runtime (`setLogger`, `useConsoleLogger`).
- Probe `isSessionManagerRunning()`.

Use the manager when you need any of those.

## The client — direct injection

<!-- @code-block language="php" label="client injection" -->
```php
use PhpOpcua\Client\OpcUaClientInterface;

final class TagsController
{
    public function __construct(private readonly OpcUaClientInterface $client) {}

    public function show(): JsonResponse
    {
        $dv = $this->client->read('ns=2;s=Speed');
        return new JsonResponse(['value' => $dv->getValue()]);
    }
}
```
<!-- @endcode-block -->

Pros:

- More concise — no `->connect()` step.
- The constructor signature explicitly declares the OPC UA
  dependency.
- Cleaner mocks in tests.

Cons:

- Only the **default** connection is reachable.
- No `connectTo()`.

Use the client interface when you talk to one server.

## Same instance under the hood

`OpcUaClientInterface` is wired as a **factory service** that
calls `$manager->connection()` (which is `connect()` with no
arg). Same instance, different injection name:

<!-- @code-block language="text" label="container wiring (simplified)" -->
```text
services:
    PhpOpcua\Client\OpcUaClientInterface:
        factory: ['@PhpOpcua\SymfonyOpcua\OpcuaManager', 'connection']
        arguments: [null]
```
<!-- @endcode-block -->

Injecting both into the same class works — both resolve to the
same connection object for the default connection.

## When to pick which

| Code shape                                     | Pick                            |
| ---------------------------------------------- | ------------------------------- |
| Controller, 1-2 reads/writes, one PLC          | `OpcUaClientInterface`           |
| Controller / service that switches connections  | `OpcuaManager`                  |
| Service that creates ad-hoc connections         | `OpcuaManager` (`connectTo()`)  |
| Test target, single connection                  | Either                          |
| Console command                                  | `OpcuaManager` (often needs lifecycle hooks like `useConsoleLogger`) |
| Messenger handler                                | Resolved at `__invoke` time     |

## Console command pattern

Symfony commands instantiate via the container, so constructor
injection just works:

<!-- @code-block language="php" label="src/Command/CheckCommand.php" -->
```php
namespace App\Command;

use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:opcua:check')]
final class CheckCommand extends Command
{
    public function __construct(private readonly OpcuaManager $opcua)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Forward -v/-vv/-vvv to the OPC UA client's logger
        $this->opcua->useConsoleLogger($output);

        $state = $this->opcua->connect()->read('i=2259')->getValue();
        $output->writeln("State: {$state}");

        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

`useConsoleLogger()` is one of the lifecycle helpers that justify
choosing `OpcuaManager` over `OpcUaClientInterface` in commands.

## Messenger handler pattern

Messenger resolves dependencies via constructor injection per
message:

<!-- @code-block language="php" label="MessageHandler" -->
```php
namespace App\MessageHandler;

use App\Message\SamplePlc;
use PhpOpcua\Client\OpcUaClientInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SamplePlcHandler
{
    public function __construct(private readonly OpcUaClientInterface $client) {}

    public function __invoke(SamplePlc $message): void
    {
        $dv = $this->client->read($message->nodeId);
        // ... persist
    }
}
```
<!-- @endcode-block -->

If the handler reads from multiple connections, inject the
manager instead.

## EventListener pattern

For PSR-14 events emitted by the OPC UA client (subscription
notifications etc.), listeners get the event injected — they
typically don't need to talk back to the client. When they do,
inject either type:

<!-- @code-block language="php" label="EventListener" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\DataChangeReceived;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class HandleDataChange
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        // Use $this->opcua to acknowledge, write back, etc.
    }
}
```
<!-- @endcode-block -->

## Type-safety with PHPStan / Psalm

`OpcUaClientInterface` exposes a fully typed surface — every
method has a return type. Static analyzers resolve
`$client->read('ns=2;s=Speed')` to `DataValue` without extra
helpers.

`OpcuaManager` similarly has typed return values on `connect()`,
`connection()`, `connectTo()`. The dynamic `__call` proxy
forwards to the underlying client.

## Long-running workers (Messenger / scheduler)

Both injection types resolve **fresh per-message** under typical
Messenger transport configs. The manager's connection cache
persists for the lifetime of the worker process — same
connection reused across many messages, dropped on worker
restart. See [Connection lifecycle](./connection-lifecycle.md).

## Mixed style is fine

A typical codebase has:

- Controllers injecting `OpcUaClientInterface` (single
  connection).
- Services that fan across connections injecting `OpcuaManager`.
- Messenger handlers injecting `OpcUaClientInterface` per
  message.
- Console commands injecting `OpcuaManager` for
  `useConsoleLogger`.

There's no rule. Optimise for the reader of each class.

## Where to read next

- [Named connections](./named-connections.md) — switching by name.
- [Ad-hoc connections](./ad-hoc-connections.md) — when YAML isn't
  enough.
- [Mocking the manager](../testing/mocking-the-manager.md) — the
  testing surface for both injection types.
