<?php

declare(strict_types=1);

namespace PhpOpcua\SymfonyOpcua;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\SymfonyOpcua\Command\SessionCommand;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Symfony bundle for OPC UA client integration with optional session manager support.
 */
class PhpOpcuaSymfonyOpcuaBundle extends AbstractBundle
{
    /**
     * @param DefinitionConfigurator $definition
     * @return void
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        /** @phpstan-ignore-next-line */
        $definition->rootNode()
            ->children()
                ->scalarNode('default')
                    ->defaultValue('default')
                ->end()
                ->arrayNode('session_manager')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('socket_path')
                            ->defaultValue('%kernel.project_dir%/var/opcua-session-manager.sock')
                        ->end()
                        ->integerNode('timeout')
                            ->defaultValue(600)
                        ->end()
                        ->integerNode('cleanup_interval')
                            ->defaultValue(30)
                        ->end()
                        ->scalarNode('auth_token')
                            ->defaultNull()
                        ->end()
                        ->integerNode('max_sessions')
                            ->defaultValue(100)
                        ->end()
                        ->integerNode('socket_mode')
                            ->defaultValue(0600)
                        ->end()
                        ->arrayNode('allowed_cert_dirs')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->scalarNode('log_channel')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('cache_pool')
                            ->defaultValue('cache.app')
                        ->end()
                        ->booleanNode('auto_publish')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('connections')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('endpoint')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('security_policy')
                                ->defaultValue('None')
                            ->end()
                            ->scalarNode('security_mode')
                                ->defaultValue('None')
                            ->end()
                            ->scalarNode('username')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('password')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('client_certificate')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('client_key')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('ca_certificate')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('user_certificate')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('user_key')
                                ->defaultNull()
                            ->end()
                            ->floatNode('timeout')
                                ->defaultValue(5.0)
                            ->end()
                            ->integerNode('auto_retry')
                                ->defaultNull()
                            ->end()
                            ->integerNode('batch_size')
                                ->defaultNull()
                            ->end()
                            ->integerNode('browse_max_depth')
                                ->defaultValue(10)
                            ->end()
                            ->scalarNode('trust_store_path')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('trust_policy')
                                ->defaultNull()
                            ->end()
                            ->booleanNode('auto_accept')
                                ->defaultFalse()
                            ->end()
                            ->booleanNode('auto_accept_force')
                                ->defaultFalse()
                            ->end()
                            ->booleanNode('auto_detect_write_type')
                                ->defaultTrue()
                            ->end()
                            ->booleanNode('read_metadata_cache')
                                ->defaultFalse()
                            ->end()
                            ->booleanNode('auto_connect')
                                ->defaultFalse()
                            ->end()
                            ->arrayNode('subscriptions')
                                ->arrayPrototype()
                                    ->children()
                                        ->floatNode('publishing_interval')
                                            ->defaultValue(500.0)
                                        ->end()
                                        ->integerNode('max_keep_alive_count')
                                            ->defaultValue(5)
                                        ->end()
                                        ->arrayNode('monitored_items')
                                            ->arrayPrototype()
                                                ->children()
                                                    ->scalarNode('node_id')
                                                        ->isRequired()
                                                    ->end()
                                                    ->integerNode('client_handle')
                                                        ->isRequired()
                                                    ->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                        ->arrayNode('event_monitored_items')
                                            ->arrayPrototype()
                                                ->children()
                                                    ->scalarNode('node_id')
                                                        ->isRequired()
                                                    ->end()
                                                    ->integerNode('client_handle')
                                                        ->isRequired()
                                                    ->end()
                                                    ->arrayNode('select_fields')
                                                        ->scalarPrototype()->end()
                                                        ->defaultValue(['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'])
                                                    ->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array $config
     * @param ContainerConfigurator $container
     * @param ContainerBuilder $builder
     * @return void
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $opcuaConfig = [
            'default' => $config['default'],
            'session_manager' => $config['session_manager'],
            'connections' => $config['connections'],
        ];

        $logChannel = $config['session_manager']['log_channel'] ?? null;
        $loggerServiceId = $logChannel !== null ? 'monolog.logger.' . $logChannel : 'logger';
        $cachePool = $config['session_manager']['cache_pool'] ?? 'cache.app';

        $services->set('php_opcua.psr16_cache', Psr16Cache::class)
            ->args([new Reference($cachePool)]);

        $services->set(OpcuaManager::class)
            ->args([
                $opcuaConfig,
                new Reference($loggerServiceId),
                new Reference('php_opcua.psr16_cache'),
                new Reference(EventDispatcherInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->public();

        $services->alias('opcua', OpcuaManager::class)
            ->public();

        $builder->register(OpcUaClientInterface::class)
            ->setFactory([new Reference(OpcuaManager::class), 'connection'])
            ->addArgument(null)
            ->setPublic(true);

        $services->set(SessionCommand::class)
            ->args([
                $config['session_manager'],
                $config['connections'],
                new Reference($loggerServiceId),
                new Reference('php_opcua.psr16_cache'),
                new Reference(EventDispatcherInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->tag('console.command');
    }
}
