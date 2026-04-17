<?php

declare(strict_types=1);

namespace PhpOpcua\SymfonyOpcua\Command;

use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use PhpOpcua\SessionManager\Ipc\TransportFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Symfony console command to start the OPC UA session manager daemon.
 */
#[AsCommand(
    name: 'opcua:session',
    description: 'Start the OPC UA session manager daemon',
)]
class SessionCommand extends Command
{
    /**
     * @param array $sessionManagerConfig
     * @param array $connectionsConfig
     * @param LoggerInterface $logger
     * @param CacheInterface $cache
     * @param ?EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        protected readonly array $sessionManagerConfig,
        protected readonly array $connectionsConfig,
        protected readonly LoggerInterface $logger,
        protected readonly CacheInterface $cache,
        protected readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Session inactivity timeout in seconds')
            ->addOption('cleanup-interval', null, InputOption::VALUE_REQUIRED, 'Cleanup check interval in seconds')
            ->addOption('max-sessions', null, InputOption::VALUE_REQUIRED, 'Maximum concurrent sessions')
            ->addOption('socket-mode', null, InputOption::VALUE_REQUIRED, 'Socket file permissions (octal)');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->sessionManagerConfig;

        $socketPath = $config['socket_path'];
        $timeout = $input->getOption('timeout') ?? $config['timeout'];
        $cleanupInterval = $input->getOption('cleanup-interval') ?? $config['cleanup_interval'];
        $maxSessions = $input->getOption('max-sessions') ?? $config['max_sessions'];
        $socketMode = $input->getOption('socket-mode') !== null
            ? intval($input->getOption('socket-mode'), 8)
            : $config['socket_mode'];

        $allowedCertDirs = !empty($config['allowed_cert_dirs']) ? $config['allowed_cert_dirs'] : null;
        $authToken = $config['auth_token'];

        $autoPublish = (bool) ($config['auto_publish'] ?? false);
        $eventDispatcher = $autoPublish ? $this->eventDispatcher : null;

        $unixPath = TransportFactory::toUnixPath($socketPath);
        if ($unixPath !== null) {
            $socketDir = dirname($unixPath);
            if (!is_dir($socketDir)) {
                mkdir($socketDir, 0755, true);
            }
        }

        $io->info('Starting OPC UA Session Manager...');

        $settings = [
            ['Endpoint', $socketPath],
            ['Timeout', $timeout . 's'],
            ['Cleanup Interval', $cleanupInterval . 's'],
            ['Max Sessions', (string) $maxSessions],
        ];
        if ($unixPath !== null) {
            $settings[] = ['Socket Mode', sprintf('0%o', $socketMode)];
        }
        $settings[] = ['Auth Token', $authToken ? 'configured' : 'none'];
        $settings[] = ['Cert Dirs', $allowedCertDirs ? implode(', ', $allowedCertDirs) : 'any'];
        $settings[] = ['Auto-publish', $autoPublish ? 'enabled' : 'disabled'];
        $io->table(['Setting', 'Value'], $settings);

        $daemon = $this->createDaemon(
            socketPath: $socketPath,
            timeout: (int) $timeout,
            cleanupInterval: (int) $cleanupInterval,
            authToken: $authToken,
            maxSessions: (int) $maxSessions,
            socketMode: $socketMode,
            allowedCertDirs: $allowedCertDirs,
            logger: $this->logger,
            clientCache: $this->cache,
            clientEventDispatcher: $eventDispatcher,
            autoPublish: $autoPublish,
        );

        if ($autoPublish) {
            $autoConnections = $this->buildAutoConnectConfig();
            if (!empty($autoConnections)) {
                $daemon->autoConnect($autoConnections);
                $io->info('Auto-connecting ' . count($autoConnections) . ' connection(s): ' . implode(', ', array_keys($autoConnections)));
            }
        }

        $daemon->run();

        return Command::SUCCESS;
    }

    /**
     * Create the session manager daemon instance.
     *
     * @param string $socketPath
     * @param int $timeout
     * @param int $cleanupInterval
     * @param ?string $authToken
     * @param int $maxSessions
     * @param int $socketMode
     * @param ?array $allowedCertDirs
     * @param LoggerInterface $logger
     * @param ?CacheInterface $clientCache
     * @param ?EventDispatcherInterface $clientEventDispatcher
     * @param bool $autoPublish
     * @return SessionManagerDaemon
     */
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
        return new SessionManagerDaemon(
            socketPath: $socketPath,
            timeout: $timeout,
            cleanupInterval: $cleanupInterval,
            authToken: $authToken,
            maxSessions: $maxSessions,
            socketMode: $socketMode,
            allowedCertDirs: $allowedCertDirs,
            logger: $logger,
            clientCache: $clientCache,
            clientEventDispatcher: $clientEventDispatcher,
            autoPublish: $autoPublish,
        );
    }

    /**
     * Build auto-connect configuration from connections that have auto_connect enabled.
     *
     * @return array<string, array{endpoint: string, config: array, subscriptions: array}>
     */
    protected function buildAutoConnectConfig(): array
    {
        $result = [];

        foreach ($this->connectionsConfig as $name => $config) {
            if (empty($config['auto_connect']) || empty($config['subscriptions'])) {
                continue;
            }

            $result[$name] = [
                'endpoint' => $config['endpoint'],
                'config' => $this->mapToDaemonConfig($config),
                'subscriptions' => $config['subscriptions'],
            ];
        }

        return $result;
    }

    /**
     * Map connection config keys to the daemon's CommandHandler config format.
     *
     * @param array $config
     * @return array
     */
    protected function mapToDaemonConfig(array $config): array
    {
        $daemon = [];

        if (!empty($config['security_policy']) && $config['security_policy'] !== 'None') {
            $daemon['securityPolicy'] = $this->resolveSecurityPolicyUri($config['security_policy']);
        }
        if (!empty($config['security_mode']) && $config['security_mode'] !== 'None') {
            $daemon['securityMode'] = $this->resolveSecurityModeValue($config['security_mode']);
        }
        if (!empty($config['username'])) {
            $daemon['username'] = $config['username'];
        }
        if (!empty($config['password'])) {
            $daemon['password'] = $config['password'];
        }
        if (!empty($config['client_certificate'])) {
            $daemon['clientCertPath'] = $config['client_certificate'];
        }
        if (!empty($config['client_key'])) {
            $daemon['clientKeyPath'] = $config['client_key'];
        }
        if (!empty($config['ca_certificate'])) {
            $daemon['caCertPath'] = $config['ca_certificate'];
        }
        if (!empty($config['user_certificate'])) {
            $daemon['userCertPath'] = $config['user_certificate'];
        }
        if (!empty($config['user_key'])) {
            $daemon['userKeyPath'] = $config['user_key'];
        }
        if (isset($config['timeout']) && $config['timeout'] !== null) {
            $daemon['opcuaTimeout'] = (float) $config['timeout'];
        }
        if (isset($config['auto_retry']) && $config['auto_retry'] !== null) {
            $daemon['autoRetry'] = (int) $config['auto_retry'];
        }
        if (isset($config['batch_size']) && $config['batch_size'] !== null) {
            $daemon['batchSize'] = (int) $config['batch_size'];
        }
        if (isset($config['browse_max_depth']) && $config['browse_max_depth'] !== null) {
            $daemon['defaultBrowseMaxDepth'] = (int) $config['browse_max_depth'];
        }
        if (!empty($config['trust_store_path'])) {
            $daemon['trustStorePath'] = $config['trust_store_path'];
        }
        if (!empty($config['trust_policy'])) {
            $daemon['trustPolicy'] = $config['trust_policy'];
        }
        if (!empty($config['auto_accept'])) {
            $daemon['autoAccept'] = true;
        }
        if (!empty($config['auto_accept_force'])) {
            $daemon['autoAcceptForce'] = true;
        }
        if (isset($config['auto_detect_write_type']) && $config['auto_detect_write_type'] !== null) {
            $daemon['autoDetectWriteType'] = (bool) $config['auto_detect_write_type'];
        }
        if (isset($config['read_metadata_cache']) && $config['read_metadata_cache'] !== null) {
            $daemon['readMetadataCache'] = (bool) $config['read_metadata_cache'];
        }

        return $daemon;
    }

    /**
     * Resolve a security policy short name to its OPC UA URI.
     *
     * @param string $policy
     * @return string
     */
    protected function resolveSecurityPolicyUri(string $policy): string
    {
        $map = [
            'None' => SecurityPolicy::None->value,
            'Basic128Rsa15' => SecurityPolicy::Basic128Rsa15->value,
            'Basic256' => SecurityPolicy::Basic256->value,
            'Basic256Sha256' => SecurityPolicy::Basic256Sha256->value,
            'Aes128Sha256RsaOaep' => SecurityPolicy::Aes128Sha256RsaOaep->value,
            'Aes256Sha256RsaPss' => SecurityPolicy::Aes256Sha256RsaPss->value,
            'ECC_nistP256' => SecurityPolicy::EccNistP256->value,
            'ECC_nistP384' => SecurityPolicy::EccNistP384->value,
            'ECC_brainpoolP256r1' => SecurityPolicy::EccBrainpoolP256r1->value,
            'ECC_brainpoolP384r1' => SecurityPolicy::EccBrainpoolP384r1->value,
        ];

        return $map[$policy] ?? $policy;
    }

    /**
     * Resolve a security mode name or integer to its enum integer value.
     *
     * @param string|int $mode
     * @return int
     */
    protected function resolveSecurityModeValue(string|int $mode): int
    {
        if (is_int($mode)) {
            return $mode;
        }

        return match ($mode) {
            'None' => SecurityMode::None->value,
            'Sign' => SecurityMode::Sign->value,
            'SignAndEncrypt' => SecurityMode::SignAndEncrypt->value,
            default => (int) $mode,
        };
    }
}
