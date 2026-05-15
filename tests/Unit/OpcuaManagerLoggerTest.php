<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilderInterface;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\SymfonyOpcua\Logging\TimestampedLogger;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

if (!function_exists('makeMinimalConfig')) {
    function makeMinimalConfig(): array
    {
        return [
            'default' => 'default',
            'session_manager' => [
                'enabled' => false,
                'socket_path' => '/tmp/nonexistent.sock',
            ],
            'connections' => [
                'default' => [
                    'endpoint' => 'opc.tcp://localhost:4840',
                ],
            ],
        ];
    }
}

/**
 * Coverage for the v4.3 logger additions on OpcuaManager:
 *  - resolveLogger() priority
 *  - log_channel config + loggerResolver closure
 *  - setLogger() runtime override + propagation
 *  - useConsoleLogger() + TimestampedLogger wrapping
 *  - getLogger()
 */
describe('OpcuaManager logger v4.3', function () {

    describe('resolveLogger priority', function () {

        it('uses runtime logger first when set', function () {
            $defaultLogger = Mockery::mock(LoggerInterface::class);
            $runtimeLogger = Mockery::mock(LoggerInterface::class);
            $configLogger = Mockery::mock(LoggerInterface::class);

            $manager = new OpcuaManager(makeMinimalConfig(), defaultLogger: $defaultLogger);
            $manager->setLogger($runtimeLogger);

            $resolved = (new ReflectionMethod($manager, 'resolveLogger'))
                ->invoke($manager, ['logger' => $configLogger, 'log_channel' => 'channel-x']);

            expect($resolved)->toBe($runtimeLogger);
        });

        it('uses config["logger"] when no runtime override', function () {
            $defaultLogger = Mockery::mock(LoggerInterface::class);
            $configLogger = Mockery::mock(LoggerInterface::class);

            $manager = new OpcuaManager(makeMinimalConfig(), defaultLogger: $defaultLogger);

            $resolved = (new ReflectionMethod($manager, 'resolveLogger'))
                ->invoke($manager, ['logger' => $configLogger]);

            expect($resolved)->toBe($configLogger);
        });

        it('uses log_channel via resolver when no config["logger"]', function () {
            $defaultLogger = Mockery::mock(LoggerInterface::class);
            $channelLogger = Mockery::mock(LoggerInterface::class);

            $resolver = static function (string $channel) use ($channelLogger): ?LoggerInterface {
                expect($channel)->toBe('plc-line-a');
                return $channelLogger;
            };

            $manager = new OpcuaManager(makeMinimalConfig(), defaultLogger: $defaultLogger, loggerResolver: $resolver);

            $resolved = (new ReflectionMethod($manager, 'resolveLogger'))
                ->invoke($manager, ['log_channel' => 'plc-line-a']);

            expect($resolved)->toBe($channelLogger);
        });

        it('falls back to default when resolver returns null', function () {
            $defaultLogger = Mockery::mock(LoggerInterface::class);

            $resolver = static fn(string $channel): ?LoggerInterface => null;

            $manager = new OpcuaManager(makeMinimalConfig(), defaultLogger: $defaultLogger, loggerResolver: $resolver);

            $resolved = (new ReflectionMethod($manager, 'resolveLogger'))
                ->invoke($manager, ['log_channel' => 'unknown-channel']);

            expect($resolved)->toBe($defaultLogger);
        });

        it('falls back to default when log_channel is empty / non-string', function () {
            $defaultLogger = Mockery::mock(LoggerInterface::class);
            $manager = new OpcuaManager(makeMinimalConfig(), defaultLogger: $defaultLogger, loggerResolver: static fn() => Mockery::mock(LoggerInterface::class));

            $resolved = (new ReflectionMethod($manager, 'resolveLogger'))
                ->invoke($manager, ['log_channel' => '']);

            expect($resolved)->toBe($defaultLogger);
        });

        it('returns null when nothing is configured', function () {
            $manager = new OpcuaManager(makeMinimalConfig());

            $resolved = (new ReflectionMethod($manager, 'resolveLogger'))
                ->invoke($manager, []);

            expect($resolved)->toBeNull();
        });
    });

    describe('setLogger / getLogger', function () {

        it('stores the runtime logger and getLogger returns it', function () {
            $logger = Mockery::mock(LoggerInterface::class);
            $manager = new OpcuaManager(makeMinimalConfig());

            expect($manager->getLogger())->toBeNull();
            $manager->setLogger($logger);
            expect($manager->getLogger())->toBe($logger);
        });

        it('returns $this for chaining', function () {
            $manager = new OpcuaManager(makeMinimalConfig());
            $logger = Mockery::mock(LoggerInterface::class);
            expect($manager->setLogger($logger))->toBe($manager);
        });

        it('propagates to existing connections (best-effort)', function () {
            $manager = new OpcuaManager(makeMinimalConfig());
            $logger = Mockery::mock(LoggerInterface::class);

            // Need a real (or class-mocked) object that exposes setLogger() so
            // `method_exists()` returns true. Bare interface mocks don't expose
            // methods to method_exists, so we use a simple anonymous class.
            $captured = null;
            $client = new class($captured) {
                public function __construct(public ?LoggerInterface &$captured) {}
                public function setLogger(LoggerInterface $logger): void
                {
                    $this->captured = $logger;
                }
            };

            $prop = new ReflectionProperty($manager, 'connections');
            $prop->setValue($manager, ['default' => $client]);

            $manager->setLogger($logger);

            expect($captured)->toBe($logger);
        });

        it('skips connections that do not expose setLogger', function () {
            $manager = new OpcuaManager(makeMinimalConfig());
            $logger = Mockery::mock(LoggerInterface::class);

            $client = new class {
                // intentionally no setLogger()
            };

            $prop = new ReflectionProperty($manager, 'connections');
            $prop->setValue($manager, ['default' => $client]);

            // no exception, no error
            $manager->setLogger($logger);
            expect($manager->getLogger())->toBe($logger);
        });
    });

    describe('useConsoleLogger', function () {

        it('wraps the ConsoleLogger in a TimestampedLogger by default', function () {
            $manager = new OpcuaManager(makeMinimalConfig());
            $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);

            $manager->useConsoleLogger($output);

            $logger = $manager->getLogger();
            expect($logger)->toBeInstanceOf(TimestampedLogger::class);
        });

        it('emits a plain ConsoleLogger when dateFormat is null', function () {
            $manager = new OpcuaManager(makeMinimalConfig());
            $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);

            $manager->useConsoleLogger($output, dateFormat: null);

            $logger = $manager->getLogger();
            expect($logger)->toBeInstanceOf(ConsoleLogger::class);
            expect($logger)->not->toBeInstanceOf(TimestampedLogger::class);
        });

        it('returns $this for chaining', function () {
            $manager = new OpcuaManager(makeMinimalConfig());
            $output = new BufferedOutput();
            expect($manager->useConsoleLogger($output))->toBe($manager);
        });
    });

    describe('log_channel wired through configureBuilder', function () {

        it('honours the log_channel config key via loggerResolver', function () {
            $channelLogger = Mockery::mock(LoggerInterface::class);
            $resolver = static function (string $channel) use ($channelLogger): ?LoggerInterface {
                return $channel === 'plc' ? $channelLogger : null;
            };

            $manager = new OpcuaManager(makeMinimalConfig(), loggerResolver: $resolver);
            $builder = Mockery::mock(ClientBuilderInterface::class);
            $builder->shouldReceive('setLogger')->once()->with($channelLogger)->andReturnSelf();

            (new ReflectionMethod($manager, 'configureBuilder'))
                ->invoke($manager, $builder, ['log_channel' => 'plc']);
        });

        it('log_channel falls back to default when resolver returns null', function () {
            $default = Mockery::mock(LoggerInterface::class);
            $resolver = static fn(string $channel): ?LoggerInterface => null;

            $manager = new OpcuaManager(makeMinimalConfig(), defaultLogger: $default, loggerResolver: $resolver);
            $builder = Mockery::mock(ClientBuilderInterface::class);
            $builder->shouldReceive('setLogger')->once()->with($default)->andReturnSelf();

            (new ReflectionMethod($manager, 'configureBuilder'))
                ->invoke($manager, $builder, ['log_channel' => 'no-such-channel']);
        });
    });
});

describe('TimestampedLogger', function () {

    it('prepends a timestamp before delegating', function () {
        $captured = [];
        $inner = new class($captured) implements LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public function __construct(public array &$captured) {}
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->captured[] = (string) $message;
            }
        };

        $logger = new TimestampedLogger($inner, 'Y-m-d');
        $logger->info('hello');

        expect($inner->captured)->toHaveCount(1);
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        expect($inner->captured[0])->toStartWith('[' . $today . '] ');
        expect($inner->captured[0])->toEndWith('hello');
    });

    it('respects a custom date format', function () {
        $captured = [];
        $inner = new class($captured) implements LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public function __construct(public array &$captured) {}
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->captured[] = (string) $message;
            }
        };

        // 'H' = 24-hour hour; using a format the wall clock can satisfy
        // makes the assertion deterministic across runs.
        $logger = new TimestampedLogger($inner, 'H');
        $logger->warning('boom');

        $hour = (new \DateTimeImmutable())->format('H');
        expect($inner->captured[0])->toBe('[' . $hour . '] boom');
    });
});
