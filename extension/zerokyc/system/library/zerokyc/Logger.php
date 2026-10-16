<?php
/**
 * ZeroKYC Pay for OpenCart 2.3 — Logger wrapper.
 *
 * Writes structured entries to OpenCart's system/storage/logs/zerokyc.log.
 * Never logs API keys, webhook secrets, or raw signature headers.
 */

declare(strict_types=1);

namespace ZeroKyc\OpenCart;

final class Logger
{
    private const REDACTED_KEYS = [
        'api_key',
        'webhook_secret',
        'secret',
        'signature',
        'x-zkp-signature',
        'authorization',
    ];

    /** @var object|null OpenCart \Log instance or compatible writer with write(string $msg) */
    private ?object $log;

    private bool $debugEnabled;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    public function __construct(?object $log = null, bool $debugEnabled = false)
    {
        $this->log          = $log;
        $this->debugEnabled = $debugEnabled;
    }

    /**
     * Detailed diagnostic message; written only when debug mode is enabled in settings.
     *
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        if (!$this->debugEnabled) {
            return;
        }
        $this->write('DEBUG', $message, $context);
    }

    /**
     * Recoverable warning; always logged regardless of debug mode.
     *
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /**
     * Critical alert (e.g. ReplayGuard mismatch, double-check failure); always logged.
     *
     * @param array<string, mixed> $context
     */
    public function alert(string $message, array $context = []): void
    {
        $this->write('ALERT', $message, $context);
    }

    /**
     * Returns recorded log entries (useful for unit tests).
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $safeContext     = $this->sanitize($context);
        $this->records[] = [
            'level'   => $level,
            'message' => $message,
            'context' => $safeContext,
        ];

        if ($this->log === null || !method_exists($this->log, 'write')) {
            return;
        }

        $line = '[ZeroKYC][' . $level . '] ' . $message;
        if ($safeContext !== []) {
            $encoded = json_encode($safeContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                $line .= ' ' . $encoded;
            }
        }

        $this->log->write($line);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, self::REDACTED_KEYS, true)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }
}
