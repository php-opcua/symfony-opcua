---
eyebrow: 'Docs · Recipes'
lede:    'Load OPC UA companion specifications via opcua-client-nodeset for type-aware browsing — MachineTool, PackML, DI, and friends.'

see_also:
  - { href: 'https://github.com/php-opcua/opcua-client-nodeset', meta: 'external', label: 'opcua-client-nodeset' }
  - { href: '../operations/browsing.md',                         meta: '5 min' }

prev: { label: 'Multi-plant tenant',  href: './multi-plant-tenant.md' }
next: { label: 'Dev with Docker',     href: './dev-with-docker.md' }
---

# Using companion specs

OPC UA companion specifications define **typed** node
hierarchies for specific industries: MachineTool, PackML,
Robotics, DI (Device Information). The `opcua-client-nodeset`
package gives type-aware access — and the Symfony bundle picks
it up automatically.

## Install

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/opcua-client-nodeset
```
<!-- @endcode-block -->

The package's discovery mechanism auto-registers nodesets from
its bundled directory. No additional config.

## Without companion specs — raw browse

<!-- @code-block language="php" label="raw" -->
```php
$nodes = $this->client->browseRecursive('ns=4;s=MachineTool', maxDepth: 5);
// array of ReferenceDescription — generic
```
<!-- @endcode-block -->

## With companion specs — typed

<!-- @code-block language="php" label="typed" -->
```php
use PhpOpcua\Client\Nodeset\MachineTool\MachineToolType;

$machine = $this->client->nodeset(MachineToolType::class, 'ns=4;s=MachineTool');

$alarms     = $machine->getAlarms();         // array of MachineToolAlarm
$production = $machine->getProduction();      // ProductionType
$equipment  = $machine->getEquipment();        // ToolListType

// No string-fiddling, no walking the address space
```
<!-- @endcode-block -->

The PHP classes correspond to the spec's ObjectType hierarchy.

## Available specs

| Companion spec | PHP namespace                                  | Use case                          |
| -------------- | ---------------------------------------------- | --------------------------------- |
| DI             | `PhpOpcua\Client\Nodeset\Di\`                  | Generic device information         |
| MachineTool    | `PhpOpcua\Client\Nodeset\MachineTool\`         | CNCs, lathes, mills                |
| Robotics       | `PhpOpcua\Client\Nodeset\Robotics\`            | Industrial robots                  |
| PackML         | `PhpOpcua\Client\Nodeset\PackML\`              | Packaging machinery                |
| Machinery      | `PhpOpcua\Client\Nodeset\Machinery\`           | Generic industrial machinery       |

Plus several more — see the
[opcua-client-nodeset README](https://github.com/php-opcua/opcua-client-nodeset).

## End-to-end — MachineTool production monitor

<!-- @code-block language="php" label="src/Entity/MachineToolReading.php" -->
```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class MachineToolReading
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    public string $machineId;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    public ?string $activeProgram = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    public ?string $activeTool = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    public ?string $operationMode = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $partCount = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $readAt;
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="src/Command/PollMachineToolCommand.php" -->
```php
namespace App\Command;

use App\Entity\MachineToolReading;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Nodeset\MachineTool\MachineToolType;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:machine:poll')]
final class PollMachineToolCommand extends Command
{
    public function __construct(
        private OpcuaManager $opcua,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('machine-id', InputArgument::REQUIRED, 'MachineTool root NodeId');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('machine-id');
        $machine = $this->opcua->connect()->nodeset(MachineToolType::class, $id);

        $production = $machine->getProduction();

        $reading = (new MachineToolReading())
            ->setMachineId($id)
            ->setActiveProgram($production->getActiveProgram()?->getName()->value)
            ->setActiveTool($production->getActiveTool()?->getName()->value)
            ->setOperationMode($production->getOperationMode()->value)
            ->setPartCount((int) $production->getPartCount()->value)
            ->setReadAt(new \DateTimeImmutable());

        $this->em->persist($reading);
        $this->em->flush();

        $output->writeln("Recorded {$reading->id}");
        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Schedule every minute via Symfony Scheduler.

## Type discovery

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console debug:autowiring nodeset
```
<!-- @endcode-block -->

…or in tinker-style:

