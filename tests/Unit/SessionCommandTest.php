<?php

declare(strict_types=1);

use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use PhpOpcua\SymfonyOpcua\Command\SessionCommand;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

function makeSmConfig(array $overrides = []): array
{
    return array_merge([
        'enabled' => false,
        'socket_path' => sys_get_temp_dir() . '/opcua-cmd-test-' . uniqid() . '.sock',
        'timeout' => 600,
        'cleanup_interval' => 30,
        'auth_token' => null,
        'max_sessions' => 100,
        'socket_mode' => 0600,
        'allowed_cert_dirs' => [],
        'log_channel' => null,
        'cache_pool' => 'cache.app',
        'auto_publish' => false,
    ], $overrides);
}

function makeConnectionsConfig(array $overrides = []): array
{
    return array_merge([
        'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
    ], $overrides);
}

/**
 * @return array{0: SessionCommand&object, 1: NullLogger, 2: CacheInterface&Mockery\MockInterface}
 */
function makeTestableSessionCommand(array $smOverrides = [], array $connOverrides = []): array
{
    $logger = new NullLogger();
    $cache = Mockery::mock(CacheInterface::class);
    $eventDispatcher = Mockery::mock(EventDispatcherInterface::class);

    $command = new class(
        makeSmConfig($smOverrides),
        makeConnectionsConfig($connOverrides),
        $logger,
        $cache,
        $eventDispatcher,
    ) extends SessionCommand {
        public array $capturedArgs = [];
        public ?SessionManagerDaemon $daemonMock = null;
        public ?array $autoConnectCalled = null;

        protected function createDaemon(
            string                    $socketPath,
            int                       $timeout,
            int                       $cleanupInterval,
            ?string                   $authToken,
            int                       $maxSessions,
            int                       $socketMode,
            ?array                    $allowedCertDirs,
            LoggerInterface           $logger,
            ?CacheInterface           $clientCache,
            ?EventDispatcherInterface $clientEventDispatcher = null,
            bool                      $autoPublish = false,
        ): SessionManagerDaemon {
            $this->capturedArgs = [
                'socketPath' => $socketPath,
                'timeout' => $timeout,
                'cleanupInterval' => $cleanupInterval,
                'authToken' => $authToken,
                'maxSessions' => $maxSessions,
                'socketMode' => $socketMode,
                'allowedCertDirs' => $allowedCertDirs,
                'logger' => $logger,
                'clientCache' => $clientCache,
                'clientEventDispatcher' => $clientEventDispatcher,
                'autoPublish' => $autoPublish,
            ];

            $this->daemonMock = Mockery::mock(SessionManagerDaemon::class);
            $this->daemonMock->shouldReceive('run')->once();
            $this->daemonMock->shouldReceive('autoConnect')->zeroOrMoreTimes()->andReturnUsing(function ($connections) {
                $this->autoConnectCalled = $connections;
            });

            return $this->daemonMock;
        }
    };

    return [$command, $logger, $cache];
}

function runSessionCommand(SessionCommand $command, array $options = []): string
{
    $tester = new CommandTester($command);
    $tester->execute($options);

    return $tester->getDisplay();
}

