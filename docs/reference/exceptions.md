---
eyebrow: 'Docs · Reference'
lede:    'Every exception the bundle can raise, when, and the right Symfony-side response — Voter denials, controller error pages, Messenger retry hints.'

see_also:
  - { href: '../observability/debugging.md',     meta: '5 min' }
  - { href: '../operations/reading.md',          meta: '6 min' }
  - { href: '../operations/writing.md',          meta: '5 min' }

prev: { label: 'Console commands',  href: './console-commands.md' }
next: { label: 'Persistent tag history', href: '../recipes/persistent-tag-history.md' }
---

# Exceptions

All exceptions extend
`PhpOpcua\Client\Exception\OpcUaException`, which extends
`RuntimeException`. Catch the base for "any OPC UA problem",
or the specific subclass for typed responses.

## Hierarchy

<!-- @code-block language="text" label="hierarchy" -->
```text
\RuntimeException
└── PhpOpcua\Client\Exception\OpcUaException
    ├── ConnectionException         — TCP / socket level
    ├── PolicyException              — security policy negotiation
    ├── CertificateException         — cert validation
    ├── AuthenticationException      — user-identity rejection
    ├── ServiceException             — server returned Bad_*
    │   └── ServiceUnsupportedException — Bad_ServiceUnsupported
    ├── InactiveSessionException     — session timeout server-side
    ├── EncodingException            — wire codec failure
    └── ConfigurationException       — bad config at construction time
```
<!-- @endcode-block -->

## ConnectionException

TCP layer issues, server unreachable, broken socket.

| Field      | Type       | Meaning                             |
| ---------- | ---------- | ----------------------------------- |
| `endpoint`  | `?string`  | URL that failed                     |

**Causes:** server unreachable, firewall, TCP RST mid-flight.

**Symfony response:**

<!-- @code-block language="php" label="controller catch" -->
```php
try {
    $dv = $this->client->read('ns=2;s=Speed');
} catch (ConnectionException $e) {
    $this->logger->warning('PLC unreachable', ['endpoint' => $e->endpoint]);
    return $this->json(['error' => 'PLC unreachable'], 503);
}
```
<!-- @endcode-block -->

In a Messenger handler:

<!-- @code-block language="php" label="messenger catch" -->
```php
try {
    $dv = $this->client->read('ns=2;s=Speed');
} catch (ConnectionException $e) {
    throw new RecoverableMessageHandlingException('Transient', 0, $e);
}
```
<!-- @endcode-block -->

## PolicyException

Requested security policy isn't offered by the server.

**Causes:** typo in policy name; server too old to support
modern policies (e.g., ECC).

**Response:** configuration bug. Use `vendor/bin/opcua-cli
discover` to see what the server actually offers.

## CertificateException

Cert validation failure during handshake.

| Field          | Type      | Meaning                                            |
| -------------- | --------- | -------------------------------------------------- |
| `fingerprint`  | `?string` | SHA-256 of the offending cert                       |
| `reason`        | `string`  | `'untrusted'`, `'expired'`, `'hostname-mismatch'`, … |

**Symfony response:** operator-level. Trigger a Notifier alert
and surface to admin UI. See [Trust store](../security/trust-store.md).

## AuthenticationException

User-identity rejection.

**Causes:** wrong username/password; locked-out user;
user-cert not in server trust list.

**Response:** generic error to the operator (don't leak whether
the username or password was wrong).

## ServiceException

Server returned a `Bad_*` status. The connection is healthy;
the operation was rejected.

| Field         | Type      | Meaning                                |
| ------------- | --------- | -------------------------------------- |
| `statusCode`   | `int`     | OPC UA status code (numeric)            |
| `statusName`    | `string`  | Human name (`Bad_NodeIdInvalid`, …)     |

**Common causes:**

