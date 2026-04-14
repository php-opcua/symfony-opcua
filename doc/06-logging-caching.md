# Logging & Caching

## PSR-3 Logging

Every OPC UA client created by `OpcuaManager` automatically receives a Symfony Monolog logger. The logger captures connection events, retries, errors, and protocol details.

### Automatic Injection

The `PhpOpcuaSymfonyOpcuaBundle` resolves a `LoggerInterface` from the Symfony container and passes it to `OpcuaManager`. Every client created through the manager gets this logger via `setLogger()`.

By default, the bundle uses the `logger` service. When `session_manager.log_channel` is set to a channel name (e.g. `opcua`), the bundle resolves `monolog.logger.opcua` instead:

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    session_manager:
        log_channel: opcua
```

This injects the `monolog.logger.opcua` service into both the `OpcuaManager` and the `opcua:session` console command.

### Override Per-Client

```php
use Psr\Log\NullLogger;

$client = $this->opcua->connect();
$client->setLogger(new NullLogger()); // disable logging for this client
```

### Override Per-Connection Config

Pass a logger explicitly in the connection config array (e.g. via `connectTo()`):

```php
$client = $this->opcua->connectTo('opc.tcp://...', [
    'logger' => new NullLogger(),
]);
```

An explicit logger in the config takes precedence over the default Monolog logger.

### What Gets Logged

| Level | Events |
|-------|--------|
| `DEBUG` | Protocol details, handshake steps |
| `INFO` | Connections, batch splits, type discovery |
| `WARNING` | Retry attempts |
| `ERROR` | Failures, exceptions |

### Daemon Logging

The `opcua:session` command uses the same Monolog channel configured via `log_channel`. If not specified, the default Symfony logger is used.

Configure a dedicated Monolog channel for the daemon:

```yaml
# config/packages/monolog.yaml
monolog:
    channels:
        - opcua

    handlers:
        opcua:
            type: rotating_file
            path: '%kernel.logs_dir%/opcua.log'
            level: debug
            channels: [opcua]
```

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    session_manager:
        log_channel: opcua
```

## PSR-14 Event System

The client supports PSR-14 event dispatching. When an event dispatcher is attached, the client fires events for every significant operation -- 47 event types in total covering connections, reads, writes, subscriptions, security, and more.

### Automatic Injection

The bundle automatically injects Symfony's `EventDispatcherInterface` (when available in the container) into `OpcuaManager`. Every client created through the manager receives the event dispatcher.

### Event Categories

| Category | Example Events | Description |
|----------|---------------|-------------|
| Connection | `Connected`, `Disconnected`, `Reconnecting` | Lifecycle of the TCP/secure channel |
| Read | `BeforeRead`, `AfterRead`, `ReadFailed` | Single and multi-read operations |
| Write | `BeforeWrite`, `AfterWrite`, `WriteFailed` | Single and multi-write operations |
| Browse | `BeforeBrowse`, `AfterBrowse` | Address space navigation |
| Subscription | `SubscriptionCreated`, `DataChangeNotification` | Pub/sub monitoring |
| Security | `CertificateTrusted`, `CertificateRejected`, `UntrustedCertificate` | Trust store decisions |
| Method | `BeforeCall`, `AfterCall` | Method invocations |
| Discovery | `EndpointsDiscovered`, `DataTypesDiscovered` | Endpoint and type discovery |

### Listening for Events

Use Symfony's `#[AsEventListener]` attribute to listen for OPC UA events:

```php
use PhpOpcua\Client\Event\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class OpcuaDataChangeListener
{
    public function __invoke(DataChangeReceived $event): void
    {
        // Handle data change notification
    }
}
```

Or register listeners via `services.yaml`:

```yaml
# config/services.yaml
services:
    App\EventListener\OpcuaReadListener:
        tags:
            - { name: kernel.event_listener, event: PhpOpcua\Client\Event\AfterRead }
```

Or use an event subscriber:

