# Examples

Complete, copy-paste-ready code examples for all major features. All examples use Symfony dependency injection patterns — constructor injection of `OpcuaManager` or `OpcUaClientInterface`.

## Read a Single Value

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class ServerStatusService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function getServerState(): int
    {
        $client = $this->opcua->connect();

        $dv = $client->read('i=2259');
        $state = $dv->getValue(); // 0 = Running

        $client->disconnect();

        return $state;
    }
}
```

## Read Multiple Values (Array)

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class SensorReadService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function readAll(): array
    {
        $client = $this->opcua->connect();

        $results = $client->readMulti([
            ['nodeId' => 'i=2259'],
            ['nodeId' => 'ns=2;i=1001'],
            ['nodeId' => 'ns=2;s=Temperature'],
        ]);

        $values = [];
        foreach ($results as $i => $dv) {
            $values[$i] = $dv->getValue();
        }

        $client->disconnect();

        return $values;
    }
}
```

## Read Multiple Values (Fluent Builder)

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class FluentReadService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function readWithMetadata(): array
    {
        $client = $this->opcua->connect();

        $results = $client->readMulti()
            ->node('i=2259')->value()
            ->node('ns=2;i=1001')->displayName()
            ->node('ns=2;s=Temperature')->value()
            ->execute();

        $values = [];
        foreach ($results as $dv) {
            $values[] = $dv->getValue();
        }

        $client->disconnect();

        return $values;
    }
}
```

## Write a Value

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

class WriteService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function setSetpoint(int $value): bool
    {
        $client = $this->opcua->connect();

        $status = $client->write('ns=2;i=1001', $value, BuiltinType::Int32);

        $client->disconnect();

        return StatusCode::isGood($status);
    }
}
```

## Write Without Explicit Type (Auto-Detection, v4.0+)

When `auto_detect_write_type` is enabled (the default), you can omit the type parameter. The client reads the node's DataType attribute, caches it, and uses it for the write.

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use PhpOpcua\Client\Types\StatusCode;

class AutoTypeWriteService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function setSetpoint(int $value): bool
    {
        $client = $this->opcua->connect();

        // No BuiltinType needed — the client auto-detects that ns=2;i=1001 is Int32
        $status = $client->write('ns=2;i=1001', $value);

        $client->disconnect();

        return StatusCode::isGood($status);
    }
}
```

## Write Multiple Values (Fluent Builder)

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use PhpOpcua\Client\Types\StatusCode;

class BatchWriteService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function updateAll(): array
    {
        $client = $this->opcua->connect();

        $results = $client->writeMulti()
            ->node('ns=2;i=1001')->int32(42)
            ->node('ns=2;i=1002')->double(3.14)
            ->node('ns=2;s=Label')->string('active')
            ->execute();

        $statuses = [];
        foreach ($results as $i => $status) {
            $statuses[$i] = StatusCode::isGood($status) ? 'OK' : 'FAIL';
        }

        $client->disconnect();

        return $statuses;
    }
}
```

## Read with Refresh Parameter (v4.0+)

When read metadata cache is enabled, use the `refresh` parameter to bypass the cache:

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class CachedReadService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function readWithCache(): void
    {
        $client = $this->opcua->connect();
        $client->setReadMetadataCache(true);

        // Cached read (default)
        $dv = $client->read('ns=2;i=1001');
        echo "Cached: " . $dv->getValue() . "\n";

        // Force fresh read from server
        $dv = $client->read('ns=2;i=1001', refresh: true);
        echo "Fresh: " . $dv->getValue() . "\n";

        $client->disconnect();
    }
}
```

## Browse the Address Space

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class BrowseService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function browseObjects(): array
    {
        $client = $this->opcua->connect();

        $refs = $client->browse('i=85');

        $nodes = [];
        foreach ($refs as $ref) {
            $nodes[] = "{$ref->displayName} ({$ref->nodeClass->name}) — {$ref->nodeId}";
        }

        $client->disconnect();

        return $nodes;
    }
}
```

