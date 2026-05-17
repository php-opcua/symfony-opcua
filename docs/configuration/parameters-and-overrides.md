---
eyebrow: 'Docs · Configuration'
lede:    'Per-environment overrides, container parameters, and the DI patterns for decorating or replacing the OpcuaManager.'

see_also:
  - { href: './bundle-yaml.md',                meta: '6 min' }
  - { href: './environment-variables.md',      meta: '5 min' }
  - { href: '../testing/phpunit-and-pest-setup.md', meta: '5 min' }

prev: { label: 'Session manager',  href: './session-manager.md' }
next: { label: 'Manager vs interface', href: '../using-the-client/manager-vs-interface.md' }
---

# Parameters and overrides

Three layered ways to change the bundle's behaviour without
forking it:

1. **Per-environment YAML** — different config per Symfony env.
2. **Container parameters** — share values across multiple
   bundles.
3. **Service decoration / replacement** — change the `OpcuaManager`
   behaviour.

## Per-environment YAML

Symfony loads `config/packages/<bundle>.yaml` first, then
`config/packages/<env>/<bundle>.yaml`. The latter wins.

### Dev: chatty logging, no daemon

<!-- @code-block language="text" label="config/packages/dev/php_opcua_symfony_opcua.yaml" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        enabled: false      # never use the daemon in dev
    connections:
        default:
            log_channel: opcua
            timeout: 30.0    # dev PLCs are slow
```
<!-- @endcode-block -->

### Test: everything disabled

<!-- @code-block language="text" label="config/packages/test/php_opcua_symfony_opcua.yaml" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        enabled: false
    connections:
        default:
            endpoint: 'opc.tcp://localhost:14840'   # test server
            security_policy: None
            security_mode:   None
```
<!-- @endcode-block -->

See [Testing · PHPUnit and Pest setup](../testing/phpunit-and-pest-setup.md).

### Prod: secrets-loaded, strict

<!-- @code-block language="text" label="config/packages/prod/php_opcua_symfony_opcua.yaml" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        enabled:           true
        socket_path:       '%env(OPCUA_SOCKET_PATH)%'
        auth_token:        '%env(secret:OPCUA_AUTH_TOKEN)%'
        auto_publish:      true
        log_channel:       opcua
        cache_pool:        cache.redis
        allowed_cert_dirs: ['/etc/opcua/certs']
```
<!-- @endcode-block -->

Per-env YAML merges with the base — you only override what
changes per environment.

## Container parameters

If you want a value reusable across multiple bundles, declare a
container parameter and reference it:

<!-- @code-block language="text" label="config/services.yaml" -->
```text
parameters:
    app.opcua.endpoint: 'opc.tcp://plc.factory.local:4840'
    app.opcua.line_a:   'opc.tcp://plc-a.factory.local:4840'
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%app.opcua.endpoint%'
        plc-line-a:
            endpoint: '%app.opcua.line_a%'
```
<!-- @endcode-block -->

Same value also reachable from other services via container
injection.

## Service decoration

Decorate `OpcuaManager` to add cross-cutting behaviour
(logging, metrics, retries) without forking the bundle:

<!-- @code-block language="php" label="src/Opcua/LoggingOpcuaManager.php" -->
```php
namespace App\Opcua;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(decorates: OpcuaManager::class)]
final class LoggingOpcuaManager extends OpcuaManager
{
    public function __construct(
        private readonly OpcuaManager $inner,
        private readonly LoggerInterface $logger,
    ) {
        // Don't call parent constructor — we delegate everything to $inner.
    }

    public function connect(?string $name = null): OpcUaClientInterface
    {
        $this->logger->debug('OPC UA connect', ['name' => $name]);
        $client = $this->inner->connect($name);
        $this->logger->debug('OPC UA connected', ['name' => $name, 'class' => get_class($client)]);
        return $client;
    }

    public function disconnect(?string $name = null): void
    {
        $this->logger->debug('OPC UA disconnect', ['name' => $name]);
        $this->inner->disconnect($name);
    }

    // … delegate other methods as needed, or use __call:
    public function __call(string $method, array $args): mixed
    {
        return $this->inner->$method(...$args);
    }
}
```
<!-- @endcode-block -->

Now everywhere autowires `OpcuaManager`, your decorated class
is injected instead.

## Replacing the manager

For a more invasive change, replace the bundle's service
definition entirely:

<!-- @code-block language="text" label="config/services.yaml" -->
```text
services:
    PhpOpcua\SymfonyOpcua\OpcuaManager:
        class: App\Opcua\CustomOpcuaManager
        arguments:
            $config: '%app.opcua_config%'
            # … your own deps
```
<!-- @endcode-block -->

Be aware this **breaks the YAML semantic config** unless your
custom class accepts the same shape.

## Overriding the logger resolver

The bundle's `LoggerResolverFactory` produces a closure that maps
`log_channel` strings to `monolog.logger.<channel>` services.
You can swap the resolver for a custom one:

<!-- @code-block language="text" label="config/services.yaml" -->
```text
services:
    php_opcua.logger_resolver:
        class: Closure
        factory: ['App\Opcua\MyLoggerResolver', 'create']
        arguments:
            - !service { class: App\Opcua\LoggerRegistry }
```
<!-- @endcode-block -->

The factory must return a `\Closure(string): ?LoggerInterface`.

## Overriding the cache adapter

The bundle wraps the cache pool with `Psr16Cache`. To use a
different PSR-16 adapter (e.g., a tagged or pre-filled one):

<!-- @code-block language="text" label="config/services.yaml" -->
```text
services:
    php_opcua.psr16_cache:
        class: App\Opcua\CustomPsr16Cache
        arguments: ['@my_cache_pool']
```
<!-- @endcode-block -->

The id `php_opcua.psr16_cache` is what the bundle's internal
wiring references.

## Compiler passes (advanced)

For deeper customisation — for example, conditionally
registering a custom module via `ClientBuilder::addModule()` —
use a compiler pass:

<!-- @code-block language="php" label="src/DependencyInjection/Compiler/OpcuaModulesPass.php" -->
```php
namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class OpcuaModulesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasDefinition('PhpOpcua\\SymfonyOpcua\\OpcuaManager')) {
            return;
        }

        // E.g., set a parameter that a custom manager subclass reads
        $container->setParameter('app.opcua.custom_modules', [
            \App\Opcua\AcmeMachineModule::class,
        ]);
    }
}
```
<!-- @endcode-block -->

Register in `src/Kernel.php`:

<!-- @code-block language="php" label="src/Kernel.php" -->
```php
protected function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(new \App\DependencyInjection\Compiler\OpcuaModulesPass());
}
```
<!-- @endcode-block -->

The bundle doesn't expose `ClientBuilder::addModule()` directly
via YAML — for now, custom modules need a custom manager.

## Validating overrides

After any of the above:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console cache:clear
php bin/console debug:container PhpOpcua\\SymfonyOpcua\\OpcuaManager
php bin/console debug:autowiring opcua
```
<!-- @endcode-block -->

The second command shows the actual class, decorators in
order, and the constructor args the container resolved.

## Where to read next

- [Manager vs interface](../using-the-client/manager-vs-interface.md) —
  what code injects from your overridden services.
- [Reference · Bundle services](../reference/bundle-services.md) —
  the full service-id list.
