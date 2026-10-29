<?php

declare(strict_types=1);

namespace TMSWP\Testing\Tests\Integration;

use Closure;
use LogicException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RealTables;
use wpdb;

/**
 * RealTablesとIntegrationTestCase::useRealTables()が、実際のテーブルを作り、テストの後に、宣言したテーブルのうち
 * start()の時点に無かったものだけを消し、DDLの前にコミットされた行を後始末で消し、どの段が失敗しても残りを続けて
 * 起きた順で最初の失敗を投げることを、実物のDBで確かめる。RealTablesを直接使うテストは、tearDown()の後の状態を
 * テストの中で確かめる。
 */
final class RealTablesTest extends IntegrationTestCase
{
    private const string TABLE = 'tmswp_it_real_tables';

    private const string OTHER_TABLE = 'tmswp_it_real_tables_other';

    private const string FOREIGN_TABLE = 'tmswp_it_real_tables_foreign';

    private const string USE_TABLE = 'tmswp_it_real_tables_use';

    private const string COMMITTED_OPTION = 'tmswp_it_real_tables_committed';

    private const string ROLLED_BACK_OPTION = 'tmswp_it_real_tables_rolled_back';

    /** 別の接続(このテストで作った場合だけ。後始末で閉じる) */
    private ?wpdb $otherConnection = null;

    public function tear_down(): void
    {
        try {
            if ($this->otherConnection !== null) {
                $this->otherConnection->query('DROP TABLE IF EXISTS `' . $this->otherConnection->prefix . self::FOREIGN_TABLE . '`');
                $this->otherConnection->close();
                $this->otherConnection = null;
            }
        } finally {
            parent::tear_down();
        }
    }

    /**
     * DDLの前に書いた行はコミットされ、onTearDown()の後始末で消える。最後のDDLの後に書いた行はロールバックで戻る。
     * 宣言したテーブルは実際のテーブルで、tearDown()で消え、もとからあるテーブルは残る。
     */
    public function testTearDownDropsTheDeclaredTableAndRunsTheCleanupAfterRollback(): void
    {
        $wpdb = self::wpdb();
        $tables = RealTables::start($this, $wpdb, self::TABLE);
        add_option(self::COMMITTED_OPTION, 'before ddl', '', false);
        $tables->onTearDown(static function () use ($wpdb): void {
            $wpdb->delete($wpdb->options, ['option_name' => self::COMMITTED_OPTION]);
        });
        self::createTable($wpdb, self::TABLE);
        add_option(self::ROLLED_BACK_OPTION, 'after ddl', '', false);

        self::assertSame('BASE TABLE', self::tableType(self::TABLE));

        $tables->tearDown();

        self::assertNull(self::tableType(self::TABLE));
        self::assertSame('BASE TABLE', self::tableType('options'));
        self::assertNull(self::optionRow(self::COMMITTED_OPTION));
        self::assertNull(self::optionRow(self::ROLLED_BACK_OPTION));
    }

    /**
     * start()の後に別の接続が作ったテーブルと、宣言していないテーブルは、DB全体では新しくても消さない。
     */
    public function testTableCreatedByAnotherConnectionAfterStartIsKept(): void
    {
        $wpdb = self::wpdb();
        $tables = RealTables::start($this, $wpdb, self::TABLE);
        $other = $this->otherConnection();

        self::assertNotSame($wpdb->get_var('SELECT CONNECTION_ID()'), $other->get_var('SELECT CONNECTION_ID()'));

        self::createTable($wpdb, self::TABLE);
        self::createTable($other, self::FOREIGN_TABLE);

        $tables->tearDown();

        self::assertNull(self::tableType(self::TABLE));
        self::assertSame('BASE TABLE', self::tableType(self::FOREIGN_TABLE));
    }

    /**
     * 後始末が例外を投げても、残りの後始末とテーブルの削除を続け、最後に最初の例外を投げる。宣言したテーブルでも、
     * start()の時点に既にあったもの(別のRealTablesが作ったもの)は消さない。
     */
    public function testFailingCleanupDoesNotStopTheRestAndTablesThatExistedAtStartAreKept(): void
    {
        $wpdb = self::wpdb();
        $outer = RealTables::start($this, $wpdb, self::OTHER_TABLE);
        self::createTable($wpdb, self::OTHER_TABLE);
        $inner = RealTables::start($this, $wpdb, self::TABLE, self::OTHER_TABLE);
        self::createTable($wpdb, self::TABLE);
        add_option(self::COMMITTED_OPTION, 'committed by the alter table', '', false);
        self::assertTrue($wpdb->query("ALTER TABLE {$wpdb->prefix}" . self::TABLE . ' ADD COLUMN extra int(11) NULL'));
        $inner->onTearDown(static function (): void {
            throw new RuntimeException('cleanup failed');
        });
        $inner->onTearDown(static function () use ($wpdb): void {
            $wpdb->delete($wpdb->options, ['option_name' => self::COMMITTED_OPTION]);
        });

        self::assertFailure('cleanup failed', $inner);

        self::assertNull(self::tableType(self::TABLE));
        self::assertNull(self::optionRow(self::COMMITTED_OPTION));
        self::assertSame('BASE TABLE', self::tableType(self::OTHER_TABLE));

        $outer->tearDown();

        self::assertNull(self::tableType(self::OTHER_TABLE));
    }

