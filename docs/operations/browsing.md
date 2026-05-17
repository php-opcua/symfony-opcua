---
eyebrow: 'Docs · Operations'
lede:    'Walking the OPC UA address space. Flat browse, recursive browse, filtering — and pragmatic Symfony patterns for tag discovery on schedule.'

see_also:
  - { href: './reading.md',                          meta: '6 min' }
  - { href: '../recipes/persistent-tag-history.md',  meta: '7 min' }

prev: { label: 'Writing',       href: './writing.md' }
next: { label: 'Method calls',  href: './method-calls.md' }
---

# Browsing

OPC UA models the device as a graph of typed nodes connected by
references. Browsing is how you discover what's available.

## Flat browse

<!-- @code-block language="php" label="basic browse" -->
```php
$nodes = $this->client->browse('ns=0;i=85');  // Objects folder
foreach ($nodes as $node) {
    echo "{$node->browseName} → {$node->nodeId}\n";
}
```
<!-- @endcode-block -->

Each entry is a `ReferenceDescription`:

| Field             | Meaning                                      |
| ----------------- | -------------------------------------------- |
| `nodeId`           | OPC UA NodeId of the target                  |
| `browseName`       | Programmatic name                            |
| `displayName`      | Human-readable label                          |
| `nodeClass`        | Object / Variable / Method / View / ...      |
| `referenceType`    | The reference linking source → target         |
| `typeDefinition`   | Type of the target                            |

## Recursive browse

<!-- @code-block language="php" label="recursive" -->
```php
$tree = $this->client->browseRecursive('ns=4;s=Tags', maxDepth: 5);

foreach ($tree as $entry) {
    echo str_repeat('  ', $entry->depth) . $entry->browseName . "\n";
}
```
<!-- @endcode-block -->

Default `maxDepth` is 10. Higher for deep address spaces.

## Filtered browse

<!-- @code-block language="php" label="filtered" -->
```php
$variables = $this->client->browseBuilder()
    ->from('ns=2;s=Folder')
    ->referenceType('Organizes')   // direct children
    ->nodeClass('Variable')        // only variables
    ->execute();
```
<!-- @endcode-block -->

Common reference types:

| Reference type    | Meaning                                       |
| ----------------- | --------------------------------------------- |
| `Organizes`        | Folder ↔ children                              |
| `HasComponent`     | Composition (object has parts)                |
| `HasProperty`      | Variable property attached to a node          |
| `HasTypeDefinition` | Instance ↔ its type                          |
| `HasSubtype`       | Type hierarchy                                |

For user-facing tag browsing, `Organizes` + `Variable` gives the
cleanest list.

## Discovery on schedule — fill a Doctrine table

Browse once a day, persist to `plc_tags`:

<!-- @code-block language="php" label="src/Command/DiscoverTagsCommand.php" -->
```php
namespace App\Command;

use App\Entity\PlcTag;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:plc:discover')]
final class DiscoverTagsCommand extends Command
{
    public function __construct(
        private OpcuaManager $opcua,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('connection', InputArgument::OPTIONAL, '', 'default')
            ->addArgument('root',       InputArgument::OPTIONAL, '', 'ns=4;s=Tags');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tree = $this->opcua->connect($input->getArgument('connection'))
            ->browseRecursive($input->getArgument('root'), maxDepth: 10);

        $output->writeln('Found ' . count($tree) . ' nodes');

        foreach ($tree as $entry) {
            if ($entry->nodeClass !== NodeClass::Variable) continue;

            $tag = $this->em->getRepository(PlcTag::class)
                ->findOneBy(['nodeId' => $entry->nodeId])
                ?? new PlcTag();

            $tag->setNodeId($entry->nodeId);
            $tag->setBrowseName($entry->browseName);
            $tag->setDisplayName($entry->displayName);
            $tag->setParentNodeId($entry->parentNodeId);
            $tag->setLastSeenAt(new \DateTimeImmutable());

            $this->em->persist($tag);
        }

        $this->em->flush();
        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Run on a schedule via Symfony Scheduler:

<!-- @code-block language="php" label="src/Scheduler/PlcSchedule.php" -->
```php
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;

#[AsSchedule('plc')]
final class PlcSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::cron('0 3 * * *', new RunCommandMessage('app:plc:discover')));
    }
}
```
<!-- @endcode-block -->

Discovery runs at 03:00 daily.

## Listing only writable nodes

A UI need: show the operator which tags are writable.

<!-- @code-block language="php" label="writable filter" -->
```php
use PhpOpcua\Client\Types\AttributeId;

$variables = $this->client->browseBuilder()
    ->from('ns=2;s=Folder')
    ->nodeClass('Variable')
    ->execute();

$writable = [];
foreach ($variables as $node) {
    $access = $this->client->readBuilder()
        ->node($node->nodeId)
        ->attribute(AttributeId::AccessLevel)
        ->execute();

    if (($access->getValue() & 0b10) !== 0) {   // CurrentWrite bit
        $writable[] = $node;
    }
}
```
<!-- @endcode-block -->

`AccessLevel` bits:

| Bit | Name                |
| --- | ------------------- |
| 0   | CurrentRead          |
| 1   | CurrentWrite         |
| 2   | HistoryRead          |
| 3   | HistoryWrite         |
| 4   | SemanticChange       |
| 5   | StatusWrite          |
| 6   | TimestampWrite       |

## Reverse browse

Walk upward from a node to its parents — useful for breadcrumbs:

<!-- @code-block language="php" label="inverse browse" -->
```php
$parents = $this->client->browseBuilder()
    ->from('ns=2;s=Speed')
    ->direction('Inverse')
    ->execute();
```
<!-- @endcode-block -->

## Translate browse paths

Server-side resolution of a browse path string to a NodeId:

<!-- @code-block language="php" label="translate" -->
```php
$nodeId = $this->client->translateBrowsePath('ns=2;s=Folder', '/Subfolder/Speed');
// → 'ns=2;s=Folder.Subfolder.Speed'
```
<!-- @endcode-block -->

## Performance

| Scope                          | Round-trips                                   |
| ------------------------------ | --------------------------------------------- |
| Flat browse, ~10 children      | 1                                              |
| Filtered browse                 | 1                                              |
| Recursive ~100 nodes            | 5-10 (chunked at `MaxNodesPerBrowse`)         |
| Whole address space             | Don't do this from a request — schedule it    |

Don't browse on every request. Discovery is a periodic activity;
results live in your Doctrine schema or Symfony cache.

## Caching browse results

If you must browse live (operator-driven address-space
exploration), cache aggressively:

<!-- @code-block language="php" label="cached browse" -->
```php
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

public function children(string $nodeId): array
{
    return $this->cache->get(
        'opcua.browse.' . hash('xxh3', $nodeId),
        function (ItemInterface $item) use ($nodeId) {
            $item->expiresAfter(3600);   // 1 hour
            return $this->client->browse($nodeId);
        },
    );
}
```
<!-- @endcode-block -->

PLC tag structure changes on the order of weeks — cache for
hours, not seconds.

## Browsing via companion-spec types

If `opcua-client-nodeset` is installed (see
[Recipes · Using companion specs](../recipes/using-companion-specs.md)),
type-aware browsing becomes available — strongly-typed accessors
on `MachineToolType`, `PackMLType`, etc.

## Where to read next

- [Method calls](./method-calls.md) — invoking methods on
  browsed objects.
- [Recipes · Using companion specs](../recipes/using-companion-specs.md) —
  typed browse.
