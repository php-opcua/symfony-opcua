<?php

declare(strict_types=1);

namespace PhpOpcua\SymfonyOpcua\Logging;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class LoggerResolverFactory
{
    public static function create(ContainerInterface $loggerLocator): \Closure
    {
        return static function (string $channel) use ($loggerLocator): ?LoggerInterface {
            if (!$loggerLocator->has($channel)) {
                return null;
            }

            $logger = $loggerLocator->get($channel);

            return $logger instanceof LoggerInterface ? $logger : null;
        };
    }
}
