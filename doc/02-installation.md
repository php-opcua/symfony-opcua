# Installation & Configuration

## Installation

```bash
composer require php-opcua/symfony-opcua
```

Register the bundle in `config/bundles.php` (auto-registered with Symfony Flex):

```php
return [
    // ...
    PhpOpcua\SymfonyOpcua\PhpOpcuaSymfonyOpcuaBundle::class => ['all' => true],
];
```

## Basic Setup

Create `config/packages/php_opcua_symfony_opcua.yaml`:

```yaml
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
```

Add to `.env`:

```dotenv
OPCUA_ENDPOINT=opc.tcp://192.168.1.100:4840
```

That's all you need to get started.

## Configuration File

The full configuration has three sections: default connection, session manager, and connections.

### Default Connection

```yaml
php_opcua_symfony_opcua:
    default: default
```

### Session Manager

```yaml
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
| `socket_path` | `%kernel.project_dir%/var/opcua-session-manager.sock` (Linux/macOS) — set to `tcp://127.0.0.1:9990` on Windows | IPC endpoint URI: `unix://<path>`, `tcp://127.0.0.1:<port>` (loopback-only), or scheme-less path (= `unix://<path>`) |
| `timeout` | `600` | Session inactivity timeout (seconds) |
| `cleanup_interval` | `30` | Expired session check interval |
| `auth_token` | `null` | Shared secret for daemon IPC |
| `max_sessions` | `100` | Maximum concurrent sessions |
| `socket_mode` | `0600` | Socket file permissions |
| `allowed_cert_dirs` | `null` | Certificate directory whitelist |
| `log_channel` | `null` | Monolog channel name (null = default logger) |
| `cache_pool` | `cache.app` | Symfony cache pool service ID (PSR-6) |
| `auto_publish` | `false` | Auto-publish for sessions with subscriptions |

### Connections

Each connection can have its own endpoint, security settings, and credentials:

```yaml
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
            security_policy: None
            security_mode: None
            username: ~
            password: ~
            client_certificate: ~
            client_key: ~
            ca_certificate: ~
            user_certificate: ~
            user_key: ~
            timeout: 5.0
            auto_retry: ~
            batch_size: ~
            browse_max_depth: 10
            trust_store_path: ~
            trust_policy: ~
            auto_accept: false
            auto_accept_force: false
            auto_detect_write_type: true
            read_metadata_cache: false
```

| Key | Default | Description |
|-----|---------|-------------|
| `endpoint` | *(required)* | OPC UA server URL |
| `security_policy` | `None` | Security policy name or URI (see [Security](07-security.md) for all 10 policies including ECC) |
| `security_mode` | `None` | `None`, `Sign`, or `SignAndEncrypt` |
| `username` / `password` | `null` | Username/password authentication |
| `client_certificate` / `client_key` | `null` | Client certificate (auto-generated if omitted) |
| `ca_certificate` | `null` | CA certificate for server validation |
| `user_certificate` / `user_key` | `null` | X.509 certificate authentication |
| `timeout` | `5.0` | Network timeout in seconds |
| `auto_retry` | `null` | Max reconnection retries |
| `batch_size` | `null` | Max items per read/write batch |
| `browse_max_depth` | `10` | Default depth for `browseRecursive()` |
| `trust_store_path` | `null` | Path to the certificate trust store directory |
| `trust_policy` | `null` | Trust policy: `fingerprint`, `fingerprint+expiry`, or `full` |
| `auto_accept` | `false` | Automatically accept unknown server certificates |
| `auto_accept_force` | `false` | Force accept even revoked/expired certificates |
| `auto_detect_write_type` | `true` | Auto-detect OPC UA type on `write()` when type is omitted |
| `read_metadata_cache` | `false` | Cache node metadata to avoid redundant reads |

## Multiple Connections

```yaml
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'

        plc-line-1:
            endpoint: 'opc.tcp://10.0.0.10:4840'
            username: operator
            password: pass123

        plc-line-2:
            endpoint: 'opc.tcp://10.0.0.11:4840'
            security_policy: Basic256Sha256
            security_mode: SignAndEncrypt
            client_certificate: /etc/opcua/certs/client.pem
            client_key: /etc/opcua/certs/client.key
```

## Environment Variables Reference

```dotenv
# Connection
OPCUA_ENDPOINT=opc.tcp://192.168.1.100:4840

# Authentication
OPCUA_USERNAME=admin
OPCUA_PASSWORD=secret

# Security
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
OPCUA_CLIENT_CERT=/path/to/client.pem
OPCUA_CLIENT_KEY=/path/to/client.key
OPCUA_CA_CERT=/path/to/ca.pem

# Client behaviour
OPCUA_TIMEOUT=10.0
OPCUA_AUTO_RETRY=3
OPCUA_BATCH_SIZE=100
OPCUA_BROWSE_MAX_DEPTH=20

# Trust store & certificate acceptance
OPCUA_TRUST_STORE_PATH=/path/to/trust-store
OPCUA_TRUST_POLICY=fingerprint
OPCUA_AUTO_ACCEPT=false
OPCUA_AUTO_ACCEPT_FORCE=false

# Write & read behaviour
OPCUA_AUTO_DETECT_WRITE_TYPE=true
OPCUA_READ_METADATA_CACHE=true

# Session manager
OPCUA_AUTH_TOKEN=my-secret-token
```

Environment variables are referenced in YAML config using Symfony's `%env(...)%` syntax.
