<?php

declare(strict_types=1);

use PhpOpcua\SymfonyOpcua\PhpOpcuaSymfonyOpcuaBundle;
use Symfony\Component\Config\Definition\Configuration;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

function processConfig(array $config): array
{
    $bundle = new PhpOpcuaSymfonyOpcuaBundle();
    $extension = $bundle->getContainerExtension();
    $container = new ContainerBuilder();

    $configuration = $extension->getConfiguration([], $container);
    $processor = new Processor();

    return $processor->processConfiguration($configuration, [$config]);
}

describe('Configuration', function () {

    describe('defaults', function () {

        it('produces valid defaults with minimal config', function () {
            $result = processConfig([
                'connections' => [
                    'default' => [
                        'endpoint' => 'opc.tcp://localhost:4840',
                    ],
                ],
            ]);

            expect($result['default'])->toBe('default');
            expect($result['session_manager']['enabled'])->toBeTrue();
            expect($result['session_manager']['timeout'])->toBe(600);
            expect($result['session_manager']['cleanup_interval'])->toBe(30);
            expect($result['session_manager']['auth_token'])->toBeNull();
            expect($result['session_manager']['max_sessions'])->toBe(100);
            expect($result['session_manager']['socket_mode'])->toBe(0600);
            expect($result['session_manager']['cache_pool'])->toBe('cache.app');
            expect($result['session_manager']['auto_publish'])->toBeFalse();
        });

        it('produces valid connection defaults', function () {
            $result = processConfig([
                'connections' => [
                    'default' => [
                        'endpoint' => 'opc.tcp://localhost:4840',
                    ],
                ],
            ]);

            $conn = $result['connections']['default'];

            expect($conn['endpoint'])->toBe('opc.tcp://localhost:4840');
            expect($conn['security_policy'])->toBe('None');
            expect($conn['security_mode'])->toBe('None');
            expect($conn['username'])->toBeNull();
            expect($conn['password'])->toBeNull();
            expect($conn['timeout'])->toBe(5.0);
            expect($conn['browse_max_depth'])->toBe(10);
            expect($conn['auto_accept'])->toBeFalse();
            expect($conn['auto_accept_force'])->toBeFalse();
            expect($conn['auto_detect_write_type'])->toBeTrue();
            expect($conn['read_metadata_cache'])->toBeFalse();
            expect($conn['auto_connect'])->toBeFalse();
            expect($conn['subscriptions'])->toBe([]);
        });
    });

    describe('custom values', function () {

        it('accepts custom session_manager settings', function () {
            $result = processConfig([
                'session_manager' => [
                    'enabled' => false,
                    'timeout' => 300,
                    'max_sessions' => 50,
                    'auto_publish' => true,
                ],
                'connections' => [
                    'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
                ],
            ]);

            expect($result['session_manager']['enabled'])->toBeFalse();
            expect($result['session_manager']['timeout'])->toBe(300);
            expect($result['session_manager']['max_sessions'])->toBe(50);
            expect($result['session_manager']['auto_publish'])->toBeTrue();
        });

        it('accepts custom connection settings', function () {
            $result = processConfig([
                'connections' => [
                    'plc1' => [
                        'endpoint' => 'opc.tcp://plc1:4840',
                        'security_policy' => 'Basic256Sha256',
                        'security_mode' => 'SignAndEncrypt',
                        'username' => 'admin',
                        'password' => 'secret',
                        'timeout' => 10.0,
                        'auto_retry' => 3,
                        'batch_size' => 100,
                        'browse_max_depth' => 20,
                        'auto_accept' => true,
                        'auto_detect_write_type' => false,
                    ],
                ],
            ]);

            $conn = $result['connections']['plc1'];

            expect($conn['security_policy'])->toBe('Basic256Sha256');
            expect($conn['security_mode'])->toBe('SignAndEncrypt');
            expect($conn['username'])->toBe('admin');
            expect($conn['password'])->toBe('secret');
            expect($conn['timeout'])->toBe(10.0);
            expect($conn['auto_retry'])->toBe(3);
            expect($conn['batch_size'])->toBe(100);
            expect($conn['browse_max_depth'])->toBe(20);
            expect($conn['auto_accept'])->toBeTrue();
            expect($conn['auto_detect_write_type'])->toBeFalse();
        });

        it('supports multiple connections', function () {
            $result = processConfig([
                'default' => 'plc1',
                'connections' => [
                    'plc1' => ['endpoint' => 'opc.tcp://plc1:4840'],
                    'plc2' => ['endpoint' => 'opc.tcp://plc2:4840'],
                    'historian' => ['endpoint' => 'opc.tcp://historian:4840'],
                ],
            ]);

            expect($result['default'])->toBe('plc1');
            expect($result['connections'])->toHaveCount(3);
            expect($result['connections'])->toHaveKeys(['plc1', 'plc2', 'historian']);
        });
    });

    describe('subscriptions', function () {

        it('accepts subscription config with monitored items', function () {
            $result = processConfig([
                'connections' => [
                    'default' => [
                        'endpoint' => 'opc.tcp://localhost:4840',
                        'auto_connect' => true,
                        'subscriptions' => [
                            [
                                'publishing_interval' => 500.0,
                                'max_keep_alive_count' => 5,
                                'monitored_items' => [
                                    ['node_id' => 'ns=2;s=Temperature', 'client_handle' => 1],
                                    ['node_id' => 'ns=2;s=Pressure', 'client_handle' => 2],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $subs = $result['connections']['default']['subscriptions'];

            expect($subs)->toHaveCount(1);
            expect($subs[0]['publishing_interval'])->toBe(500.0);
            expect($subs[0]['max_keep_alive_count'])->toBe(5);
            expect($subs[0]['monitored_items'])->toHaveCount(2);
            expect($subs[0]['monitored_items'][0]['node_id'])->toBe('ns=2;s=Temperature');
            expect($subs[0]['monitored_items'][0]['client_handle'])->toBe(1);
        });

        it('accepts event monitored items with select fields', function () {
            $result = processConfig([
                'connections' => [
                    'default' => [
                        'endpoint' => 'opc.tcp://localhost:4840',
                        'subscriptions' => [
                            [
                                'event_monitored_items' => [
                                    [
                                        'node_id' => 'i=2253',
                                        'client_handle' => 10,
                                        'select_fields' => ['EventId', 'Message'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $eventItems = $result['connections']['default']['subscriptions'][0]['event_monitored_items'];

            expect($eventItems)->toHaveCount(1);
            expect($eventItems[0]['node_id'])->toBe('i=2253');
            expect($eventItems[0]['select_fields'])->toBe(['EventId', 'Message']);
        });

        it('uses default select_fields for event monitored items', function () {
            $result = processConfig([
                'connections' => [
                    'default' => [
                        'endpoint' => 'opc.tcp://localhost:4840',
                        'subscriptions' => [
                            [
                                'event_monitored_items' => [
                                    ['node_id' => 'i=2253', 'client_handle' => 10],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $selectFields = $result['connections']['default']['subscriptions'][0]['event_monitored_items'][0]['select_fields'];

            expect($selectFields)->toBe(['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity']);
        });
    });

    describe('validation', function () {

        it('requires endpoint in connections', function () {
            expect(fn() => processConfig([
                'connections' => [
                    'default' => [],
                ],
            ]))->toThrow(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        });
    });
});
