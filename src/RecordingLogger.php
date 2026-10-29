<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * 記録を出力せずに保持するロガー(ユニットテストと fixture のテーマ・プラグインのログのチャネルが使う)。
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
