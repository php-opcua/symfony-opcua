---
eyebrow: 'Docs · Testing'
lede:    'MockClient — the in-memory OpcUaClientInterface from opcua-client. Faithful behaviour without a network. The recommended approach for complex multi-call flows.'

see_also:
  - { href: './mocking-the-manager.md',                       meta: '5 min' }
  - { href: './phpunit-and-pest-setup.md',                    meta: '5 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/testing/mock-client.md', meta: 'external', label: 'opcua-client — testing' }

prev: { label: 'Mocking the manager',  href: './mocking-the-manager.md' }
next: { label: 'Kernel tests',         href: './kernel-tests.md' }
---

# Using MockClient

`MockClient` is a full in-memory `OpcUaClientInterface`. It
behaves like a real client (reads, writes, browses,
subscriptions, errors) — but everything happens in PHP memory.

## When to reach for it

| Scenario                                              | MockClient appropriate?    |
| ----------------------------------------------------- | -------------------------- |
| Unit-test a service with 5+ reads/writes               | **Yes**                    |
| Test subscription callbacks                            | **Yes**                    |
| Test code that handles write failures                  | **Yes**                    |
| Functional test for a controller                       | Usually facade mock instead |
| Integration test against a real server                 | No — use real server       |

`MockClient` shines when the test exercises **multi-step flows**.

## Basic usage

<!-- @code-block language="php" label="basic" -->
```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;

it('captures a reading', function () {
    $client = MockClient::create()
        ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

    $service = new \App\Service\SpeedService($client);

    expect($service->current())->toBe(75.0);
});
```
<!-- @endcode-block -->

`MockClient` implements `OpcUaClientInterface` — anywhere your
production code accepts that interface, the mock plugs in.

## Programmable behaviour

<!-- @code-block language="php" label="responses" -->
```php
$client = MockClient::create();

// Reads
$client->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));
$client->onRead('ns=2;s=Temperature', fn() => DataValue::ofDouble(22.5));

// Writes — capture for assertion, optional success/fail
$client->onWrite('ns=2;s=Setpoint', fn(mixed $value) => true);

// Browse — return a fixture set
$client->onBrowse('ns=2;s=Folder', fn() => [
    referenceFor('ns=2;s=Folder.Speed'),
    referenceFor('ns=2;s=Folder.Temperature'),
]);

// Method calls
$client->onCall('ns=2;s=Recipe', 'ns=2;s=Recipe.Load',
    fn(array $inputs) => [0, [true]]);
```
<!-- @endcode-block -->

## Simulating errors

<!-- @code-block language="php" label="errors" -->
```php
use PhpOpcua\Client\Exception\ConnectionException;

$client->onRead('ns=2;s=Speed', function () {
    throw new ConnectionException('Network unreachable');
});

$client->onWrite('ns=2;s=Setpoint', fn() => false);   // raises ServiceException
```
<!-- @endcode-block -->

## Subscriptions

<!-- @code-block language="php" label="subscription mock" -->
```php
$client = MockClient::create();

$sub = $client->subscribe(
    publishingInterval: 500,
    onData: function (string $node, DataValue $dv) {
        // Test assertion lives here
    },
);

$sub->monitor('ns=2;s=Speed');

// Trigger a value change synchronously
$client->fireDataChange('ns=2;s=Speed', DataValue::ofDouble(75.0));
```
<!-- @endcode-block -->

## Recorded calls

<!-- @code-block language="php" label="recording" -->
```php
$client->getRecordedReads();      // array of NodeIds read
$client->getRecordedWrites();     // [['node' => ..., 'value' => ...], ...]
$client->getRecordedCalls();      // method calls
```
<!-- @endcode-block -->

In a test:

<!-- @code-block language="php" label="assert recorded" -->
```php
it('reads speed before writing setpoint', function () {
    $client = MockClient::create()
        ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(70.0))
        ->onWrite('ns=2;s=Setpoint', fn() => true);

    (new \App\Service\RecipeService($client))->bumpSetpoint();

    expect($client->getRecordedReads())->toContain('ns=2;s=Speed');
    expect($client->getRecordedWrites())->toHaveCount(1);
    expect($client->getRecordedWrites()[0]['node'])->toBe('ns=2;s=Setpoint');
});
```
<!-- @endcode-block -->

## Binding to the container

In a `WebTestCase`, swap into the container:

<!-- @code-block language="php" label="container binding" -->
```php
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Testing\MockClient;

protected function setUp(): void
{
    parent::setUp();
    static::createClient();

    $this->mock = MockClient::create();
    static::getContainer()->set(OpcUaClientInterface::class, $this->mock);
}
```
<!-- @endcode-block -->

Now any controller injecting `OpcUaClientInterface` gets the
mock.

## A reusable test trait

<!-- @code-block language="php" label="HasMockOpcua trait" -->
```php
namespace App\Tests;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Testing\MockClient;

trait HasMockOpcua
{
    protected MockClient $mock;

    protected function setUpOpcuaMock(): void
    {
        $this->mock = MockClient::create();
        static::getContainer()->set(OpcUaClientInterface::class, $this->mock);
    }
}
```
<!-- @endcode-block -->

Usage:

<!-- @code-block language="php" label="usage" -->
```php
final class SomeControllerTest extends WebTestCase
{
    use HasMockOpcua;

    public function testIt(): void
    {
        static::createClient();
        $this->setUpOpcuaMock();

        $this->mock->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(42.0));

        // exercise + assert
    }
}
```
<!-- @endcode-block -->

## Mock vs manager — when to pick which

| Test                                                | Pick                       |
| --------------------------------------------------- | -------------------------- |
| Single OPC UA call, exact interaction known         | Manager mock                |
| 3+ calls, complex flow                              | MockClient                  |
| Need recorded-call assertions                       | MockClient                  |
| Test a controller, not a service                    | Manager mock                |
| Test a service with `OpcUaClientInterface` injection | MockClient                  |

Mix freely. Most apps end up with both styles.

## Comparing values

Don't compare `DataValue` instances with `==` — timestamps
differ. Compare fields:

<!-- @code-block language="php" label="comparison" -->
```php
expect($dv->getValue())->toBe(75.0);
expect($dv->statusCode)->toBe(0);
```
<!-- @endcode-block -->

Or a custom expectation:

<!-- @code-block language="php" label="custom expectation" -->
```php
// tests/Pest.php
expect()->extend('toBeGoodReading', function (mixed $expectedValue) {
    expect($this->value)->statusCode->toBe(0);
    expect($this->value->getValue())->toBe($expectedValue);
});

// usage
expect($dv)->toBeGoodReading(75.0);
```
<!-- @endcode-block -->

## Where to read next

- [Kernel tests](./kernel-tests.md) — `KernelTestCase` patterns.
- [opcua-client testing](https://github.com/php-opcua/opcua-client/blob/master/docs/testing/mock-client.md) —
  the upstream MockClient reference.