describe('SessionCommand', function () {

    afterEach(function () {
        Mockery::close();
    });

    describe('default config values', function () {

        it('passes config defaults to the daemon', function () {
            [$command] = makeTestableSessionCommand();

            runSessionCommand($command);

            expect($command->capturedArgs['timeout'])->toBe(600);
            expect($command->capturedArgs['cleanupInterval'])->toBe(30);
            expect($command->capturedArgs['maxSessions'])->toBe(100);
            expect($command->capturedArgs['socketMode'])->toBe(0600);
            expect($command->capturedArgs['authToken'])->toBeNull();
        });

        it('passes socket path from config', function () {
            [$command] = makeTestableSessionCommand([
                'socket_path' => '/tmp/custom-test.sock',
            ]);

            runSessionCommand($command);

            expect($command->capturedArgs['socketPath'])->toBe('/tmp/custom-test.sock');
        });

        it('passes auth token from config', function () {
            [$command] = makeTestableSessionCommand([
                'auth_token' => 'my-secret-token',
            ]);

            runSessionCommand($command);

            expect($command->capturedArgs['authToken'])->toBe('my-secret-token');
        });

        it('passes allowed cert dirs from config', function () {
            [$command] = makeTestableSessionCommand([
                'allowed_cert_dirs' => ['/etc/opcua/certs'],
            ]);

            runSessionCommand($command);

            expect($command->capturedArgs['allowedCertDirs'])->toBe(['/etc/opcua/certs']);
        });
    });

    describe('CLI option overrides', function () {

        it('--timeout overrides config', function () {
            [$command] = makeTestableSessionCommand();

            runSessionCommand($command, ['--timeout' => '300']);

            expect($command->capturedArgs['timeout'])->toBe(300);
        });

        it('--cleanup-interval overrides config', function () {
            [$command] = makeTestableSessionCommand();

            runSessionCommand($command, ['--cleanup-interval' => '10']);

            expect($command->capturedArgs['cleanupInterval'])->toBe(10);
        });

        it('--max-sessions overrides config', function () {
            [$command] = makeTestableSessionCommand();

            runSessionCommand($command, ['--max-sessions' => '50']);

            expect($command->capturedArgs['maxSessions'])->toBe(50);
        });

        it('--socket-mode overrides config as octal', function () {
            [$command] = makeTestableSessionCommand();

            runSessionCommand($command, ['--socket-mode' => '0660']);

            expect($command->capturedArgs['socketMode'])->toBe(0660);
        });
    });

    describe('output', function () {

        it('displays the startup info table', function () {
            [$command] = makeTestableSessionCommand([
                'socket_path' => '/tmp/test-output.sock',
                'timeout' => 120,
                'auth_token' => 'secret',
            ]);

            $output = runSessionCommand($command);

            expect($output)->toContain('Starting OPC UA Session Manager');
            expect($output)->toContain('/tmp/test-output.sock');
            expect($output)->toContain('120s');
            expect($output)->toContain('configured');
        });

        it('shows "none" when auth token is null', function () {
            [$command] = makeTestableSessionCommand([
                'auth_token' => null,
            ]);

            $output = runSessionCommand($command);

            expect($output)->toContain('none');
        });

        it('shows "any" when allowed cert dirs is empty', function () {
            [$command] = makeTestableSessionCommand([
                'allowed_cert_dirs' => [],
            ]);

            $output = runSessionCommand($command);

            expect($output)->toContain('any');
        });
    });

    describe('daemon creation', function () {

        it('calls daemon run()', function () {
            [$command] = makeTestableSessionCommand();

            runSessionCommand($command);

            expect(true)->toBeTrue();
        });

        it('creates socket directory if missing', function () {
            $dir = sys_get_temp_dir() . '/opcua-cmd-test-' . uniqid() . '/nested';
            $sockPath = $dir . '/test.sock';

            [$command] = makeTestableSessionCommand([
                'socket_path' => $sockPath,
            ]);

            runSessionCommand($command);

            expect(is_dir($dir))->toBeTrue();

            @rmdir($dir);
            @rmdir(dirname($dir));
        });
    });

    describe('auto-publish', function () {

        it('passes autoPublish=false by default', function () {
            [$command] = makeTestableSessionCommand();

            runSessionCommand($command);

            expect($command->capturedArgs['autoPublish'])->toBeFalse();
            expect($command->capturedArgs['clientEventDispatcher'])->toBeNull();
        });

        it('passes autoPublish=true and event dispatcher when enabled', function () {
            [$command] = makeTestableSessionCommand(['auto_publish' => true]);

            runSessionCommand($command);

            expect($command->capturedArgs['autoPublish'])->toBeTrue();
            expect($command->capturedArgs['clientEventDispatcher'])->toBeInstanceOf(EventDispatcherInterface::class);
        });

        it('displays auto-publish status in startup table', function () {
            [$command] = makeTestableSessionCommand(['auto_publish' => true]);

            $output = runSessionCommand($command);

            expect($output)->toContain('enabled');
        });

        it('displays disabled when auto-publish is off', function () {
            [$command] = makeTestableSessionCommand(['auto_publish' => false]);

            $output = runSessionCommand($command);

            expect($output)->toContain('disabled');
        });
    });

    describe('auto-connect', function () {

        it('calls autoConnect on daemon when connections have auto_connect', function () {
            [$command] = makeTestableSessionCommand(
                ['auto_publish' => true],
                [
                    'plc1' => [
                        'endpoint' => 'opc.tcp://plc1:4840',
                        'auto_connect' => true,
                        'subscriptions' => [
                            [
                                'publishing_interval' => 500.0,
                                'monitored_items' => [
                                    ['node_id' => 'ns=2;s=Temperature', 'client_handle' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
            );

            runSessionCommand($command);

            expect($command->autoConnectCalled)->toHaveKey('plc1');
            expect($command->autoConnectCalled['plc1']['endpoint'])->toBe('opc.tcp://plc1:4840');
            expect($command->autoConnectCalled['plc1']['subscriptions'])->toHaveCount(1);
        });

        it('skips connections without auto_connect', function () {
            [$command] = makeTestableSessionCommand(
                ['auto_publish' => true],
                [
                    'plc1' => [
                        'endpoint' => 'opc.tcp://plc1:4840',
                        'auto_connect' => true,
                        'subscriptions' => [['monitored_items' => [['node_id' => 'i=1', 'client_handle' => 1]]]],
                    ],
                    'historian' => [
                        'endpoint' => 'opc.tcp://historian:4840',
                    ],
                    'plc2' => [
                        'endpoint' => 'opc.tcp://plc2:4840',
                        'auto_connect' => false,
                        'subscriptions' => [['monitored_items' => [['node_id' => 'i=2', 'client_handle' => 2]]]],
                    ],
                ],
            );

            runSessionCommand($command);

            expect($command->autoConnectCalled)->toHaveCount(1);
            expect($command->autoConnectCalled)->toHaveKey('plc1');
            expect($command->autoConnectCalled)->not->toHaveKey('historian');
            expect($command->autoConnectCalled)->not->toHaveKey('plc2');
        });

        it('does not call autoConnect when auto_publish is disabled', function () {
            [$command] = makeTestableSessionCommand(
                ['auto_publish' => false],
                [
                    'plc1' => [
                        'endpoint' => 'opc.tcp://plc1:4840',
                        'auto_connect' => true,
                        'subscriptions' => [['monitored_items' => [['node_id' => 'i=1', 'client_handle' => 1]]]],
                    ],
                ],
            );

            runSessionCommand($command);

            expect($command->autoConnectCalled)->toBeNull();
        });
    });

    describe('mapToDaemonConfig', function () {

        it('maps config keys to daemon format', function () {
            $command = new SessionCommand(
                makeSmConfig(),
                makeConnectionsConfig(),
                new NullLogger(),
                Mockery::mock(CacheInterface::class),
            );
            $method = new ReflectionMethod(SessionCommand::class, 'mapToDaemonConfig');

            $result = $method->invoke($command, [
                'security_policy' => 'Basic256Sha256',
                'security_mode' => 'SignAndEncrypt',
                'username' => 'admin',
                'password' => 'secret',
                'timeout' => 3.0,
                'auto_retry' => 5,
                'batch_size' => 100,
                'browse_max_depth' => 15,
                'trust_store_path' => '/certs',
                'trust_policy' => 'fingerprint',
                'auto_accept' => true,
                'auto_accept_force' => true,
                'auto_detect_write_type' => true,
                'read_metadata_cache' => false,
            ]);

            expect($result['securityPolicy'])->toBe(SecurityPolicy::Basic256Sha256->value);
            expect($result['securityMode'])->toBe(SecurityMode::SignAndEncrypt->value);
            expect($result['username'])->toBe('admin');
            expect($result['password'])->toBe('secret');
            expect($result['opcuaTimeout'])->toBe(3.0);
            expect($result['autoRetry'])->toBe(5);
            expect($result['batchSize'])->toBe(100);
            expect($result['defaultBrowseMaxDepth'])->toBe(15);
            expect($result['trustStorePath'])->toBe('/certs');
            expect($result['trustPolicy'])->toBe('fingerprint');
            expect($result['autoAccept'])->toBeTrue();
            expect($result['autoAcceptForce'])->toBeTrue();
            expect($result['autoDetectWriteType'])->toBeTrue();
            expect($result['readMetadataCache'])->toBeFalse();
        });

        it('skips None security policy and mode', function () {
            $command = new SessionCommand(
                makeSmConfig(),
                makeConnectionsConfig(),
                new NullLogger(),
                Mockery::mock(CacheInterface::class),
            );
            $method = new ReflectionMethod(SessionCommand::class, 'mapToDaemonConfig');

            $result = $method->invoke($command, [
                'security_policy' => 'None',
                'security_mode' => 'None',
            ]);

            expect($result)->not->toHaveKey('securityPolicy');
            expect($result)->not->toHaveKey('securityMode');
        });

        it('skips empty/null values', function () {
            $command = new SessionCommand(
                makeSmConfig(),
                makeConnectionsConfig(),
                new NullLogger(),
                Mockery::mock(CacheInterface::class),
            );
            $method = new ReflectionMethod(SessionCommand::class, 'mapToDaemonConfig');

            $result = $method->invoke($command, [
                'username' => null,
                'password' => null,
                'client_certificate' => null,
            ]);

            expect($result)->not->toHaveKey('username');
            expect($result)->not->toHaveKey('password');
            expect($result)->not->toHaveKey('clientCertPath');
        });

        it('maps certificate paths', function () {
            $command = new SessionCommand(
                makeSmConfig(),
                makeConnectionsConfig(),
                new NullLogger(),
                Mockery::mock(CacheInterface::class),
            );
            $method = new ReflectionMethod(SessionCommand::class, 'mapToDaemonConfig');

            $result = $method->invoke($command, [
                'client_certificate' => '/certs/client.pem',
                'client_key' => '/certs/client.key',
                'ca_certificate' => '/certs/ca.pem',
                'user_certificate' => '/certs/user.pem',
                'user_key' => '/certs/user.key',
            ]);

            expect($result['clientCertPath'])->toBe('/certs/client.pem');
            expect($result['clientKeyPath'])->toBe('/certs/client.key');
            expect($result['caCertPath'])->toBe('/certs/ca.pem');
            expect($result['userCertPath'])->toBe('/certs/user.pem');
            expect($result['userKeyPath'])->toBe('/certs/user.key');
        });
    });

    describe('buildAutoConnectConfig', function () {

        it('filters connections by auto_connect and subscriptions', function () {
            $command = new SessionCommand(
                makeSmConfig(),
                [
                    'active' => [
                        'endpoint' => 'opc.tcp://plc:4840',
                        'auto_connect' => true,
                        'subscriptions' => [['monitored_items' => [['node_id' => 'i=1', 'client_handle' => 1]]]],
                    ],
                    'no-auto' => [
                        'endpoint' => 'opc.tcp://other:4840',
                        'subscriptions' => [['monitored_items' => [['node_id' => 'i=2', 'client_handle' => 2]]]],
                    ],
                    'no-subs' => [
                        'endpoint' => 'opc.tcp://bare:4840',
                        'auto_connect' => true,
                    ],
                ],
                new NullLogger(),
                Mockery::mock(CacheInterface::class),
            );
            $method = new ReflectionMethod(SessionCommand::class, 'buildAutoConnectConfig');

            $result = $method->invoke($command);

            expect($result)->toHaveCount(1);
            expect($result)->toHaveKey('active');
            expect($result['active']['endpoint'])->toBe('opc.tcp://plc:4840');
        });
    });
});
