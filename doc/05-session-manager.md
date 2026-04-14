# Session Manager

## Overview

PHP's request/response lifecycle destroys all state at the end of every request -- including TCP connections. OPC UA requires a 5-step handshake (TCP -> Hello/Ack -> OpenSecureChannel -> CreateSession -> ActivateSession) that adds 50-200ms per request.

The session manager solves this with a long-running daemon that holds OPC UA connections in memory. PHP requests communicate with the daemon via a lightweight Unix socket IPC protocol.

**The session manager is entirely optional.** If the daemon is not running, the bundle falls back to direct connections with zero code changes.

```
Without daemon:
  Request 1:  [connect 150ms] [read 5ms] [disconnect]  -> ~155ms
  Request 2:  [connect 150ms] [read 5ms] [disconnect]  -> ~155ms

With daemon:
  Request 1:  [open session 150ms] [read 5ms]           -> ~155ms (first only)
  Request 2:                       [read 5ms]           -> ~5ms
  Request N:                       [read 5ms]           -> ~5ms
```

## Starting the Daemon

```bash
php bin/console opcua:session
```

The daemon creates a Unix socket at `var/opcua-session-manager.sock` (configurable). The `OpcuaManager` detects this socket and routes traffic through the daemon automatically.

## Command Options

```bash
php bin/console opcua:session \
    --timeout=600 \
    --cleanup-interval=30 \
    --max-sessions=100 \
    --socket-mode=0600
```

| Option | Default | Description |
|--------|---------|-------------|
| `--timeout` | `600` | Session inactivity timeout in seconds |
| `--cleanup-interval` | `30` | Interval between expired session checks |
| `--max-sessions` | `100` | Maximum concurrent OPC UA sessions |
| `--socket-mode` | `0600` | Unix socket file permissions (octal) |

All options can also be configured via YAML config.

## Configuration

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    session_manager:
        enabled: true
        socket_path: '%kernel.project_dir%/var/opcua-session-manager.sock'
        timeout: 600
        cleanup_interval: 30
        auth_token: '%env(OPCUA_AUTH_TOKEN)%'
        max_sessions: 100
        socket_mode: 0600
        allowed_cert_dirs: ~
        log_channel: ~
        cache_pool: cache.app
        auto_publish: false
```

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Enable daemon auto-detection |
| `socket_path` | `%kernel.project_dir%/var/opcua-session-manager.sock` | Unix socket path |
| `timeout` | `600` | Session inactivity timeout (seconds) |
| `cleanup_interval` | `30` | Expired session check interval |
| `auth_token` | `null` | Shared secret for daemon IPC |
| `max_sessions` | `100` | Maximum concurrent sessions |
| `socket_mode` | `0600` | Socket file permissions |
| `allowed_cert_dirs` | `null` | Certificate directory whitelist |
| `log_channel` | `null` | Monolog channel name (`null` = default logger) |
| `cache_pool` | `cache.app` | Symfony cache pool service ID (PSR-6, wrapped to PSR-16) |
| `auto_publish` | `false` | Auto-publish for sessions with subscriptions |

When `log_channel` is set to a channel name (e.g. `opcua`), the bundle resolves the `monolog.logger.opcua` service and injects it into both the daemon and all clients. When `null`, the default Symfony `logger` service is used.

When `cache_pool` is set, the bundle wraps the specified PSR-6 cache pool with `Symfony\Component\Cache\Psr16Cache` and injects the resulting PSR-16 cache into all clients.

## Production Deployment

Use systemd to keep the daemon running in production:

```ini
[Unit]
Description=OPC UA Session Manager
After=network.target

[Service]
User=www-data
WorkingDirectory=/path/to/symfony-project
ExecStart=/usr/bin/php bin/console opcua:session
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable opcua-session-manager
sudo systemctl start opcua-session-manager
```

Check status:

```bash
sudo systemctl status opcua-session-manager
sudo journalctl -u opcua-session-manager -f
```

## Architecture

```
+--------------+         +------------------------------+         +--------------+
|  PHP Request | --IPC-->|  Session Manager Daemon       | --TCP-->|  OPC UA      |
|  (short-     |<--IPC-- |                              |<--TCP-- |  Server      |
|   lived)     |         |  * ReactPHP event loop       |         |              |
+--------------+         |  * Sessions in memory        |         +--------------+
                         |  * Periodic cleanup timer    |
