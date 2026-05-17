---
eyebrow: 'Docs · Getting started'
lede:    'Four idiomatic Symfony shapes — controller, console command, Messenger handler, scheduled task — each exercising the bundle differently.'

see_also:
  - { href: '../using-the-client/manager-vs-interface.md',  meta: '5 min' }
  - { href: '../operations/reading.md',                     meta: '6 min' }
  - { href: '../integrations/messenger.md',                  meta: '6 min' }

prev: { label: 'Installation',  href: './installation.md' }
next: { label: 'How symfony-opcua fits', href: './how-symfony-opcua-fits.md' }
---

# Quick start

Four shapes a typical Symfony app reaches for first. Each one
covers a different runtime context.

Assumed `.env`:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_ENDPOINT=opc.tcp://127.0.0.1:4840
```
<!-- @endcode-block -->

Assumed `config/packages/php_opcua_symfony_opcua.yaml`:

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
```
<!-- @endcode-block -->

## 1. HTTP controller

The most common entry point. `OpcuaManager` is autowired into
the constructor.

<!-- @code-block language="php" label="src/Controller/SpeedController.php" -->
```php
namespace App\Controller;

use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class SpeedController extends AbstractController
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    #[Route('/api/plc/speed', methods: ['GET'])]
    public function show(): JsonResponse
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

Hit it:

<!-- @code-block language="bash" label="terminal" -->
```bash
curl http://localhost:8000/api/plc/speed
```
<!-- @endcode-block -->

## 2. Console command

For one-shot scripts, scheduled tasks, or admin tooling.

<!-- @code-block language="php" label="src/Command/CheckPlcCommand.php" -->
```php
namespace App\Command;

use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:check-plc', description: 'Probe the PLC and print the Server state')]
class CheckPlcCommand extends Command
{
    public function __construct(private readonly OpcuaManager $opcua)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $client = $this->opcua->connect();
        $state  = $client->read('i=2259')->getValue();      // Server_ServerStatus_State

        $io->success("PLC reports state: {$state} (0 = Running)");

        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Run:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console app:check-plc
```
<!-- @endcode-block -->

Pair with `useConsoleLogger()` for `-v` / `-vv` / `-vvv` to
flow into the OPC UA client's logger — see
[Observability · Logging](../observability/logging.md).

## 3. Messenger handler

For work that shouldn't block a request. The message holds the
**input**; the handler resolves `OpcuaManager` from the
container.

<!-- @code-block language="php" label="src/Message/SamplePlc.php" -->
```php
namespace App\Message;

final readonly class SamplePlc
{
    public function __construct(public string $nodeId) {}
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="src/MessageHandler/SamplePlcHandler.php" -->
```php
namespace App\MessageHandler;

use App\Message\SamplePlc;
use App\Repository\PlcReadingRepository;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SamplePlcHandler
{
    public function __construct(
        private readonly OpcuaManager $opcua,
        private readonly PlcReadingRepository $repo,
    ) {}

    public function __invoke(SamplePlc $message): void
    {
        $dv = $this->opcua->connect()->read($message->nodeId);

        $this->repo->save([
            'node_id' => $message->nodeId,
            'value'   => $dv->getValue(),
            'good'    => $dv->statusCode === 0,
            'at'      => $dv->sourceTimestamp ?? new \DateTimeImmutable(),
        ]);
    }
}
```
<!-- @endcode-block -->

Dispatch from anywhere:

<!-- @code-block language="php" label="dispatching" -->
```php
use App\Message\SamplePlc;
use Symfony\Component\Messenger\MessageBusInterface;

$bus->dispatch(new SamplePlc('ns=2;s=Speed'));
```
<!-- @endcode-block -->

See [Integrations · Messenger](../integrations/messenger.md) for
async transports, retries, and the deeper async pattern.

## 4. Scheduled task

Run the command on a cron-like schedule using Symfony Scheduler:

<!-- @code-block language="php" label="src/Scheduler/PlcSchedule.php" -->
```php
namespace App\Scheduler;

use App\Message\SamplePlc;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('plc')]
final class PlcSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 minute', new SamplePlc('ns=2;s=Speed')))
            ->add(RecurringMessage::every('5 minutes', new SamplePlc('ns=2;s=Temperature')));
    }
}
```
<!-- @endcode-block -->

Run the scheduler worker:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console messenger:consume scheduler_plc
```
<!-- @endcode-block -->

Every minute a `SamplePlc` message is dispatched and the handler
above picks it up. See [Console and scheduler](../integrations/console-and-scheduler.md).

## Two-line write

For completeness — writing is just as terse:

<!-- @code-block language="php" label="write" -->
```php
$this->opcua->connect()->write('ns=2;s=Setpoint', 75.0);
```
<!-- @endcode-block -->

The package auto-detects the OPC UA type from the PHP value.
See [Operations · Writing](../operations/writing.md) for the
explicit-type path.

## A subscription

A subscription delivers value changes via the EventDispatcher
(in managed mode with auto-publish enabled — see
[Session manager · Auto-publish](../session-manager/auto-publish.md)).

<!-- @code-block language="php" label="src/EventListener/StoreSpeedReading.php" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class StoreSpeedReading
{
    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        if ($event->nodeId === 'ns=2;s=Speed') {
            // ... persist
        }
    }
}
```
<!-- @endcode-block -->

See [Events · Overview](../events/overview.md).

## Where to read next

- [How symfony-opcua fits](./how-symfony-opcua-fits.md) — the
  architecture diagram.
- [Manager vs interface](../using-the-client/manager-vs-interface.md) —
  picking the right injection type.
- [Operations · Reading](../operations/reading.md) — read
  patterns and error handling.
