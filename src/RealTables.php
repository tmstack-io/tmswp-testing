<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use Closure;
use Throwable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
use WP_UnitTestCase_Base;
use wpdb;

/**
 * WordPressのテストライブラリ(WP_UnitTestCase)のテストで、CREATE TABLEを一時テーブルに書き換えずに実際のテーブルを
 * 作り、テストの後に、そのテストが作ると宣言したテーブルだけを消す。
 *
 * テストライブラリは、各テストの中のCREATE TABLE・DROP TABLEを一時テーブルの文に書き換える。MySQLのSHOW TABLES・
 * SHOW TABLE STATUSは一時テーブルを返さず(MariaDBは11.2から返す)、MySQLは1つの文で同じ一時テーブルを2回参照
 * できない。そのため、Migratorの適用の後の照合(SHOW TABLESでテーブルの有無を確かめる)等は、MySQLでは一時テーブルで
 * 確かめられない。start()は、そのテストの間だけこの書き換えを外す(テストライブラリは、テストの後に書き換えを外し、
 * 次のテストの開始で付け直す)。
 *
 * 消すテーブルは、start()に渡した名前(接頭辞を除いた名前)のうち、start()の時点に実際のテーブルとして無かったものに
 * 限る。DB全体の差分は使わない(start()の後に別の接続が作ったテーブルを、このテストのものと取り違えないため)。
 * 宣言していないテーブルは消さないため、テストが作るテーブルはすべて宣言する。
 *
 * CREATE TABLE・ALTER TABLE・DROP TABLE等のDDLは暗黙にコミットする。DDLより前にそのテストが書いた行(ユーザー・
 * ターム・option等)は、テストの最後のロールバックで戻らない。そうした行は、onTearDown()で消すか、DDLの後に書く。
 *
 * tearDown()の順序: (1) ROLLBACKで、最後のDDLより後の書き込みを戻す。(2) オブジェクトキャッシュを空にする(ロール
 * バックの前の値を読まないため)。(3) onTearDown()の後始末を登録の順に実行する。(4) 消す対象のテーブルを、名前で
 * 1つずつDROP TABLEする。(5) COMMITで、後始末の削除を確定させる。どの段が失敗しても残りを続け、最後に、起きた順で
 * 最初の失敗を投げる。
 */
final class RealTables
{
    /** @var list<Closure(): void> */
    private array $cleanups = [];

    /**
     * @param list<string> $ownedTables tearDown()で消すテーブル(接頭辞を含む名前)
     */
    private function __construct(
        private readonly wpdb $wpdb,
        private readonly array $ownedTables,
    ) {}

    /**
     * $testCaseのテストの間、CREATE TABLE・DROP TABLEの一時テーブルへの書き換えを外す。テストでテーブルを作る前
     * (set_up等)に呼び、テストのtear_downでtearDown()を呼ぶ。
     *
     * @param string ...$tables このテストが作るテーブルの、接頭辞($wpdb->prefix)を除いた名前。この時点で実際の
     *                          テーブルとして既にあるものは、tearDown()で消さない
     *
     * @throws InvalidArgumentException 名前が英数字と`_`でない場合
     * @throws RuntimeException テーブルの有無を確かめられない場合
     */
    public static function start(WP_UnitTestCase_Base $testCase, wpdb $wpdb, string ...$tables): self
    {
        $owned = [];

        foreach ($tables as $table) {
            if (preg_match('/\A[A-Za-z0-9_]+\z/', $table) !== 1) {
                throw new InvalidArgumentException("invalid table name: {$table}");
            }

            $name = $wpdb->prefix . $table;

            if (!self::baseTableExists($wpdb, $name)) {
                $owned[] = $name;
            }
        }

        remove_filter('query', [$testCase, '_create_temporary_tables']);
        remove_filter('query', [$testCase, '_drop_temporary_tables']);

        return new self($wpdb, array_values(array_unique($owned)));
    }

    /**
     * tearDown()のロールバックの後に実行する後始末を登録する。DDLより前に書いてコミットされた行を消すのに使う。
     * 消すときは、そのテストが作ったIDや名前を控えて、それだけを消す。
     *
     * @param Closure(): void $cleanup
     */
    public function onTearDown(Closure $cleanup): void
    {
        $this->cleanups[] = $cleanup;
    }

    /**
     * 最後のDDLより後の書き込みを戻し、後始末を実行して、start()で宣言したテーブルのうち、その時点に無かったものを消す。
     *
     * @throws Throwable 起きた順で最初の失敗(後始末の例外、またはROLLBACK・DROP・COMMITの失敗のRuntimeException)
     */
    public function tearDown(): void
    {
        $failures = [];

        if ($this->wpdb->query('ROLLBACK') === false) {
            $failures[] = new RuntimeException("ROLLBACK failed: {$this->wpdb->last_error}");
        }

        wp_cache_flush();

        foreach ($this->cleanups as $cleanup) {
            try {
                $cleanup();
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        foreach ($this->ownedTables as $table) {
            if ($this->wpdb->query("DROP TABLE IF EXISTS `{$table}`") === false) {
                $failures[] = new RuntimeException("failed to drop the table {$table}: {$this->wpdb->last_error}");
            }
        }

        if ($this->wpdb->query('COMMIT') === false) {
            $failures[] = new RuntimeException("COMMIT failed: {$this->wpdb->last_error}");
        }

        if ($failures !== []) {
            throw $failures[0];
        }
    }

    /**
     * $tableが実際のテーブル(一時テーブル・ビューを除く)としてあるか。
     *
     * @throws RuntimeException 確かめられない場合
     */
    private static function baseTableExists(wpdb $wpdb, string $table): bool
    {
        $rows = $wpdb->get_results($wpdb->prepare('SHOW FULL TABLES LIKE %s', $wpdb->esc_like($table)), ARRAY_N);

        if ($wpdb->last_error !== '' || !\is_array($rows)) {
            throw new RuntimeException("cannot check whether the table {$table} exists: {$wpdb->last_error}");
        }

        foreach ($rows as $row) {
            if (\is_array($row) && ($row[0] ?? null) === $table && ($row[1] ?? null) === 'BASE TABLE') {
                return true;
            }
        }

        return false;
    }
}