## Recursive Browse

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class RecursiveBrowseService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function browseTree(): void
    {
        $client = $this->opcua->connect();

        $tree = $client->browseRecursive('i=85', maxDepth: 3);

        $this->printTree($tree);

        $client->disconnect();
    }

    private function printTree(array $nodes, int $indent = 0): void
    {
        foreach ($nodes as $node) {
            echo str_repeat('  ', $indent) . $node->reference->displayName . "\n";
            if (!empty($node->children)) {
                $this->printTree($node->children, $indent + 1);
            }
        }
    }
}
```

## Path Resolution

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class PathResolveService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function resolveAndRead(): void
    {
        $client = $this->opcua->connect();

        $nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');
        $dv = $client->read($nodeId);

        echo "ServerStatus NodeId: {$nodeId}\n";
        echo "Value: {$dv->getValue()}\n";

        $client->disconnect();
    }
}
```

## Call a Method

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\StatusCode;

class MethodCallService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function callHypotenuse(float $a, float $b): ?float
    {
        $client = $this->opcua->connect();

        $result = $client->call(
            'ns=2;i=100',   // parent object
            'ns=2;i=200',   // method
            [
                new Variant(BuiltinType::Double, $a),
                new Variant(BuiltinType::Double, $b),
            ],
        );

        $client->disconnect();

        if (StatusCode::isGood($result->statusCode)) {
            return $result->outputArguments[0]->value;
        }

        return null;
    }
}
```

## Subscribe to Data Changes

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class SubscriptionService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function monitorNodes(): void
    {
        $client = $this->opcua->connect();

        $sub = $client->createSubscription(publishingInterval: 500.0);

        $client->createMonitoredItems($sub->subscriptionId, [
            ['nodeId' => 'ns=2;i=1001', 'clientHandle' => 1],
            ['nodeId' => 'ns=2;i=1002', 'clientHandle' => 2],
        ]);

        // Poll for notifications
        for ($i = 0; $i < 10; $i++) {
            $pub = $client->publish();

            foreach ($pub->notifications as $notif) {
                echo "[handle={$notif['clientHandle']}] {$notif['dataValue']->getValue()}\n";
            }

            usleep(500_000);
        }

        $client->deleteSubscription($sub->subscriptionId);
        $client->disconnect();
    }
}
```

## Historical Data

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class HistoryService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function getLastHour(): array
    {
        $client = $this->opcua->connect();

        $history = $client->historyReadRaw(
            'ns=2;i=1001',
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('now'),
        );

        $data = [];
        foreach ($history as $dv) {
            $data[] = [
                'time' => $dv->sourceTimestamp->format('H:i:s'),
                'value' => $dv->getValue(),
            ];
        }

        $client->disconnect();

        return $data;
    }
}
```

## Secure Connection with Authentication

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class SecureConnectionService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function readFromSecurePlc(): mixed
    {
        // Option 1: Via YAML config (recommended)
        // php_opcua_symfony_opcua:
        //     connections:
        //         secure:
        //             endpoint: 'opc.tcp://10.0.0.10:4840'
        //             security_policy: Basic256Sha256
        //             security_mode: SignAndEncrypt
        //             username: operator
        //             password: secret
        //             client_certificate: /etc/opcua/certs/client.pem
        //             client_key: /etc/opcua/certs/client.key
        $client = $this->opcua->connect('secure');

        // Option 2: Via connectTo with inline config
        $client = $this->opcua->connectTo('opc.tcp://10.0.0.10:4840', [
            'security_policy'    => 'Basic256Sha256',
            'security_mode'      => 'SignAndEncrypt',
            'username'           => 'operator',
            'password'           => 'secret',
            'client_certificate' => '/etc/opcua/certs/client.pem',
            'client_key'         => '/etc/opcua/certs/client.key',
        ]);

        $value = $client->read('ns=2;i=1001');

        $client->disconnect();

        return $value->getValue();
    }
}
```