<!-- @code-block language="php" label="reflection" -->
```php
$methods = get_class_methods(PhpOpcua\Client\Nodeset\MachineTool\MachineToolType::class);
print_r($methods);
```
<!-- @endcode-block -->

The classes are generated with rich docblocks listing every
typed property.

## Type-narrowing on alarms

<!-- @code-block language="php" label="typed alarms" -->
```php
use PhpOpcua\Client\Nodeset\MachineTool\AxisAlarm;
use PhpOpcua\Client\Nodeset\MachineTool\MachineToolAlarm;

foreach ($machine->getAlarms() as $alarm) {
    if ($alarm instanceof AxisAlarm) {
        echo "Axis {$alarm->getAxisId()->value}: {$alarm->getMessage()->value}\n";
    }
}
```
<!-- @endcode-block -->

`instanceof` narrowing lets you handle subtypes specifically.

## Subscribing to typed events

The subscription side uses the raw `subscribe()` API; the
listener decodes with `opcua-client-nodeset` helpers:

<!-- @code-block language="php" label="typed event listener" -->
```php
namespace App\EventListener;

use App\Entity\AxisAlarm as AxisAlarmEntity;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Events\EventNotificationReceived;
use PhpOpcua\Client\Nodeset\MachineTool\AlarmDecoder;
use PhpOpcua\Client\Nodeset\MachineTool\AxisAlarm;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class HandleMachineToolAlarm
{
    public function __construct(private EntityManagerInterface $em) {}

    #[AsEventListener]
    public function __invoke(EventNotificationReceived $event): void
    {
        if (!$event->eventType) return;

        $alarm = AlarmDecoder::decode($event);

        if ($alarm instanceof AxisAlarm) {
            $entity = (new AxisAlarmEntity())
                ->setMachineId($event->connection)
                ->setAxisId($alarm->axisId)
                ->setMessage($alarm->message)
                ->setSeverity($event->severity);

            $this->em->persist($entity);
            $this->em->flush();
        }
    }
}
```
<!-- @endcode-block -->

## Custom companion specs

For internal / proprietary types:

<!-- @code-block language="php" label="src/Opcua/Nodeset/Acme/AcmeReactorType.php" -->
```php
namespace App\Opcua\Nodeset\Acme;

use PhpOpcua\Client\Nodeset\BaseNodesetType;
use PhpOpcua\Client\Types\DataValue;

class AcmeReactorType extends BaseNodesetType
{
    public function getTemperature(): ?DataValue
    {
        return $this->readChild('Temperature');
    }

    public function getPressure(): ?DataValue
    {
        return $this->readChild('Pressure');
    }

    public function getState(): string
    {
        return (string) $this->readChild('State')->value;
    }
}
```
<!-- @endcode-block -->

Use identically:

<!-- @code-block language="php" label="usage" -->
```php
$reactor = $this->client->nodeset(
    \App\Opcua\Nodeset\Acme\AcmeReactorType::class,
    'ns=2;s=Reactor1',
);

echo $reactor->getTemperature()->value;
```
<!-- @endcode-block -->

## Trade-off

| Approach          | Pros                                            | Cons                                                    |
| ----------------- | ----------------------------------------------- | ------------------------------------------------------- |
| Raw browse / read | Universal — works against any OPC UA server     | String-fiddling, no type safety                          |
| Companion specs   | Type-safe, IDE auto-complete, idiomatic         | Only works against spec-conformant servers              |

If your servers conform (Siemens, Beckhoff, Rockwell), specs
are dramatically nicer. If bespoke, raw is fine.

## Performance

A typed accessor reads the underlying node lazily.
`$machine->getProduction()` is one round-trip;
`$production->getActiveProgram()` is another. For multi-property
reads, the typed API hides batching — internally uses
`executeMany()`.

For known property sets, snapshot:

<!-- @code-block language="php" label="snapshot" -->
```php
$snapshot = $machine->snapshot([
    'production.active_program',
    'production.active_tool',
    'production.operation_mode',
    'production.part_count',
]);
// One round-trip; $snapshot is an array of resolved values
```
<!-- @endcode-block -->

## Where to read next

- [opcua-client-nodeset README](https://github.com/php-opcua/opcua-client-nodeset) —
  the canonical reference.
- [Production deployment](./production-deployment.md) — shipping.
