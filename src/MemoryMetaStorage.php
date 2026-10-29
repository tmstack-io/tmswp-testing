<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;

/**
 * メタをメモリに保存する MetaStorageInterface の実装。WordPress なしのユニットテストで、Kernel の既定のメタの
 * ストレージと差し替える(rules/meta.md の「テスト」のアプリ側の実装例と同じ)。
 *
 * 値はそのまま保存する(WordPress のメタと違い、false を '' に、数値を文字列に変えない)。同じ値かどうかは === で判定する。
 */
final class MemoryMetaStorage implements MetaStorageInterface
{
    /** @var array<string, non-empty-list<mixed>> 対象とキーごとの全行 */
    private array $rows = [];

    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        return \array_key_exists(self::slot($type, $objectId, $key), $this->rows);
    }

    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        return $this->rows[self::slot($type, $objectId, $key)][0] ?? null;
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        return $this->rows[self::slot($type, $objectId, $key)] ?? [];
    }

    public function getMultiple(ObjectType $type, int $objectId, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if ($this->has($type, $objectId, $key)) {
                $values[$key] = $this->get($type, $objectId, $key);
            }
        }

        return $values;
    }

    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool
    {
        return $this->setAll($type, $objectId, $key, [$value]);
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        foreach ($values as $value) {
            if ($value === null) {
                throw new InvalidArgumentException("meta '{$key}' cannot store null; use delete() to remove it");
            }
        }

        $slot = self::slot($type, $objectId, $key);
        $before = $this->rows[$slot] ?? [];

        if ($values === []) {
            unset($this->rows[$slot]);
        } else {
            $this->rows[$slot] = $values;
        }

        return $values !== $before;
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        $existed = $this->has($type, $objectId, $key);
        unset($this->rows[self::slot($type, $objectId, $key)]);

        return $existed;
    }

    private static function slot(ObjectType $type, int $objectId, string $key): string
    {
        return "{$type->value}:{$objectId}:{$key}";
    }
}