## ECC Secure Connection

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class EccConnectionService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function readWithEcc(): mixed
    {
        // ECC security — no client certificate needed (auto-generated)
        $client = $this->opcua->connectTo('opc.tcp://10.0.0.10:4840', [
            'security_policy' => 'ECC_nistP256',
            'security_mode'   => 'SignAndEncrypt',
            'username'        => 'operator',
            'password'        => 'secret',
        ]);

        $value = $client->read('ns=2;i=1001');

        $client->disconnect();

        return $value->getValue();
    }
}
```

> **ECC disclaimer:** ECC security policies (`ECC_nistP256`, `ECC_nistP384`, `ECC_brainpoolP256r1`, `ECC_brainpoolP384r1`) are fully implemented and tested against the OPC Foundation's UA-.NETStandard reference stack. No commercial OPC UA vendor supports ECC endpoints yet.

## Multiple Connections

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class MultiConnectionService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function readBothLines(): array
    {
        // Connect to two PLCs simultaneously
        $plc1 = $this->opcua->connect('plc-line-1');
        $plc2 = $this->opcua->connect('plc-line-2');

        $temp1 = $plc1->read('ns=2;s=Temperature')->getValue();
        $temp2 = $plc2->read('ns=2;s=Temperature')->getValue();

        $this->opcua->disconnectAll();

        return [
            'line_1' => $temp1,
            'line_2' => $temp2,
        ];
    }
}
```

## DI in a Symfony Controller

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use PhpOpcua\Client\Types\StatusCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class PlcController extends AbstractController
{
    public function __construct(private OpcuaManager $opcua) {}

    #[Route('/plc/status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $client = $this->opcua->connect();
        $state = $client->read('i=2259')->getValue();
        $client->disconnect();

        return $this->json([
            'server_state' => $state,
            'daemon_active' => $this->opcua->isSessionManagerRunning(),
        ]);
    }

    #[Route('/plc/write', methods: ['POST'])]
    public function write(): JsonResponse
    {
        $client = $this->opcua->connect();
        // Type is optional in v4 — auto-detected when auto_detect_write_type is enabled
        $status = $client->write('ns=2;i=1001', 42);
        $client->disconnect();

        return $this->json([
            'success' => StatusCode::isGood($status),
        ]);
    }
}
```

You can also inject `OpcUaClientInterface` directly for the default connection:

```php
use PhpOpcua\Client\OpcUaClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class SensorController extends AbstractController
{
    public function __construct(private OpcUaClientInterface $client) {}

    #[Route('/sensor/temperature', methods: ['GET'])]
    public function temperature(): JsonResponse
    {
        $dv = $this->client->read('ns=2;s=Temperature');

        return $this->json([
            'temperature' => $dv->getValue(),
            'timestamp' => $dv->sourceTimestamp?->format('c'),
        ]);
    }
}
```

## Type Discovery

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class TypeDiscoveryService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function discoverAndRead(): void
    {
        $client = $this->opcua->connect();

        // Discover all custom types
        $count = $client->discoverDataTypes();
        echo "Discovered {$count} custom types\n";

        // Now reading structured types works without manual codecs
        $point = $client->read('ns=2;s=MyPoint')->getValue();
        // ['x' => 1.5, 'y' => 2.5, 'z' => 3.5]

        $client->disconnect();
    }
}
```

## Error Handling

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Types\StatusCode;

class SafeReadService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function readSafely(): ?int
    {
        try {
            $client = $this->opcua->connect();

            $dv = $client->read('ns=99;i=99999');
            if (StatusCode::isBad($dv->statusCode)) {
                echo "Bad status: {$dv->statusCode}\n";
                return null;
            }

            $client->disconnect();

            return $dv->getValue();
        } catch (ConnectionException $e) {
            echo "Connection failed: {$e->getMessage()}\n";
            return null;
        } catch (ServiceException $e) {
            echo "Service error: {$e->getMessage()}\n";
            return null;
        }
    }
}
```

## Trust Store Configuration (v4.0+)

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use PhpOpcua\Client\Security\TrustPolicy;

class TrustStoreService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function connectWithTrustStore(): void
    {
        $client = $this->opcua->connection();

        // Configure trust store
        $client->setTrustStorePath('/var/opcua/trust');
        $client->setTrustPolicy(TrustPolicy::FingerprintAndExpiry);
        $client->autoAccept(true); // TOFU mode

        $client->connect('opc.tcp://192.168.1.100:4840');

        $dv = $client->read('i=2259');
        echo "ServerState: " . $dv->getValue() . "\n";

        $client->disconnect();
    }
}
```

