---
eyebrow: 'Docs · Testing'
lede:    'Replace OpcuaManager / OpcUaClientInterface in the Symfony container per test. Mockery and Prophecy patterns; container::set; container::override.'

see_also:
  - { href: './phpunit-and-pest-setup.md',  meta: '5 min' }
  - { href: './using-mock-client.md',       meta: '5 min' }
  - { href: '../using-the-client/manager-vs-interface.md', meta: '5 min' }

prev: { label: 'PHPUnit and Pest setup',  href: './phpunit-and-pest-setup.md' }
next: { label: 'Using MockClient',        href: './using-mock-client.md' }
---

# Mocking the manager

In Symfony tests, replace the bundle's services in the test
container. Two approaches:

- **`static::getContainer()->set()`** — the modern Symfony idiom.
- **Mockery / PHPUnit createMock()** — for service-isolated unit
  tests.

## `static::getContainer()->set()` — recommended

Symfony's test container allows overriding any service. Per
test, swap in a mock:

<!-- @code-block language="php" label="container set" -->
```php
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

        // Replace OpcUaClientInterface in the container with the mock
        static::getContainer()->set(OpcUaClientInterface::class, $mock);

        $client->request('GET', '/api/plc/speed');

        $this->assertResponseIsSuccessful();
    }
}
```
<!-- @endcode-block -->

This works because the controller injects
`OpcUaClientInterface` — and the container now returns the
mock.

## Replacing `OpcuaManager`

When the code under test uses the manager directly:

<!-- @code-block language="php" label="manager replacement" -->
```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\SymfonyOpcua\OpcuaManager;

public function testService(): void
{
    $client = static::createClient();

    $mock = MockClient::create()
        ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

    // Anonymous subclass returns the mock for any connection name
    $manager = new class($mock) extends OpcuaManager {
        public function __construct(private MockClient $m) {
            parent::__construct(['default' => 'd', 'connections' => ['d' => []]]);
        }
        public function connect(?string $name = null): \PhpOpcua\Client\OpcUaClientInterface { return $this->m; }
    };

    static::getContainer()->set(OpcuaManager::class, $manager);

    $client->request('GET', '/api/plc/speed');
    $this->assertResponseIsSuccessful();
}
```
<!-- @endcode-block -->

The anonymous subclass keeps the test inline; for several tests
that share the pattern, extract a `TestableOpcuaManager` class.

## Multi-connection mocking

When the code switches between connections:

<!-- @code-block language="php" label="multi-connection" -->
```php
$lineAMock = MockClient::create()
    ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

$lineBMock = MockClient::create()
    ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(80.0));

$manager = new class($lineAMock, $lineBMock) extends OpcuaManager {
    public function __construct(
        private MockClient $a,
        private MockClient $b,
    ) {
        parent::__construct([
            'default' => 'plc-line-a',
            'connections' => [
                'plc-line-a' => [],
                'plc-line-b' => [],
            ],
        ]);
    }

    public function connect(?string $name = null): \PhpOpcua\Client\OpcUaClientInterface
    {
        return match ($name ?? 'plc-line-a') {
            'plc-line-a' => $this->a,
            'plc-line-b' => $this->b,
            default      => throw new \InvalidArgumentException("Unknown: $name"),
        };
    }
};

static::getContainer()->set(OpcuaManager::class, $manager);
```
<!-- @endcode-block -->

## Mockery — when MockClient isn't enough

`MockClient` covers most cases, but for complex assertion needs
(call count, argument matching), use Mockery:

<!-- @code-block language="php" label="mockery" -->
```php
use Mockery as M;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\DataValue;

protected function tearDown(): void
{
    M::close();
    parent::tearDown();
}

public function testWriteCallsServer(): void
{
    $client = static::createClient();

    $mock = M::mock(OpcUaClientInterface::class);
    $mock->shouldReceive('write')
        ->once()
        ->with('ns=2;s=Setpoint', 75.0)
        ->andReturn(true);

    static::getContainer()->set(OpcUaClientInterface::class, $mock);

    $client->request('PUT', '/api/plc/ns=2;s=Setpoint', [], [], [], json_encode(['value' => 75.0]));
    $this->assertResponseIsSuccessful();
}
```
<!-- @endcode-block -->

## PHPUnit's `createMock()`

For service-isolated unit tests (no container):

<!-- @code-block language="php" label="createMock" -->
```php
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\DataValue;
use PHPUnit\Framework\TestCase;

final class SpeedServiceTest extends TestCase
{
    public function testCurrent(): void
    {
        $dv = $this->createMock(DataValue::class);
        $dv->method('getValue')->willReturn(75.0);
        $dv->statusCode = 0;

        $client = $this->createMock(OpcUaClientInterface::class);
        $client->method('read')->with('ns=2;s=Speed')->willReturn($dv);

        $service = new \App\Service\SpeedService($client);

        $this->assertSame(75.0, $service->current());
    }
}
```
<!-- @endcode-block -->

## Pest expectations

<!-- @code-block language="php" label="pest" -->
```php
use App\Service\SpeedService;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;

it('returns the current speed', function () {
    $client = MockClient::create()
        ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

    $service = new SpeedService($client);

    expect($service->current())->toBe(75.0);
});
```
<!-- @endcode-block -->

## Throwing exceptions

For testing error paths:

<!-- @code-block language="php" label="exception" -->
```php
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Testing\MockClient;

$mock = MockClient::create()
    ->onRead('ns=2;s=Speed', function () {
        throw new ConnectionException('PLC unreachable');
    });

static::getContainer()->set(OpcUaClientInterface::class, $mock);

$client->request('GET', '/api/plc/speed');
$this->assertResponseStatusCodeSame(502);
```
<!-- @endcode-block -->

## Spying on events

Test that the bundle dispatched an event:

<!-- @code-block language="php" label="event spy" -->
```php
use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

$dispatcher = static::getContainer()->get('event_dispatcher');
$received = [];

$dispatcher->addListener(DataChangeReceived::class, function (DataChangeReceived $e) use (&$received) {
    $received[] = $e;
});

// trigger code path that should emit
$this->assertCount(1, $received);
$this->assertSame('ns=2;s=Speed', $received[0]->nodeId);
```
<!-- @endcode-block -->

## When manager mocking is wrong

Two cases:

1. **Testing the manager itself.** Don't — it's tested upstream.
2. **Complex multi-call flows.** A test with 8 `shouldReceive`
   calls signals too much coupling — refactor the production
   code first.

For complex flows, prefer `MockClient` — see
[Using MockClient](./using-mock-client.md).

## Container override permissions

Replacing services requires that the service is **non-public**
turned **public for tests**. Symfony's test container does this
automatically for any service listed in
`config/services_test.yaml`:

<!-- @code-block language="text" label="services_test.yaml" -->
```text
services:
    test.OpcUaClientInterface:
        alias: PhpOpcua\Client\OpcUaClientInterface
        public: true
    test.OpcuaManager:
        alias: PhpOpcua\SymfonyOpcua\OpcuaManager
        public: true
```
<!-- @endcode-block -->

Modern Symfony defaults to making all services public in the
test container — usually you don't need this.

## Where to read next

- [Using MockClient](./using-mock-client.md) — when manager
  mocking gets verbose.
- [Kernel tests](./kernel-tests.md) — full `KernelTestCase`
  patterns.
