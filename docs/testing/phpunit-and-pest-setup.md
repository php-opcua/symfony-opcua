---
eyebrow: 'Docs · Testing'
lede:    'Test setup for OPC UA-aware Symfony apps — disabling the daemon, the three test layers, fixtures, and the PHPUnit/Pest config.'

see_also:
  - { href: './mocking-the-manager.md',          meta: '5 min' }
  - { href: './using-mock-client.md',            meta: '5 min' }
  - { href: './kernel-tests.md',                 meta: '6 min' }

prev: { label: 'Trust store',          href: '../security/trust-store.md' }
next: { label: 'Mocking the manager',  href: './mocking-the-manager.md' }
---

# PHPUnit and Pest setup

The bundle plays well with both PHPUnit and Pest — the standard
Symfony test-pack idioms apply.

## Disable the daemon

The single most important rule: **don't hit the daemon in unit
or feature tests**. Set in `.env.test`:

<!-- @code-block language="bash" label=".env.test" -->
```bash
OPCUA_SESSION_MANAGER_ENABLED=false
```
<!-- @endcode-block -->

…paired with bundle config:

<!-- @code-block language="text" label="config/packages/test/php_opcua_symfony_opcua.yaml" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        enabled: false
    connections:
        default:
            endpoint:        'opc.tcp://localhost:14840'   # test server
            security_policy: None
            security_mode:   None
```
<!-- @endcode-block -->

In the test environment, the bundle always uses direct mode —
and you'll mock the client per test.

## Three test layers

| Layer            | Tests                                        | Touches OPC UA?           |
| ---------------- | -------------------------------------------- | ------------------------- |
| Unit             | Pure business logic                          | No — mock everything       |
| Functional        | Controllers, listeners, handlers             | No — mock the manager      |
| Integration       | End-to-end against a real test server         | Yes (Docker fixture)       |

Unit + functional run on every push. Integration runs on PR
merge or nightly.

## PHPUnit configuration

<!-- @code-block language="text" label="phpunit.dist.xml" -->
```text
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheDirectory=".phpunit.cache"
>
    <php>
        <env name="APP_ENV" value="test" force="true"/>
        <env name="SHELL_VERBOSITY" value="-1"/>
        <env name="OPCUA_SESSION_MANAGER_ENABLED" value="false" force="true"/>
    </php>

    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="functional">
            <directory>tests/Functional</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```
<!-- @endcode-block -->

Run a single suite:

<!-- @code-block language="bash" label="terminal" -->
```bash
vendor/bin/phpunit --testsuite=unit
vendor/bin/phpunit --testsuite=functional
vendor/bin/phpunit --testsuite=integration
```
<!-- @endcode-block -->

## Pest configuration

<!-- @code-block language="text" label="tests/Pest.php" -->
```text
<?php

declare(strict_types=1);

use App\Tests\TestCase;
use App\Tests\KernelTestCase;
use App\Tests\IntegrationTestCase;

// Apply different base test classes per directory
uses(TestCase::class)->in('Unit');
uses(KernelTestCase::class)->in('Functional');
uses(IntegrationTestCase::class)->in('Integration');

beforeEach(function () {
    // Reset any global state if your unit tests need it.
});
```
<!-- @endcode-block -->

Run:

<!-- @code-block language="bash" label="terminal" -->
```bash
vendor/bin/pest --group=unit
vendor/bin/pest --group=functional
vendor/bin/pest --group=integration
```
<!-- @endcode-block -->

## Unit tests — mock everything

<!-- @code-block language="php" label="tests/Unit/SpeedServiceTest.php" -->
```php
namespace App\Tests\Unit;

use App\Service\SpeedService;
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PHPUnit\Framework\TestCase;

final class SpeedServiceTest extends TestCase
{
    public function testCurrent(): void
    {
        $client = MockClient::create()
            ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

        $service = new SpeedService($client);

        $this->assertSame(75.0, $service->current());
    }
}
```
<!-- @endcode-block -->

`MockClient` implements `OpcUaClientInterface`. See
[Using MockClient](./using-mock-client.md).

## Functional tests — mock the manager

<!-- @code-block language="php" label="tests/Functional/SpeedControllerTest.php" -->
```php
namespace App\Tests\Functional;