## Certificate Trust Management (v4.0+)

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use PhpOpcua\Client\Exception\UntrustedCertificateException;

class CertificateTrustService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function connectWithApproval(): void
    {
        try {
            $client = $this->opcua->connect();
        } catch (UntrustedCertificateException $e) {
            echo "Untrusted certificate: " . $e->getFingerprint() . "\n";

            // Approve the certificate and retry
            $client = $this->opcua->connection();
            $client->trustCertificate($e->getCertificate());
            $client->connect('opc.tcp://192.168.1.100:4840');
        }

        // Later, revoke trust
        // $client->untrustCertificate($derBytes);

        $client->disconnect();
    }
}
```

## Event Dispatcher Setup (v4.0+)

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Psr\EventDispatcher\EventDispatcherInterface;

class EventDispatcherService
{
    public function __construct(
        private OpcuaManager $opcua,
        private EventDispatcherInterface $dispatcher,
    ) {}

    public function connectWithEvents(): void
    {
        $client = $this->opcua->connect();

        // Attach Symfony's event dispatcher
        $client->setEventDispatcher($this->dispatcher);

        // Now all OPC UA operations fire PSR-14 events
        $dv = $client->read('i=2259'); // fires BeforeRead + AfterRead

        $client->disconnect(); // fires Disconnected
    }
}
```

> **Note:** When using the bundle's YAML configuration, the Symfony event dispatcher is injected automatically into every connection. You only need to call `setEventDispatcher()` manually for ad-hoc connections created outside the manager.

## Modify Monitored Items (v4.0+)

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class MonitoringTuningService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function adjustSamplingRate(): void
    {
        $client = $this->opcua->connect();

        $sub = $client->createSubscription(publishingInterval: 500.0);

        $client->createMonitoredItems($sub->subscriptionId, [
            ['nodeId' => 'ns=2;i=1001', 'clientHandle' => 1, 'samplingInterval' => 1000.0],
            ['nodeId' => 'ns=2;i=1002', 'clientHandle' => 2, 'samplingInterval' => 1000.0],
        ]);

        // Later, change the sampling interval for item 1
        $client->modifyMonitoredItems($sub->subscriptionId, [
            ['monitoredItemId' => 1, 'samplingInterval' => 200.0],
        ]);

        $client->deleteSubscription($sub->subscriptionId);
        $client->disconnect();
    }
}
```

## Set Triggering (v4.0+)

Link monitored items so that one item triggers reporting of others:

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class TriggeringService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function setupTriggering(): void
    {
        $client = $this->opcua->connect();

        $sub = $client->createSubscription(publishingInterval: 500.0);

        $client->createMonitoredItems($sub->subscriptionId, [
            ['nodeId' => 'ns=2;i=1001', 'clientHandle' => 1],  // triggering item
            ['nodeId' => 'ns=2;i=1002', 'clientHandle' => 2],  // triggered item
            ['nodeId' => 'ns=2;i=1003', 'clientHandle' => 3],  // triggered item
        ]);

        // When item 1 changes, also report items 2 and 3
        $client->setTriggering(
            $sub->subscriptionId,
            1,          // triggering monitored item ID
            [2, 3],     // links to add
            [],         // links to remove
        );

        $client->deleteSubscription($sub->subscriptionId);
        $client->disconnect();
    }
}
```

## Testing with MockClient

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\StatusCode;

// In a Pest / PHPUnit test
it('reads temperature from PLC', function () {
    $mock = MockClient::create()
        ->onRead('ns=2;s=Temperature', fn() => DataValue::ofDouble(23.5));

    $value = $mock->read('ns=2;s=Temperature');

    expect($value->getValue())->toBe(23.5);
    expect($value->statusCode)->toBe(StatusCode::Good);
    expect($mock->callCount('read'))->toBe(1);
});
```