    /**
     * ROLLBACKが失敗しても、後始末とテーブルの削除を続け、後の後始末の例外より先に起きたROLLBACKの失敗を投げる。
     */
    public function testRollbackFailureIsReportedFirstAfterTheRestRuns(): void
    {
        $wpdb = self::wpdb();
        $tables = RealTables::start($this, $wpdb, self::TABLE);
        self::createTable($wpdb, self::TABLE);
        $ran = false;
        $tables->onTearDown(static function () use (&$ran): void {
            $ran = true;

            throw new RuntimeException('cleanup failed');
        });

        $this->breakStatement('ROLLBACK', static fn() => self::assertFailure('ROLLBACK failed: ', $tables));

        self::assertTrue($ran);
        self::assertNull(self::tableType(self::TABLE));
    }

    /** COMMITが失敗しても、それまでの後始末とテーブルの削除は行い、COMMITの失敗を投げる。 */
    public function testCommitFailureIsReported(): void
    {
        $wpdb = self::wpdb();
        $tables = RealTables::start($this, $wpdb, self::TABLE);
        add_option(self::COMMITTED_OPTION, 'committed by the create table', '', false);
        self::createTable($wpdb, self::TABLE);
        $tables->onTearDown(static function () use ($wpdb): void {
            $wpdb->delete($wpdb->options, ['option_name' => self::COMMITTED_OPTION]);
        });

        $this->breakStatement('COMMIT', static fn() => self::assertFailure('COMMIT failed: ', $tables));
        // 失敗させたCOMMITの代わりに確定させる
        self::assertNotFalse($wpdb->query('COMMIT'));

        self::assertNull(self::tableType(self::TABLE));
        self::assertNull(self::optionRow(self::COMMITTED_OPTION));
    }

    /** useRealTables()で宣言して作ったテーブルは実際のテーブルで、次のテストを始める前(tear_down)に消える。 */
    public function testUseRealTablesCreatesARealTable(): void
    {
        $this->useRealTables(self::USE_TABLE);
        $wpdb = self::wpdb();

        self::createTable($wpdb, self::USE_TABLE);
        self::assertSame('BASE TABLE', self::tableType(self::USE_TABLE));
    }

    /**
     * @depends testUseRealTablesCreatesARealTable
     */
    public function testTearDownOfThePreviousTestDroppedTheTable(): void
    {
        self::assertNull(self::tableType(self::USE_TABLE));
    }

    public function testUseRealTablesTwiceInOneTestIsAnError(): void
    {
        $this->useRealTables();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('useRealTables() was already called in this test');
        $this->useRealTables();
    }

    public function testTableNameOtherThanAlphanumericAndUnderscoreIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid table name: bad`name');

        RealTables::start($this, self::wpdb(), 'bad`name');
    }

    /**
     * tearDown()が、$messageで始まるRuntimeExceptionを投げることを確かめる。
     *
     * @param non-empty-string $message
     */
    private static function assertFailure(string $message, RealTables $tables): void
    {
        try {
            $tables->tearDown();
        } catch (RuntimeException $failure) {
            self::assertStringStartsWith($message, $failure->getMessage());

            return;
        }

        self::fail("tearDown() must throw a failure starting with {$message}");
    }

    /**
     * $statementだけを構文エラーの文に差し替えて$callを実行する。エラーの表示は抑える。
     *
     * @param Closure(): void $call
     */
    private function breakStatement(string $statement, Closure $call): void
    {
        $wpdb = self::wpdb();
        $break = static fn(string $query): string => $query === $statement ? $statement . '_TMSWP_BROKEN' : $query;
        add_filter('query', $break);
        $suppressed = $wpdb->suppress_errors(true);

        try {
            $call();
        } finally {
            $wpdb->suppress_errors($suppressed);
            remove_filter('query', $break);
        }
    }

    /** このテストのwpdbと同じDB(tests/wp-tests-config.phpの設定)・接頭辞の、別の接続。 */
    private function otherConnection(): wpdb
    {
        $wpdb = self::wpdb();
        $other = new wpdb(self::config('DB_USER'), self::config('DB_PASSWORD'), self::config('DB_NAME'), self::config('DB_HOST'));
        $other->set_prefix($wpdb->prefix);

        return $this->otherConnection = $other;
    }

    /** tests/wp-tests-config.phpが定義したDBの接続の設定の値。 */
    private static function config(string $name): string
    {
        $value = \constant($name);
        self::assertIsString($value);

        return $value;
    }

    private static function createTable(wpdb $db, string $table): void
    {
        self::assertTrue($db->query("CREATE TABLE {$db->prefix}{$table} (id int(11) NOT NULL)"), $db->last_error);
    }

    /** 接頭辞を除いた名前のテーブルの種類(SHOW FULL TABLESのTable_type)。無ければnull。 */
    private static function tableType(string $table): ?string
    {
        $wpdb = self::wpdb();
        $row = $wpdb->get_row($wpdb->prepare('SHOW FULL TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . $table)), ARRAY_N);

        return \is_array($row) && \is_string($row[1] ?? null) ? $row[1] : null;
    }

    /** オブジェクトキャッシュを通さずに読んだoptionの値。行が無ければnull。 */
    private static function optionRow(string $name): ?string
    {
        $wpdb = self::wpdb();
        $value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name));

        return \is_string($value) ? $value : null;
    }
}
