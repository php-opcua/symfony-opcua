---
eyebrow: 'Docs · Testing'
lede:    'KernelTestCase + WebTestCase + integration tests — the Symfony test-pack idioms applied to OPC UA-driven apps.'

see_also:
  - { href: './phpunit-and-pest-setup.md',  meta: '5 min' }
  - { href: './mocking-the-manager.md',     meta: '5 min' }
  - { href: '../recipes/dev-with-docker.md', meta: '6 min' }

prev: { label: 'Using MockClient',   href: './using-mock-client.md' }
next: { label: 'Messenger',          href: '../integrations/messenger.md' }
---

# Kernel tests

For tests that need the Symfony container — controllers,
services, Messenger handlers — use Symfony's
`KernelTestCase` / `WebTestCase`.

## `KernelTestCase` — without HTTP

For testing services that depend on the container:

<!-- @code-block language="php" label="tests/Functional/SpeedServiceTest.php" -->
```php
namespace App\Tests\Functional;

use App\Service\SpeedService;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SpeedServiceTest extends KernelTestCase
{
    public function testCurrent(): void
    {
        self::bootKernel();

        $mock = MockClient::create()
            ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

        static::getContainer()->set(OpcUaClientInterface::class, $mock);

        $service = static::getContainer()->get(SpeedService::class);

        $this->assertSame(75.0, $service->current());
    }
}
```
<!-- @endcode-block -->

`bootKernel()` initialises the Symfony kernel; the container is
available via `getContainer()`.

## `WebTestCase` — with HTTP

For controllers:

<!-- @code-block language="php" label="tests/Functional/SpeedControllerTest.php" -->
```php
namespace App\Tests\Functional;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SpeedControllerTest extends WebTestCase
{
    public function testShow(): void
    {
        $client = static::createClient();

        $mock = MockClient::create()
            ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));
        static::getContainer()->set(OpcUaClientInterface::class, $mock);

        $client->request('GET', '/api/plc/speed');

        $this->assertResponseIsSuccessful();
        $this->assertJsonStringEqualsJsonString(
            json_encode(['value' => 75.0, 'good' => true, 'at' => null]),
            $client->getResponse()->getContent(),
        );
    }
}
```
<!-- @endcode-block -->

`createClient()` boots the kernel **and** returns a
`KernelBrowser` for HTTP requests.

## Testing Messenger handlers

Symfony Messenger's `sync` transport is perfect for tests —
messages are handled synchronously.

`config/packages/test/messenger.yaml`:

<!-- @code-block language="text" label="test transport" -->
```text
framework:
    messenger:
        transports:
            async_opcua: 'sync://'
```
<!-- @endcode-block -->

Test:

<!-- @code-block language="php" label="handler test" -->
```php
namespace App\Tests\Functional;

use App\Entity\PlcReading;
use App\Message\StoreReading;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class StoreReadingHandlerTest extends KernelTestCase
{
    public function testStoresReading(): void
    {
        self::bootKernel();

        $bus = static::getContainer()->get(MessageBusInterface::class);
        $em  = static::getContainer()->get(EntityManagerInterface::class);

        $bus->dispatch(new StoreReading(
            connection: 'default',
            nodeId:     'ns=2;s=Speed',
            value:      75.0,
            statusCode: 0,
            at:         new \DateTimeImmutable(),
        ));

        $readings = $em->getRepository(PlcReading::class)->findAll();
        $this->assertCount(1, $readings);
        $this->assertSame(75.0, $readings[0]->getValue());
    }
}
```
<!-- @endcode-block -->

`sync://` runs handlers in the same process, so the
`assertCount` works without async polling.

## Testing event listeners

For listeners that dispatch on `DataChangeReceived`:

<!-- @code-block language="php" label="listener test" -->
```php
namespace App\Tests\Functional;

use App\EventListener\StoreSpeedReading;
use PhpOpcua\Client\Events\DataChangeReceived;
use PhpOpcua\Client\Types\DataValue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StoreSpeedReadingTest extends KernelTestCase
{
    public function testOnDataChange(): void
    {
        self::bootKernel();

        $listener = static::getContainer()->get(StoreSpeedReading::class);

        $event = new DataChangeReceived(
            connection:     'default',
            nodeId:         'ns=2;s=Speed',
            dataValue:      DataValue::ofDouble(75.0),
            clientHandle:   1,
            subscriptionId: 1,
        );

        $listener($event);

        // Assert on the side-effect (DB row, broadcast, etc.)
    }
}
```
<!-- @endcode-block -->

Direct invocation is cleaner than dispatching through the
EventDispatcher, but the latter works too:

<!-- @code-block language="php" label="via dispatcher" -->
```php
$dispatcher = static::getContainer()->get('event_dispatcher');
$dispatcher->dispatch($event);
```
<!-- @endcode-block -->

## Integration tests against a real server

<!-- @code-block language="php" label="tests/Integration/PlcReadTest.php" -->
```php
namespace App\Tests\Integration;

use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlcReadTest extends KernelTestCase
{
    public function testReadServerStatus(): void
    {
        self::bootKernel();

        $opcua = static::getContainer()->get(OpcuaManager::class);
        $dv = $opcua->connect()->read('i=2256');

        $this->assertSame(0, $dv->statusCode);
        $this->assertSame(0, $dv->getValue());   // 0 = Running
    }
}
```
<!-- @endcode-block -->

Pair with a Docker `php-opcua/uanetstandard-test-suite` test
server — see [Recipes · Dev with Docker](../recipes/dev-with-docker.md).

## Doctrine in functional tests

Reset the DB per test with `dama/doctrine-test-bundle`:

<!-- @code-block language="text" label="composer" -->
```text
composer require --dev dama/doctrine-test-bundle
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="phpunit.dist.xml" -->
```text
<extensions>
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
</extensions>
```
<!-- @endcode-block -->

Each test gets a transaction-rollback-wrapped DB — no data
leaks between tests.

## Multiple containers — symfony-opcua bundle test

If you're testing the **bundle itself** (not an app using it),
boot a small test kernel:

<!-- @code-block language="php" label="bundle test kernel" -->
```php
namespace PhpOpcua\SymfonyOpcua\Tests;

use PhpOpcua\SymfonyOpcua\PhpOpcuaSymfonyOpcuaBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new PhpOpcuaSymfonyOpcuaBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__ . '/fixtures/config.yaml');
    }
}
```
<!-- @endcode-block -->

…with `fixtures/config.yaml`:

<!-- @code-block language="text" label="config" -->
```text
framework:
    secret: test

php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: 'opc.tcp://localhost:14840'
```
<!-- @endcode-block -->

Boot in tests:

<!-- @code-block language="php" label="boot the test kernel" -->
```php
$kernel = new TestKernel('test', false);
$kernel->boot();
$container = $kernel->getContainer();
```
<!-- @endcode-block -->

## CI matrix

See [PHPUnit and Pest setup](./phpunit-and-pest-setup.md) for
the GitHub Actions matrix.

## Performance

| Test type     | Per-test budget |
| ------------- | --------------- |
| Unit           | < 50 ms         |
| Functional     | < 500 ms        |
| Integration    | < 5 s           |

`createClient()` is the slow part of functional tests — reuse
the booted kernel where possible.

## Where to read next

You've finished **Testing**. Next:
[Integrations · Messenger](../integrations/messenger.md) for
async patterns.
