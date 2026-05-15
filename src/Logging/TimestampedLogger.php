<?php

declare(strict_types=1);

namespace PhpOpcua\SymfonyOpcua\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

final class TimestampedLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private LoggerInterface $inner,
        private string $dateFormat = 'Y-m-d H:i:s.v',
    ) {}

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $timestamp = (new \DateTimeImmutable())->format($this->dateFormat);
        $this->inner->log($level, '[' . $timestamp . '] ' . $message, $context);
    }
}