```php
use PhpOpcua\Client\Event\Connected;
use PhpOpcua\Client\Event\Disconnected;
use PhpOpcua\Client\Event\AfterRead;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OpcuaEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            Connected::class => 'onConnected',
            Disconnected::class => 'onDisconnected',
            AfterRead::class => 'onAfterRead',
        ];
    }

    public function onConnected(Connected $event): void
    {
        // Log connection
    }

    public function onDisconnected(Disconnected $event): void
    {
        // Clean up
    }

    public function onAfterRead(AfterRead $event): void
    {
        // Process read result
    }
}
```

### Per-Client Override

```php
$client = $this->opcua->connect();
$client->setEventDispatcher(null); // disable events for this client
```

## PSR-16 Caching

Every OPC UA client created by `OpcuaManager` automatically receives a PSR-16 cache. The cache stores browse results, endpoint discovery, and type discovery data.

### Automatic Injection

The bundle wraps the configured Symfony cache pool (PSR-6) with `Symfony\Component\Cache\Psr16Cache` to produce a PSR-16 `CacheInterface`. This adapter is injected into `OpcuaManager` and passed to every client via `setCache()`.

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    session_manager:
        cache_pool: cache.app   # any PSR-6 cache pool service ID
```

The default is `cache.app`. You can use any Symfony cache pool:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.opcua:
                adapter: cache.adapter.redis
                default_lifetime: 3600
```

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    session_manager:
        cache_pool: cache.opcua
```

### Per-Call Cache Control

Many browse operations accept a `useCache` parameter:

```php
// Use cache (default)
$refs = $client->browse('i=85', useCache: true);

// Skip cache for this call
$refs = $client->browse('i=85', useCache: false);

// Resolve with cache
$nodeId = $client->resolveNodeId('/Objects/Server', useCache: true);
```

Methods supporting `useCache`:
- `browse()`
- `browseAll()`
- `getEndpoints()`
- `resolveNodeId()`
- `discoverDataTypes()`

### Cache Invalidation

```php
// Invalidate a specific node
$client->invalidateCache('i=85');

// Flush all cached data
$client->flushCache();
```

### Override Per-Client

```php
use PhpOpcua\Client\Cache\InMemoryCache;

$client = $this->opcua->connect();
$client->setCache(new InMemoryCache(300)); // 300s TTL
```

### Disable Caching

```php
$client->setCache(null);
```

### Available Cache Drivers

The underlying client provides two built-in PSR-16 implementations:

| Driver | Class | Use case |
|--------|-------|----------|
| In-Memory | `InMemoryCache` | Default -- per-process, lost on request end |
| File | `FileCache` | Persists across requests, good for CLI workers |

In a Symfony context, you will typically rely on the automatically injected Symfony cache pool (Redis, Memcached, filesystem, etc.) rather than these built-in drivers.

### Daemon Caching

The `opcua:session` command receives the same PSR-16 cache adapter configured via `cache_pool`. The daemon injects this cache into each OPC UA client it creates.

```yaml
php_opcua_symfony_opcua:
    session_manager:
        cache_pool: cache.opcua    # Redis-backed pool for the daemon
```

## Read Metadata Cache

When `read_metadata_cache` is enabled, the client caches non-Value attribute reads (DisplayName, DataType, Description, etc.). These attributes rarely change, so caching them avoids redundant round-trips.

### Configuration

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
            read_metadata_cache: true
```

### Refresh Parameter

The `read()` method accepts a `refresh` parameter to bypass the metadata cache for a specific call:

```php
// Normal read -- uses metadata cache if enabled
$dv = $client->read('ns=2;i=1001');

// Force a fresh read from the server, ignoring any cached metadata
$dv = $client->read('ns=2;i=1001', refresh: true);
```

This is useful when you know the server has changed metadata (e.g. after reconfiguration) and you want to pick up the latest values.

## Write Type Auto-Detection Cache

When `auto_detect_write_type` is enabled (the default), the client discovers the OPC UA data type of a node before writing, so you can omit the type parameter on `write()`. The discovered types are cached for subsequent writes to the same node.

```yaml
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
            auto_detect_write_type: true   # default
```

This caching works alongside PSR-16 caching. The type discovery results are stored in the same cache backend configured for the client.