+--------------+         |  * Signal handlers           |
|  PHP Request | --IPC-->|                              |
|  (reuses     |<--IPC-- |  Sessions:                   |
|   session)   |         |   [sess-a1b2] -> Client (TCP)|
+--------------+         |   [sess-c3d4] -> Client (TCP)|
                         +------------------------------+
```

The daemon:
- Listens on a Unix socket for incoming IPC requests
- Creates OPC UA `Client` instances for each session
- Forwards OPC UA operations from PHP to the client
- Serializes/deserializes all OPC UA types over JSON IPC
- Cleans up expired sessions based on inactivity timeout
- Gracefully disconnects all sessions on SIGTERM/SIGINT
- Tracks active subscriptions and transfers them on reconnection
- Manages certificate trust store state across sessions
- Supports `modifyMonitoredItems()` and `setTriggering()` forwarding

## IPC Config Keys

When the daemon creates a `ManagedClient` for each session, the following additional IPC config keys are forwarded from the connection config:

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `trustStorePath` | `string\|null` | `null` | Path to the trust store directory for certificate persistence |
| `trustPolicy` | `string\|null` | `null` | Trust validation policy: `fingerprint`, `fingerprint+expiry`, or `full` |
| `autoAccept` | `bool` | `false` | Automatically accept unknown server certificates (TOFU mode) |
| `autoDetectWriteType` | `bool` | `true` | Auto-detect OPC UA type on `write()` when type is omitted |
| `readMetadataCache` | `bool` | `false` | Cache non-Value attribute reads (DisplayName, DataType, etc.) |

These map directly to the `ManagedClient` methods:

```php
$client->setTrustStorePath('/var/opcua/trust');
$client->setTrustPolicy(\PhpOpcua\Client\TrustStore\TrustPolicy::Fingerprint);
$client->autoAccept(true);
$client->setAutoDetectWriteType(true);
$client->setReadMetadataCache(true);
```

## Security

The daemon supports multiple security layers:

- **IPC authentication** -- `auth_token` validated with timing-safe comparison
- **Socket permissions** -- `0600` by default (owner-only access)
- **Method whitelist** -- only documented OPC UA operations allowed
- **Session limits** -- configurable maximum to prevent resource exhaustion
- **Certificate path restrictions** -- `allowed_cert_dirs` constrains certificate file access
- **Trust store integration** -- certificate trust decisions persisted via `FileTrustStore`

## Checking Daemon Status

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class DiagnosticsController
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    public function status(): Response
    {
        if ($this->opcua->isSessionManagerRunning()) {
            // Daemon is running -- ManagedClient will be used
        } else {
            // Daemon is not running -- direct Client will be used
        }
    }
}
```

## ManagedClient Methods

The `ManagedClient` (used when the daemon is running) supports the following methods in addition to the standard `OpcUaClientInterface`:

| Method | Description |
|--------|-------------|
| `setTrustStorePath(string $path)` | Set the directory for persisting trusted certificates |
| `setTrustPolicy(TrustPolicy $policy)` | Set the trust validation policy |
| `autoAccept(bool $enabled)` | Enable/disable automatic certificate acceptance |
| `setAutoDetectWriteType(bool $enabled)` | Enable/disable write type auto-detection |
| `setReadMetadataCache(bool $enabled)` | Enable/disable read metadata caching |
| `setEventDispatcher(EventDispatcherInterface $dispatcher)` | Attach a PSR-14 event dispatcher |
| `trustCertificate(string $der)` | Programmatically trust a server certificate |
| `untrustCertificate(string $der)` | Remove a previously trusted certificate |
| `modifyMonitoredItems(int $subId, array $items)` | Modify sampling/filter of existing monitored items |
| `setTriggering(int $subId, int $triggeringItem, array $linksToAdd, array $linksToRemove)` | Configure triggered monitoring links |
