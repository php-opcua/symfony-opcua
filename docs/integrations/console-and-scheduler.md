---
eyebrow: 'Docs · Integrations'
lede:    'Symfony Console for OPC UA admin tasks, Scheduler for recurring jobs. The Console verbosity flags forwarded into the client logger.'

see_also:
  - { href: '../using-the-client/manager-vs-interface.md', meta: '5 min' }
  - { href: './messenger.md',                              meta: '7 min' }
  - { href: '../observability/logging.md',                 meta: '5 min' }

prev: { label: 'Twig',      href: './twig.md' }
next: { label: 'Notifier',  href: './notifier.md' }
---

# Console and scheduler

Symfony Console is the standard entry point for admin tools,
one-off scripts, and the daemon (`opcua:session`). Symfony
Scheduler runs commands on a cadence.

## A command with OPC UA

<!-- @code-block language="php" label="src/Command/CheckPlcCommand.php" -->
```php
namespace App\Command;

use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:plc:check', description: 'Probe the PLC')]
final class CheckPlcCommand extends Command
{
    public function __construct(private readonly OpcuaManager $opcua)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Forward -v / -vv / -vvv to the OPC UA client logger
        $this->opcua->useConsoleLogger($output);

        try {
            $dv = $this->opcua->connect()->read('i=2259');
        } catch (\Throwable $e) {
            $io->error("Connection failed: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $io->success(sprintf('PLC state: %d (0 = Running)', $dv->getValue()));
        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

`useConsoleLogger()` wraps the `ConsoleLogger` in a
`TimestampedLogger` and applies it to all current and future
connections on the manager.

## Verbosity levels

| Flag       | Logger level visible | Use case                                |
| ---------- | -------------------- | --------------------------------------- |
| (none)     | warning, error       | Default, silent on healthy ops          |
| `-v`        | notice               | "Reconnecting" etc.                     |
| `-vv`       | info                 | Connection events                        |
| `-vvv`      | debug                | Full protocol detail                     |

The mapping follows Symfony's `ConsoleLogger` defaults. Override
the second arg of `useConsoleLogger($output, $verbosityMap)` for
custom mappings.

## Disabling timestamps

<!-- @code-block language="php" label="no timestamp" -->
```php
$this->opcua->useConsoleLogger($output, dateFormat: null);
```
<!-- @endcode-block -->

Useful for cleaner output when running inside another supervisor
that already timestamps lines.

## Long-running commands

For commands that run for hours (subscription watchers, daemons):

<!-- @code-block language="php" label="long-running" -->
```php
#[AsCommand(name: 'app:plc:watch')]
final class WatchPlcCommand extends Command
{
    public function __construct(private OpcuaManager $opcua) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->opcua->connect();

        $sub = $client->subscribe(
            publishingInterval: 500,
            onData: fn($node, $dv) => $output->writeln("$node = {$dv->getValue()}"),
        );
        $sub->monitor('ns=2;s=Speed');

        // Block until Ctrl+C
        $sub->run();

        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Run under systemd / Supervisor — see
[Production supervisor](../session-manager/production-supervisor.md).

## Symfony Scheduler

Symfony Scheduler turns commands into recurring messages.

<!-- @code-block language="bash" label="install" -->
```bash
composer require symfony/scheduler
```
<!-- @endcode-block -->

### Define a schedule

<!-- @code-block language="php" label="src/Scheduler/PlcSchedule.php" -->
```php
namespace App\Scheduler;

use App\Message\SamplePlc;
use Symfony\Component\Console\Messenger\RunCommandMessage;
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
            // Cron-style
            ->add(RecurringMessage::cron('0 3 * * *', new RunCommandMessage('app:plc:discover')))
            // Interval-style
            ->add(RecurringMessage::every('5 minutes', new SamplePlc('ns=2;s=Speed', ['ns=2;s=Speed'])))
            // Symfony Period strings
            ->add(RecurringMessage::every('PT1H',     new RunCommandMessage('app:plc:aggregate-1h')));
    }
}
```
<!-- @endcode-block -->

### Run the scheduler worker

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console messenger:consume scheduler_plc --time-limit=3600
```
<!-- @endcode-block -->

The scheduler dispatches messages at their cron times; messages
go to whichever transport routes them.

## Cron-style — combined

For full plant-floor scheduling:

<!-- @code-block language="php" label="full schedule" -->
```php
#[AsSchedule('plc')]
final class PlcSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            // Every minute
            ->add(RecurringMessage::every('1 minute', new SampleFleet()))
            // Every 5 minutes
            ->add(RecurringMessage::every('5 minutes', new SampleHistorian()))
            // Daily at 03:00
            ->add(RecurringMessage::cron('0 3 * * *', new RunCommandMessage('app:plc:discover')))
            // Daily at 02:00
            ->add(RecurringMessage::cron('0 2 * * *', new RunCommandMessage('app:plc:prune-readings')))
            // Hourly aggregation
            ->add(RecurringMessage::cron('5 * * * *', new RunCommandMessage('app:plc:aggregate-1h')));
    }
}
```
<!-- @endcode-block -->

The "5 minutes past every hour" pattern keeps aggregation from
fighting the on-the-minute sampling.

## Lockable commands

For commands that should never run in parallel:

<!-- @code-block language="bash" label="install lock" -->
```bash
composer require symfony/lock
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="locked command" -->
```php
use Symfony\Component\Console\Command\LockableTrait;

#[AsCommand(name: 'app:plc:prune-readings')]
final class PruneReadingsCommand extends Command
{
    use LockableTrait;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Already running, skipping.');
            return Command::SUCCESS;
        }

        // … do work …

        $this->release();
        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Useful for prune / aggregate jobs that could overlap on slow DBs.

## Command verbosity in production

For systemd-run scheduler workers, pass `-v` only when
debugging:

<!-- @code-block language="text" label="systemd unit" -->
```text
[Service]
ExecStart=/usr/bin/php /var/www/html/bin/console messenger:consume scheduler_plc \
    --time-limit=3600 --memory-limit=512M
```
<!-- @endcode-block -->

Production typically runs at default verbosity (warning/error
only); raise to `-v` for the scheduler if you want "task X
dispatched" lines.

## Interactive vs unattended

The OPC UA bundle's `opcua:trust:add` command interactively
asks for confirmation. For deploy scripts, use `--force`:

<!-- @code-block language="bash" label="non-interactive" -->
```bash
php bin/console opcua:trust:add --force --no-interaction \
    opc.tcp://plc.factory.local:4840
```
<!-- @endcode-block -->

`--no-interaction` is a global Symfony flag — applies to any
command.

## Output styles

For richer output:

<!-- @code-block language="php" label="SymfonyStyle" -->
```php
$io = new SymfonyStyle($input, $output);

$io->title('OPC UA Health Check');
$io->section('Connections');

$io->table(['Name', 'Status'], [
    ['plc-line-a', 'OK'],
    ['plc-line-b', 'TIMEOUT'],
]);

$io->note('Run `app:plc:discover` to refresh tags.');
$io->success('All checks passed');
```
<!-- @endcode-block -->

`SymfonyStyle` is the standard for human-facing output.

## Where to read next

- [Notifier](./notifier.md) — alert routing from commands.
- [Recipes · Production deployment](../recipes/production-deployment.md) —
  the systemd / Scheduler setup.
