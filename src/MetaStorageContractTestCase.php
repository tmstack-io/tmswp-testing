<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use TMSWP\Core\Exception\RuntimeException;

/**
 * WordPressのデータベース($wpdb)に保存するMetaStorageInterfaceの実装の契約を確かめる抽象テストケース。
 *
 * データベースに依存しない契約はMetaStorageContractから受け継ぎ、ここではデータベースのエラーの契約を加える。
 * 実装ごとに、このクラスを継承した具体的なテストクラスを置き、storage()・objectType()・createObject()を実装する。
 *
 * データベースのエラーは、そのキーの書き込みのSQLを存在しないテーブルへのSQLに書き換えて起こす。
 */
abstract class MetaStorageContractTestCase extends IntegrationTestCase
{
    use MetaStorageContract;

    /**
     * 書き込みの間のデータベースのエラーは、false(書き込まなかった)ではなく、データベースのエラーをメッセージに含む
     * RuntimeExceptionになり、保存されていた値は変わらない。
     */
    public function testDatabaseErrorDuringWriteThrowsRuntimeException(): void
    {
        $storage = $this->storage();
        $id = $this->createObject();
        $key = $this->key('broken');
        $storage->set($this->objectType(), $id, $key, 'before');
        $wpdb = self::wpdb();
        $broken = 'UPDATE ' . $wpdb->prefix . 'tmswp_missing_table SET meta_value = 1';
        $breakQueries = static fn(string $query): string => str_contains($query, $key) && preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $query) === 1 ? $broken : $query;
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $breakQueries);

        try {
            $storage->set($this->objectType(), $id, $key, 'after');
            self::fail('RuntimeException was not thrown');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tmswp_missing_table', $exception->getMessage());
        } finally {
            remove_filter('query', $breakQueries);
            $wpdb->suppress_errors($suppressed);
        }

        wp_cache_flush();
        self::assertSame('before', $storage->get($this->objectType(), $id, $key));
    }
}
