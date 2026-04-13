<?php

declare(strict_types=1);

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\SymfonyOpcua\Command\SessionCommand;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use PhpOpcua\SymfonyOpcua\PhpOpcuaSymfonyOpcuaBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

function buildContainer(array $rawConfig = []): ContainerBuilder
{
    $bundle = new PhpOpcuaSymfonyOpcuaBundle();
    $extension = $bundle->getContainerExtension();

    $builder = new ContainerBuilder();
    $builder->getParameterBag()->set('kernel.environment', 'test');
    $builder->getParameterBag()->set('kernel.build_dir', sys_get_temp_dir());
    $builder->getParameterBag()->set('kernel.container_class', 'TestContainer');
    $builder->getParameterBag()->set('kernel.project_dir', sys_get_temp_dir());
    $extension->load([$rawConfig], $builder);

    return $builder;
}

describe('PhpOpcuaSymfonyOpcuaBundle', function () {

    describe('configure', function () {

        it('creates a valid configuration', function () {
            $bundle = new PhpOpcuaSymfonyOpcuaBundle();
            $extension = $bundle->getContainerExtension();

            $container = new ContainerBuilder();
            $configuration = $extension->getConfiguration([], $container);

            expect($configuration)->not->toBeNull();
        });
    });

    describe('loadExtension', function () {

        it('registers OpcuaManager as a service', function () {
            $builder = buildContainer([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
                ],
            ]);

            expect($builder->hasDefinition(OpcuaManager::class))->toBeTrue();
        });

        it('registers opcua alias for OpcuaManager', function () {
            $builder = buildContainer([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
                ],
            ]);

            expect($builder->hasAlias('opcua'))->toBeTrue();
        });

        it('registers OpcUaClientInterface as a factory service', function () {
            $builder = buildContainer([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
                ],
            ]);

            expect($builder->hasDefinition(OpcUaClientInterface::class))->toBeTrue();

            $definition = $builder->getDefinition(OpcUaClientInterface::class);
            $factory = $definition->getFactory();

            expect($factory)->not->toBeNull();
        });

        it('registers SessionCommand with console.command tag', function () {
            $builder = buildContainer([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
                ],
            ]);

            expect($builder->hasDefinition(SessionCommand::class))->toBeTrue();

            $definition = $builder->getDefinition(SessionCommand::class);
            expect($definition->hasTag('console.command'))->toBeTrue();
        });

        it('registers PSR-16 cache adapter', function () {
            $builder = buildContainer([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
                ],
            ]);

            expect($builder->hasDefinition('php_opcua.psr16_cache'))->toBeTrue();
        });

        it('passes config to OpcuaManager', function () {
            $builder = buildContainer([
                'default' => 'plc1',
                'connections' => [
                    'plc1' => ['endpoint' => 'opc.tcp://plc1:4840'],
                ],
            ]);

            $definition = $builder->getDefinition(OpcuaManager::class);
            $args = $definition->getArguments();

            expect($args[0])->toBeArray();
            expect($args[0]['default'])->toBe('plc1');
            expect($args[0]['connections'])->toHaveKey('plc1');
        });

        it('passes session_manager config to SessionCommand', function () {
            $builder = buildContainer([
                'session_manager' => [
                    'timeout' => 300,
                ],
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
                ],
            ]);

            $definition = $builder->getDefinition(SessionCommand::class);
            $args = $definition->getArguments();

            expect($args[0])->toBeArray();
            expect($args[0]['timeout'])->toBe(300);
        });

        it('passes connections config to SessionCommand', function () {
            $builder = buildContainer([
                'connections' => [
                    'plc1' => ['endpoint' => 'opc.tcp://plc1:4840'],
                    'plc2' => ['endpoint' => 'opc.tcp://plc2:4840'],
                ],
            ]);

            $definition = $builder->getDefinition(SessionCommand::class);
            $args = $definition->getArguments();

            expect($args[1])->toBeArray();
            expect($args[1])->toHaveKeys(['plc1', 'plc2']);
        });

        it('uses custom log_channel for logger service reference', function () {
            $builder = buildContainer([
                'session_manager' => [
                    'log_channel' => 'opcua',
                ],
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
                ],
            ]);

            $definition = $builder->getDefinition(OpcuaManager::class);
            $loggerRef = $definition->getArguments()[1];

            expect((string) $loggerRef)->toBe('monolog.logger.opcua');
        });

        it('uses default logger when log_channel is null', function () {
            $builder = buildContainer([
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
                ],
            ]);

            $definition = $builder->getDefinition(OpcuaManager::class);
            $loggerRef = $definition->getArguments()[1];

            expect((string) $loggerRef)->toBe('logger');
        });

        it('uses custom cache_pool for cache service reference', function () {
            $builder = buildContainer([
                'session_manager' => [
                    'cache_pool' => 'cache.redis',
                ],
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
                ],
            ]);

            $definition = $builder->getDefinition('php_opcua.psr16_cache');
            $cacheRef = $definition->getArguments()[0];

            expect((string) $cacheRef)->toBe('cache.redis');
        });
    });
});
