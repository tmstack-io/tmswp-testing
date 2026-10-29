<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Option\OptionStorageInterface;

/**
 * オプションをメモリに保存する OptionStorageInterface の実装。WordPress なしのユニットテストで、Kernel の既定の
 * オプションのストレージと差し替える(rules/options.md の「テスト」のアプリ側の実装例と同じ)。
 *
 * 値はそのまま保存し、同じ値かどうかは === で判定する。autoload は扱わない。
 */
final class MemoryOptionStorage implements OptionStorageInterface
{
    /** @var array<string, mixed> 実オプション名ごとの値 */
    private array $values = [];

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->values);
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value, ?bool $autoload = null): bool
    {
        if ($value === null) {
            throw new InvalidArgumentException("option '{$name}' cannot store null; use delete() to remove it");
        }

        if ($this->has($name) && $this->values[$name] === $value) {
            return false;
        }

        $this->values[$name] = $value;

        return true;
    }

    public function delete(string $name): bool
    {
        $existed = $this->has($name);
        unset($this->values[$name]);

        return $existed;
    }
}