| Status name                 | Cause                                       |
| --------------------------- | ------------------------------------------- |
| `Bad_NodeIdInvalid`          | Node ID doesn't exist                       |
| `Bad_NodeIdUnknown`          | Node not in the address space                |
| `Bad_AttributeIdInvalid`     | Wrong attribute for the node type           |
| `Bad_TypeMismatch`           | Write value doesn't match expected type      |
| `Bad_NotWritable`            | Node isn't writable                          |
| `Bad_UserAccessDenied`       | User lacks permission                         |
| `Bad_OutOfRange`             | Value outside engineering range               |

**Response:** logic bug or permission misconfig. Surface the
`statusName` to the developer/operator.

<!-- @code-block language="php" label="targeted catch" -->
```php
try {
    $this->client->write('ns=2;s=Setpoint', 9999);
} catch (ServiceException $e) {
    if ($e->statusName === 'Bad_OutOfRange') {
        return $this->json(['error' => 'value out of range'], 422);
    }
    throw $e;
}
```
<!-- @endcode-block -->

## ServiceUnsupportedException

Specifically `Bad_ServiceUnsupported`. Subclass of
`ServiceException`, so existing `catch (ServiceException $e)`
keeps working.

**Cause:** server doesn't implement this service set (typical:
NodeManagement against UA-.NETStandard).

**Response:** feature isn't available for this server. Disable
the feature in the UI or fallback to a different approach.

## InactiveSessionException

Server timed out the session. The bundle reopens on the next
call automatically — but the current call fails.

**Response:** retry the call. If it persists, check the daemon's
`session_timeout` is **less** than the server's
`MaxSessionTimeout`.

## EncodingException

Wire codec failure. Typically a server-side bug or
protocol-version mismatch.

**Response:** report upstream. These are rare.

## ConfigurationException

Bad config at construction (missing required keys, conflicting
options, bad cert path).

**Response:** fix the config; the exception message points at
the field. Symfony's container compilation catches these at
boot time.

## Symfony exception handler

For unhandled OPC UA exceptions, register a kernel exception
listener:

<!-- @code-block language="php" label="src/EventListener/OpcuaExceptionListener.php" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Exception\{ConnectionException, InactiveSessionException, ServiceException};
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

final class OpcuaExceptionListener
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.opcua')]
        private LoggerInterface $logger,
    ) {}

    #[AsEventListener(event: ExceptionEvent::class, priority: 0)]
    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();

        if ($e instanceof ConnectionException || $e instanceof InactiveSessionException) {
            $this->logger->warning($e->getMessage());
            $event->setResponse(new JsonResponse(['error' => 'OPC UA unavailable'], 503));
            return;
        }

        if ($e instanceof ServiceException) {
            $this->logger->error('OPC UA service error', [
                'status' => $e->statusName ?? 'unknown',
                'message' => $e->getMessage(),
            ]);
            $event->setResponse(new JsonResponse([
                'error'   => 'OPC UA service error',
                'status'  => $e->statusName ?? 'unknown',
                'message' => $e->getMessage(),
            ], 502));
        }
    }
}
```
<!-- @endcode-block -->

Centralises error responses across all controllers.

## Sentry / Bugsnag

Fingerprint by status code (not message):

<!-- @code-block language="php" label="Sentry scope" -->
```php
use Sentry\State\Scope;

\Sentry\configureScope(function (Scope $scope) use ($e) {
    if ($e instanceof ServiceException) {
        $scope->setFingerprint([
            '{{ default }}',
            'opcua-service',
            $e->statusName ?? 'unknown',
        ]);
    }
});
```
<!-- @endcode-block -->

Groups `Bad_NodeIdInvalid` separately from `Bad_TypeMismatch`.

## In tests

<!-- @code-block language="php" label="throw in mock" -->
```php
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Testing\MockClient;

$mock = MockClient::create()
    ->onRead('ns=2;s=Speed', function () {
        throw new ConnectionException('PLC down');
    });

static::getContainer()->set(OpcUaClientInterface::class, $mock);

$client->request('GET', '/api/plc/speed');
$this->assertResponseStatusCodeSame(503);
```
<!-- @endcode-block -->

## Where to read next

You've finished **Reference**. Next:
[Recipes · Persistent tag history](../recipes/persistent-tag-history.md) —
the canonical end-to-end pipeline.
