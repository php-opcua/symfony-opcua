# Connections

## Named Connections

Define connections in `config/packages/php_opcua_symfony_opcua.yaml`:

```yaml
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
        plc-line-1:
            endpoint: 'opc.tcp://10.0.0.10:4840'
            username: operator
            password: pass123
```

### Connecting

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class PlcController
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    public function index(): Response
    {
        // Default connection
        $client = $this->opcua->connect();

        // Named connection
        $client = $this->opcua->connect('plc-line-1');
    }
}
```

### Getting a Connection Without Connecting (Managed Mode Only)

In direct mode, `connection()` returns an already-connected `Client`. The `OpcUaClientInterface` does not expose setter methods (`setTimeout()`, etc.), so there is no pre-connect configuration step. If you need to customise timeout or other settings, set them in YAML config or pass inline config to `connectTo()`.

In managed mode (session manager running), `connection()` returns a `ManagedClient` that still supports setter methods before the first operation.

```php
// Direct mode -- returns a connected Client (same as connect())
$client = $this->opcua->connection('plc-line-1');

// Managed mode -- setter methods are still available
// $client = $this->opcua->connection('plc-line-1');
// $client->setTimeout(15.0);
```

### Switching the Default

Set the `default` key in YAML config:

```yaml
php_opcua_symfony_opcua:
    default: plc-line-1
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
        plc-line-1:
            endpoint: 'opc.tcp://10.0.0.10:4840'
```

## Ad-hoc Connections

Connect to endpoints not defined in config:

```php
// Minimal
$client = $this->opcua->connectTo('opc.tcp://10.0.0.50:4840');

// With inline config
$client = $this->opcua->connectTo('opc.tcp://10.0.0.99:4840', [
    'username'        => 'operator',
    'password'        => 'secret',
    'security_policy' => 'Basic256Sha256',
    'security_mode'   => 'SignAndEncrypt',
]);

// With a name for later retrieval
$client = $this->opcua->connectTo('opc.tcp://10.0.0.99:4840', as: 'temp-plc');
$same = $this->opcua->connection('temp-plc');
```

## Disconnecting

```php
// Single connection
$this->opcua->disconnect('plc-line-1');

// Default connection
$this->opcua->disconnect();

// All connections (including ad-hoc)
$this->opcua->disconnectAll();
```

After disconnecting, the next call to `connection()` or `connect()` creates a fresh client.

## Connection Caching

`OpcuaManager` caches client instances by name. Repeated calls to `connection('plc-1')` return the same instance:

```php
$a = $this->opcua->connection('plc-1');
$b = $this->opcua->connection('plc-1');
assert($a === $b); // true
```

Calling `disconnect()` removes the cached instance.

## Dependency Injection

The `OpcuaManager` is registered as a public service and can be injected anywhere via autowiring.

### OpcuaManager for Full Control

Use `OpcuaManager` when you need to manage multiple connections, use ad-hoc connections, or switch between named connections:

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class PlcService
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    public function readFromBothLines(): array
    {
        $line1 = $this->opcua->connect('plc-line-1');
        $line2 = $this->opcua->connect('plc-line-2');

        $temp1 = $line1->read('ns=2;s=Temperature')->getValue();
        $temp2 = $line2->read('ns=2;s=Temperature')->getValue();

        $this->opcua->disconnectAll();

        return [$temp1, $temp2];
    }
}
```

### OpcUaClientInterface for Default Connection

When you only need the default connection, inject `OpcUaClientInterface` directly. The bundle registers a factory that calls `OpcuaManager::connection(null)`:

```php
use PhpOpcua\Client\OpcUaClientInterface;

class PlcController
{
    public function __construct(private readonly OpcUaClientInterface $client) {}

    public function index(): Response
    {
        $value = $this->client->read('ns=2;i=1001');

        return new JsonResponse(['value' => $value->getValue()]);
    }
}
```

> In direct mode, injecting `OpcUaClientInterface` returns an already-connected client. In managed mode, it returns a configured `ManagedClient` that connects on first operation.

### Controller Action Injection

Symfony also supports injection via controller action parameters:

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use PhpOpcua\Client\OpcUaClientInterface;

class PlcController
{
    public function readStatus(OpcuaManager $opcua): Response
    {
        $client = $opcua->connect();
        $value = $client->read('i=2259');
        $client->disconnect();

        return new JsonResponse(['status' => $value->getValue()]);
    }

    public function readDefault(OpcUaClientInterface $client): Response
    {
        $value = $client->read('i=2259');

        return new JsonResponse(['status' => $value->getValue()]);
    }
}
```

## Session Manager Auto-Detection

When creating a client, `OpcuaManager` checks for the daemon's Unix socket:

1. `session_manager.enabled` is `true` (default)
2. `session_manager.socket_path` file exists

If both conditions are met, a `ManagedClient` (daemon-proxied) is created. Otherwise, a direct `Client` is created via `ClientBuilder`. This is transparent -- your application code does not change.

```php
if ($this->opcua->isSessionManagerRunning()) {
    // ManagedClient -- session persists across requests
} else {
    // Direct Client -- new TCP connection per request
}
```

## How Connection Configuration Works

When a connection is created, `OpcuaManager` applies configuration differently depending on the mode.

### Direct Mode (configureBuilder)

A `ClientBuilder` is configured and then `connect()` is called, returning a fully connected `Client`. All settings are immutable after connection.

1. Security policy and mode
2. User credentials (username/password)
3. Client certificate and CA
4. User certificate
5. Timeout
6. Auto-retry
7. Batch size
8. Browse max depth
9. Trust store path and trust policy
10. Auto-accept and auto-accept-force
11. Auto-detect write type
12. Read metadata cache
13. Logger (explicit config or Symfony default via Monolog)
14. Cache (explicit config or Symfony default via Psr16Cache wrapping a PSR-6 pool)
15. Event dispatcher (Symfony EventDispatcher if available)

### Managed Mode (configureManagedClient)

A `ManagedClient` is created and setter methods are called. Settings can be adjusted after creation.

1. Security policy and mode
2. User credentials (username/password)
3. Client certificate and CA
4. User certificate
5. Timeout
6. Auto-retry
7. Batch size
8. Browse max depth
9. Trust store path and trust policy
10. Auto-accept and auto-accept-force
11. Auto-detect write type
12. Read metadata cache
13. Logger (explicit config or Symfony default via Monolog)
14. Cache (explicit config or Symfony default via Psr16Cache wrapping a PSR-6 pool)
15. Event dispatcher (Symfony EventDispatcher if available)