use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SpeedControllerTest extends WebTestCase
{
    public function testShow(): void
    {
        $client = static::createClient();

        $mock = MockClient::create()
            ->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(75.0));

        static::getContainer()->set(OpcuaManager::class, new class($mock) extends OpcuaManager {
            public function __construct(private MockClient $mock)
            {
                parent::__construct([
                    'default' => 'default',
                    'connections' => ['default' => []],
                ]);
            }
            public function connect(?string $name = null): \PhpOpcua\Client\OpcUaClientInterface
            {
                return $this->mock;
            }
        });

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

See [Mocking the manager](./mocking-the-manager.md) and
[Kernel tests](./kernel-tests.md).

## Integration tests — real server

<!-- @code-block language="php" label="tests/Integration/IntegrationTestCase.php" -->
```php
namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IntegrationTestCase extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="tests/Integration/PlcReadTest.php" -->
```php
namespace App\Tests\Integration;

use PhpOpcua\SymfonyOpcua\OpcuaManager;

final class PlcReadTest extends IntegrationTestCase
{
    public function testReadServerStatus(): void
    {
        $opcua = static::getContainer()->get(OpcuaManager::class);
        assert($opcua instanceof OpcuaManager);

        $dv = $opcua->connect()->read('i=2256');

        $this->assertSame(0, $dv->statusCode);
    }
}
```
<!-- @endcode-block -->

Run against a Docker-hosted test server — see
[Recipes · Dev with Docker](../recipes/dev-with-docker.md).

## CI matrix

<!-- @code-block language="text" label=".github/workflows/test.yml" -->
```text
jobs:
  unit-and-functional:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.2', '8.3', '8.4']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '${{ matrix.php }}' }
      - run: composer install
      - run: vendor/bin/phpunit --testsuite=unit
      - run: vendor/bin/phpunit --testsuite=functional

  integration:
    runs-on: ubuntu-latest
    services:
      opcua:
        image: ghcr.io/php-opcua/uanetstandard-test-suite:v1.2.0
        ports: ['4840:4840', '4841:4841']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4' }
      - run: composer install
      - run: vendor/bin/phpunit --testsuite=integration
```
<!-- @endcode-block -->

Unit/functional on every push; integration with a Docker
service sidecar.

## Useful test hooks

### Disable cache between tests

<!-- @code-block language="php" label="setUp" -->
```php
protected function setUp(): void
{
    parent::setUp();
    static::getContainer()->get('cache.app')->clear();
}
```
<!-- @endcode-block -->

### Freeze the clock

Symfony's Clock component:

<!-- @code-block language="text" label="composer" -->
```text
composer require symfony/clock
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="clock" -->
```php
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Clock;

protected function setUp(): void
{
    Clock::set(new MockClock('2026-05-15 10:00:00'));
}
```
<!-- @endcode-block -->

### Fake the EventDispatcher

<!-- @code-block language="php" label="event spy" -->
```php
use PhpOpcua\Client\Events\DataChangeReceived;

$dispatched = [];
static::getContainer()
    ->get('event_dispatcher')
    ->addListener(DataChangeReceived::class, function ($event) use (&$dispatched) {
        $dispatched[] = $event;
    });

// trigger code
// then assert on $dispatched
```
<!-- @endcode-block -->

## Coverage targets

| Target                                  | Reasonable coverage |
| --------------------------------------- | ------------------- |
| Services with OPC UA logic              | 90%+                |
| Event listeners                          | 90%+                |
| Controllers                              | 80%+                |
| Trust-store / cert tooling              | Medium              |

Don't chase 100% — real-cert handshake testing is
disproportionate.

## Where to read next

- [Mocking the manager](./mocking-the-manager.md) — Mockery-based
  manager mocks.
- [Using MockClient](./using-mock-client.md) — the in-memory
  client.
- [Kernel tests](./kernel-tests.md) — `KernelTestCase` patterns.
