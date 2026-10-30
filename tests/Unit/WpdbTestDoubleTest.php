<?php

declare(strict_types=1);

namespace TMSWP\Testing\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;
use Stringable;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * wpdbの代役が、実wpdbと同じ形のSQLを返すこと(実wpdbとの照合は統合層のWpdbTestDoubleParityTest)。
 */
final class WpdbTestDoubleTest extends UnitTestCase
{
    /** 実wpdbと同じく、`%%`から戻した`%`と値の中の`%`を、placeholder_escape()に置き換えて返す。 */
    public function testPrepareReplacesPercentWithPlaceholderEscape(): void
    {
        $wpdb = WpdbTestDouble::create();
        $escape = $wpdb->placeholder_escape();

        $sql = $wpdb->prepare('SELECT id %% 2 FROM wp_contacts WHERE email LIKE %s', '%news%');

        self::assertMatchesRegularExpression('/\A\{[0-9a-f]{64}\}\z/', $escape);
        self::assertSame("SELECT id {$escape} 2 FROM wp_contacts WHERE email LIKE '{$escape}news{$escape}'", $sql);
        self::assertSame("SELECT id % 2 FROM wp_contacts WHERE email LIKE '%news%'", $wpdb->remove_placeholder_escape($sql));
        self::assertSame($sql, $wpdb->add_placeholder_escape("SELECT id % 2 FROM wp_contacts WHERE email LIKE '%news%'"));
    }

    /** 実wpdbと同じく、オブジェクトの値(文字列にできるものも)を空文字にし、noticeを残す。 */
    public function testObjectValuesBecomeEmptyStringWithNotice(): void
    {
        $wpdb = WpdbTestDouble::create();

        self::assertSame("SELECT '', 0", $wpdb->prepare('SELECT %s, %d', new stdClass(), new WpdbTestDoubleTestText('text')));
        self::assertSame(['Unsupported value type (object).', 'Unsupported value type (object).'], $wpdb->prepareNotices());
    }

    /** 実wpdbがPHPの診断を出す`%i`の値(配列・resource・null・Stringableでないオブジェクト)は、再現せずに例外で止まる。 */
    public function testIdentifierValuesThatRealWpdbDiagnosesAreRejected(): void
    {
        $wpdb = WpdbTestDouble::create();
        $messages = [];

        foreach ([[['a'], 2], [null, 2]] as $args) {
            try {
                $wpdb->prepare('SELECT %i, %d', ...$args);
                $messages[] = null;
            } catch (InvalidArgumentException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        self::assertSame([
            'WpdbTestDouble does not emulate %i with array: real wpdb raises a PHP diagnostic for this input',
            'WpdbTestDouble does not emulate %i with null: real wpdb raises a PHP diagnostic for this input',
        ], $messages);
    }

    /** 実wpdbのqueryフィルタと同じく、実行したSQLはplaceholder_escape()を`%`に戻して記録する。 */
    public function testExecutedSqlIsRecordedWithoutPlaceholderEscape(): void
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueAffectedRows(1);

        $wpdb->query((string) $wpdb->prepare('DELETE FROM wp_contacts WHERE email LIKE %s', '%@example.test'));

        self::assertSame(["DELETE FROM wp_contacts WHERE email LIKE '%@example.test'"], $wpdb->queries());
    }

    /** JtiStore::gc()の掃除は、期限内(値が時刻以上)の獲得済みのjtiを消さず、消した件数0を返す。 */
    public function testJtiGarbageCollectionKeepsUnexpiredClaims(): void
    {
        $wpdb = WpdbTestDouble::create();
        self::claim($wpdb, 'tmswp_form_jti_boundary', '2000000000');
        self::claim($wpdb, 'tmswp_form_jti_valid', '2000003600');

        self::assertSame(0, self::collectGarbage($wpdb, 2000000000));
        self::assertSame(0, $wpdb->rows_affected);
        self::assertSame(['tmswp_form_jti_boundary' => 0, 'tmswp_form_jti_valid' => 0], self::claimAgain($wpdb, ['tmswp_form_jti_boundary', 'tmswp_form_jti_valid']));
    }

    /** 期限切れ(値が時刻より前)のjtiは消え、消した件数が返る。名前は大文字と小文字を区別しない(wp_optionsの照合順序と同じ)。 */
    public function testJtiGarbageCollectionDeletesExpiredClaimsAndReturnsTheCount(): void
    {
        $wpdb = WpdbTestDouble::create();
        self::claim($wpdb, 'tmswp_form_jti_expired', '1999999990');
        self::claim($wpdb, 'TMSWP_FORM_JTI_UPPER', '1999999999');
        self::claim($wpdb, 'tmswp_form_jti_valid', '2000003600');

        self::assertSame(2, self::collectGarbage($wpdb, 2000000000));
        self::assertSame(2, $wpdb->rows_affected);
        // 消えたjtiは再び獲得でき(1)、残ったjtiは重複になる(0)
        self::assertSame(
            ['tmswp_form_jti_expired' => 1, 'TMSWP_FORM_JTI_UPPER' => 1, 'tmswp_form_jti_valid' => 0],
            self::claimAgain($wpdb, ['tmswp_form_jti_expired', 'TMSWP_FORM_JTI_UPPER', 'tmswp_form_jti_valid']),
        );
        self::assertSame(0, self::collectGarbage($wpdb, 2000000000));
    }

    /** LIKEのパターンに合わない名前は、期限切れでも消えない(esc_like()で戻した`_`は任意の1文字にしない)。 */
    public function testJtiGarbageCollectionKeepsOptionsNotMatchingThePattern(): void
    {
        $wpdb = WpdbTestDouble::create();
        self::claim($wpdb, 'tmswp_formXjti_expired', '1999999990');
        self::claim($wpdb, 'other_option', '1999999990');
        self::claim($wpdb, 'xtmswp_form_jti_expired', '1999999990');

        self::assertSame(0, self::collectGarbage($wpdb, 2000000000));
        self::assertSame(
            ['tmswp_formXjti_expired' => 0, 'other_option' => 0, 'xtmswp_form_jti_expired' => 0],
            self::claimAgain($wpdb, ['tmswp_formXjti_expired', 'other_option', 'xtmswp_form_jti_expired']),
        );
    }

    /** JtiStore::gc()と違う形のLIKEのDELETEは、黙って件数を返さずに例外にする。 */
    public function testOtherDeleteWithOptionNameLikeIsRejected(): void
    {
        $wpdb = WpdbTestDouble::create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("WpdbTestDouble emulates INSERT IGNORE and DELETE of option_name only in the forms of JtiStore prepared with prepare(): DELETE FROM `wp_options` WHERE option_name LIKE 'tmswp\\\\_%'");

        $wpdb->query((string) $wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, 'tmswp\\_%'));
    }

    /** 実wpdbと同じく、DDL(CREATE・ALTER・TRUNCATE・DROP)のquery()はtrueを返す。影響した行の数は要らない。 */
    public function testQueryReturnsTrueForDdl(): void
    {
        $wpdb = WpdbTestDouble::create();

        foreach (['CREATE TABLE wp_t (id int)', 'ALTER TABLE wp_t ADD c int', 'TRUNCATE TABLE wp_t', ' drop table wp_t'] as $sql) {
            self::assertTrue($wpdb->query($sql), $sql);
        }
    }

    /** 代役が対応しない形のquery()は、黙って成功を返さずに例外にする。 */
    #[DataProvider('unsupportedQueries')]
    public function testQueryRejectsUnsupportedStatements(string $sql): void
    {
        $wpdb = WpdbTestDouble::create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("WpdbTestDouble does not emulate the query: {$sql}; it emulates DDL, INSERT, UPDATE, DELETE, REPLACE, START TRANSACTION, COMMIT, ROLLBACK, SET SESSION innodb_lock_wait_timeout and the queries of JtiStore");

        $wpdb->query($sql);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedQueries(): iterable
    {
        yield 'SELECT' => ['SELECT * FROM wp_t'];
        yield 'SHOW' => ['SHOW TABLES'];
        yield 'SET' => ['SET NAMES utf8mb4'];
        yield 'BEGIN' => ['BEGIN'];
        yield 'SAVEPOINT' => ['SAVEPOINT a'];
        yield 'ROLLBACK TO SAVEPOINT' => ['ROLLBACK TO SAVEPOINT a'];
        yield 'ほかのセッションの変数' => ['SET SESSION sql_mode = \'\''];
        yield '数字でないロック待ちの秒数' => ['SET SESSION innodb_lock_wait_timeout = @x'];
    }

    /**
     * START TRANSACTION・COMMIT・ROLLBACKは、実wpdbと同じく0を返し、トランザクションの実行中かどうかを状態に持つ。
     * COMMIT・ROLLBACKは実行中でなくても受け付ける。実行した文はqueries()に残る。
     */
    public function testTransactionControlReturnsZeroAndTracksTheState(): void
    {
        $wpdb = WpdbTestDouble::create();
        $states = [];

        foreach (['START TRANSACTION', 'COMMIT', ' start  transaction ', 'rollback', 'COMMIT', 'ROLLBACK'] as $sql) {
            $states[] = [$wpdb->query($sql), $wpdb->inTransaction()];
        }

        self::assertSame([[0, true], [0, false], [0, true], [0, false], [0, false], [0, false]], $states);
        self::assertSame(['START TRANSACTION', 'COMMIT', ' start  transaction ', 'rollback', 'COMMIT', 'ROLLBACK'], $wpdb->queries());
    }

    /** トランザクションの実行中のSTART TRANSACTIONは、実物の暗黙のコミットを再現せずに例外にする。 */
    public function testStartTransactionInsideATransactionIsRejected(): void
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->query('START TRANSACTION');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WpdbTestDouble does not emulate the query: START TRANSACTION; START TRANSACTION inside a transaction implicitly commits it');

        $wpdb->query('START TRANSACTION');
    }

    /**
     * SET SESSION innodb_lock_wait_timeoutは0を返して秒数を状態に持ち、get_var('SELECT @@SESSION.innodb_lock_wait_timeout')
     * がキューではなくその値を返す。既定は実物の既定値と同じ'50'。
     */
    public function testLockWaitTimeoutIsKeptAsSessionState(): void
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueVar('queued');

        $initial = $wpdb->get_var('SELECT @@SESSION.innodb_lock_wait_timeout');
        $set = $wpdb->query('SET SESSION innodb_lock_wait_timeout = 7');
        $changed = $wpdb->get_var('select @@session.innodb_lock_wait_timeout');

        self::assertSame(['50', 0, '7'], [$initial, $set, $changed]);
        self::assertSame('queued', $wpdb->get_var('SELECT 1'));
    }

    /** failNextWrite()の予約は、トランザクションの制御の文も失敗させ、状態を変えない。 */
    public function testFailNextWriteFailsTransactionControlWithoutChangingTheState(): void
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->query('START TRANSACTION');
        $wpdb->failNextWrite('commit failed');

        self::assertFalse($wpdb->query('COMMIT'));
        self::assertSame('commit failed', $wpdb->last_error);
        self::assertTrue($wpdb->inTransaction());
    }

    /**
     * query()で実行するINSERT・UPDATE・DELETE・REPLACEは、queueAffectedRows()で入れた数を返し、rows_affectedにも
     * 入れる。行を作ったINSERT・REPLACEのinsert_idはqueueInsertId()で入れたIDで、0件・失敗なら0(入れたIDを使わない)。
     * 数を入れていなければ例外にする。
     */
    public function testWriteQueriesReturnTheQueuedAffectedRows(): void
    {
        $wpdb = WpdbTestDouble::create();

        foreach ([3, 0, 1, 1, 0] as $rows) {
            $wpdb->queueAffectedRows($rows);
        }

        $wpdb->queueInsertId(7);
        $wpdb->queueInsertId(8);

        self::assertSame(3, $wpdb->query('UPDATE wp_t SET c = 1'));
        self::assertSame(3, $wpdb->rows_affected);
        self::assertSame(0, $wpdb->query('DELETE FROM wp_t WHERE id = 9'));
        self::assertSame(0, $wpdb->rows_affected);
        self::assertSame(1, $wpdb->query('INSERT INTO wp_t (c) VALUES (1)'));
        self::assertSame(7, $wpdb->insert_id);
        $wpdb->failNextWrite('Duplicate entry');
        self::assertFalse($wpdb->query('INSERT INTO wp_t (c) VALUES (1)'));
        self::assertSame(0, $wpdb->insert_id);
        self::assertSame(1, $wpdb->query('REPLACE INTO wp_t (c) VALUES (1)'));
        self::assertSame(8, $wpdb->insert_id);
        self::assertSame(0, $wpdb->query('INSERT INTO wp_t (c) SELECT c FROM wp_t WHERE 1 = 0'));
        self::assertSame(0, $wpdb->insert_id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WpdbTestDouble cannot tell how many rows query(REPLACE INTO wp_t (c) VALUES (1)) affects; call queueAffectedRows() before it');

        $wpdb->query('REPLACE INTO wp_t (c) VALUES (1)');
    }

    /**
     * upsert(INSERT … ON DUPLICATE KEY UPDATE)は、INSERTとしてqueueAffectedRows()で入れた数を返す。影響した行の数が
     * 1(挿入)・2(更新)ならinsert_idはqueueInsertId()で入れたID(更新では更新した行のID)、0(変化なし)なら0で、
     * 入れたIDを使わない。
     */
    public function testUpsertTakesTheQueuedIdOnlyWhenRowsAreAffected(): void
    {
        $wpdb = WpdbTestDouble::create();
        $sql = "INSERT INTO wp_t (k, cnt) VALUES ('a', 1) ON DUPLICATE KEY UPDATE cnt = cnt + 1";

        foreach ([1, 2, 0, 2] as $rows) {
            $wpdb->queueAffectedRows($rows);
        }

        foreach ([5, 5, 6] as $id) {
            $wpdb->queueInsertId($id);
        }

        $outcomes = [];

        for ($i = 0; $i < 4; ++$i) {
            $outcomes[] = [$wpdb->query($sql), $wpdb->rows_affected, $wpdb->insert_id];
        }

        self::assertSame([[1, 1, 5], [2, 2, 5], [0, 0, 0], [2, 2, 6]], $outcomes);
        self::assertSame(array_fill(0, 4, $sql), $wpdb->queries());
    }

    /** JtiStore::claim()と違う形のINSERT IGNOREと、prepare()の結果でないSQL(直前のprepare()と違うSQL)は例外にする。 */
    public function testInsertIgnoreOtherThanTheJtiClaimIsRejected(): void
    {
        $wpdb = WpdbTestDouble::create();
        $failures = [];
        $claim = (string) $wpdb->prepare("INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, 'tmswp_form_jti_a', '2000000000');

        foreach ([
            (string) $wpdb->prepare('INSERT IGNORE INTO %i (option_name) VALUES (%s)', $wpdb->options, 'tmswp_form_jti_a'),
            // JtiStore::claim()のSQLでも、直前のprepare()の結果でなければ、形を判定しない
            $claim,
        ] as $sql) {
            try {
                $wpdb->query($sql);
            } catch (InvalidArgumentException $exception) {
                $failures[] = $exception->getMessage();
            }
        }

        self::assertCount(2, $failures);
        self::assertStringStartsWith('WpdbTestDouble emulates INSERT IGNORE and DELETE of option_name only in the forms of JtiStore prepared with prepare(): INSERT IGNORE INTO `wp_options` (option_name)', $failures[0]);
        self::assertStringStartsWith('WpdbTestDouble emulates INSERT IGNORE and DELETE of option_name only in the forms of JtiStore prepared with prepare(): INSERT IGNORE INTO `wp_options` (option_name, option_value, autoload)', $failures[1]);
    }

    /** JtiStoreの形の判定は、直前のprepare()が返したSQLを実行したときだけ行い、その後の別のSQLには持ち越さない。 */
    public function testJtiFormsAreJudgedOnlyForTheLastPreparedSql(): void
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->prepare("INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, 'tmswp_form_jti_a', '2000000000');
        $wpdb->queueAffectedRows(5);

        self::assertSame(5, $wpdb->query('UPDATE wp_t SET c = 1'));
        // 実行しなかった獲得は、獲得したことにならない
        self::assertSame(1, self::claim($wpdb, 'tmswp_form_jti_a', '2000000000'));
    }

    /** 獲得と解放のoption_nameは、wp_optionsの照合順序と同じく大文字と小文字を区別しない。 */
    public function testJtiClaimAndReleaseIgnoreCase(): void
    {
        $wpdb = WpdbTestDouble::create();

        self::assertSame(1, self::claim($wpdb, 'tmswp_form_jti_abc', '2000000000'));
        self::assertSame(0, self::claim($wpdb, 'TMSWP_FORM_JTI_ABC', '2000000000'));
        self::assertSame(0, $wpdb->rows_affected);
        self::assertSame(1, self::release($wpdb, 'TMSWP_FORM_JTI_ABC'));
        self::assertSame(1, $wpdb->rows_affected);
        self::assertSame(0, self::release($wpdb, 'tmswp_form_jti_abc'));
        self::assertSame(0, $wpdb->rows_affected);
        self::assertSame(1, self::claim($wpdb, 'tmswp_form_jti_abc', '2000000000'));
    }

    /** JtiStore::release()と違う形の、option_nameのDELETEは例外にする。 */
    public function testDeleteOfOptionNameOtherThanTheJtiReleaseIsRejected(): void
    {
        $wpdb = WpdbTestDouble::create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("WpdbTestDouble emulates INSERT IGNORE and DELETE of option_name only in the forms of JtiStore prepared with prepare(): DELETE FROM `wp_options` WHERE option_name = 'a' AND autoload = 'off'");

        $wpdb->query((string) $wpdb->prepare('DELETE FROM %i WHERE option_name = %s AND autoload = %s', $wpdb->options, 'a', 'off'));
    }

    /**
     * update()・delete()は、実wpdbと同じく影響した行の数を返す。代役は行を持たないため、queueAffectedRows()で入れた数を
     * 返し、入れていなければ例外にする。failNextWrite()の失敗はfalseを返し、入れた数を使わない。
     */
    public function testUpdateAndDeleteReturnTheQueuedAffectedRows(): void
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueAffectedRows(0);
        $wpdb->queueAffectedRows(2);

        $wpdb->failNextWrite('Deadlock found');
        self::assertFalse($wpdb->update('wp_t', ['c' => 1], ['id' => 1]));
        self::assertSame(0, $wpdb->update('wp_t', ['c' => 1], ['id' => 1]));
        self::assertSame(0, $wpdb->rows_affected);
        self::assertSame(2, $wpdb->delete('wp_t', ['c' => 1]));
        self::assertSame(2, $wpdb->rows_affected);

        $failures = [];

        foreach ([
            'update()' => static fn(): mixed => $wpdb->update('wp_t', ['c' => 1], ['id' => 1]),
            'delete()' => static fn(): mixed => $wpdb->delete('wp_t', ['id' => 1]),
        ] as $call => $write) {
            try {
                $write();
            } catch (InvalidArgumentException $exception) {
                $failures[] = $exception->getMessage();
            }
        }

        self::assertSame([
            'WpdbTestDouble cannot tell how many rows update() affects; call queueAffectedRows() before it',
            'WpdbTestDouble cannot tell how many rows delete() affects; call queueAffectedRows() before it',
        ], $failures);
    }

    /**
     * insert()は1行を挿入するため、実wpdbと同じく1を返し、insert_idはqueueInsertId()で入れたID(AUTO_INCREMENTの
     * 列の無いテーブルなら0)。失敗はinsert_idを0にし、入れたIDを使わない。IDを入れていなければ例外にする。
     */
    public function testInsertReturnsOneInsertedRowWithTheQueuedId(): void
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueInsertId(5);
        $wpdb->queueInsertId(0);

        self::assertSame(1, $wpdb->insert('wp_t', ['c' => 1]));
        self::assertSame(1, $wpdb->rows_affected);
        self::assertSame(5, $wpdb->insert_id);
        $wpdb->failNextWrite('Duplicate entry');
        self::assertFalse($wpdb->insert('wp_t', ['c' => 1]));
        self::assertSame(0, $wpdb->rows_affected);
        self::assertSame(0, $wpdb->insert_id);
        self::assertSame(1, $wpdb->insert('wp_keys', ['k' => 'a']));
        self::assertSame(0, $wpdb->insert_id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WpdbTestDouble cannot tell the insert_id of the row insert() creates; call queueInsertId() before it');

        $wpdb->insert('wp_t', ['c' => 2]);
    }

    public function testQueueInsertIdRejectsNegativeIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('insert id must not be negative');

        WpdbTestDouble::create()->queueInsertId(-1);
    }

    /**
     * 実wpdbのflush()と同じく、各操作の開始時にrows_affectedを0、last_errorを空にする。書き込みの後のDDL・読み取り・
     * 読み取りの失敗のrows_affectedは0。insert_idは、INSERT・REPLACEのほかでは変わらない。
     */
    public function testEachOperationStartsByFlushingTheResultState(): void
    {
        $wpdb = WpdbTestDouble::create();
        $states = [];
        $state = static fn(mixed $result): array => [$result, $wpdb->rows_affected, $wpdb->insert_id, $wpdb->last_error];
        $wpdb->queueInsertId(4);
        $wpdb->queueAffectedRows(2);
        $wpdb->queueAffectedRows(3);
        $wpdb->queueVar('a');

        $states[] = $state($wpdb->insert('wp_t', ['c' => 1]));
        $states[] = $state($wpdb->query('ALTER TABLE wp_t ADD extra int NULL'));
        $states[] = $state($wpdb->update('wp_t', ['c' => 2], ['c' => 1]));
        $states[] = $state($wpdb->get_var('SELECT c FROM wp_t'));
        $states[] = $state($wpdb->delete('wp_t', ['c' => 2]));
        $wpdb->failNextRead('Table does not exist');
        $states[] = $state($wpdb->get_var('SELECT c FROM wp_missing'));
        $wpdb->failNextWrite('Deadlock found');
        $states[] = $state($wpdb->update('wp_t', ['c' => 3], ['c' => 2]));
        $states[] = $state($wpdb->query('DROP TABLE wp_t'));

        self::assertSame([
            [1, 1, 4, ''],
            [true, 0, 4, ''],
            [2, 2, 4, ''],
            ['a', 0, 4, ''],
            [3, 3, 4, ''],
            [null, 0, 4, 'Table does not exist'],
            [false, 0, 4, 'Deadlock found'],
            [true, 0, 4, ''],
        ], $states);
    }

    /** get_var()は、実wpdbと同じく空文字をnullで返す。文字列の'0'とSQLのNULLは、そのまま'0'とnull。 */
    public function testGetVarReturnsNullForAnEmptyString(): void
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueVar('');
        $wpdb->queueVar('0');
        $wpdb->queueVar(null);

        self::assertNull($wpdb->get_var("SELECT ''"));
        self::assertSame('0', $wpdb->get_var("SELECT '0'"));
        self::assertNull($wpdb->get_var('SELECT NULL'));
    }

    /** 獲得の状態は、`%i`のテーブルごとに分かれる。別のテーブルの同じ名前は、獲得・解放・掃除・読み取りで影響し合わない。 */
    public function testJtiStateIsSeparatedByTable(): void
    {
        $wpdb = WpdbTestDouble::create();

        self::assertSame(1, self::claim($wpdb, 'same', '1999999990', 'wp_a'));
        self::assertNull(self::lookUp($wpdb, 'same', 'wp_b'));
        self::assertSame(1, self::claim($wpdb, 'same', '1999999990', 'wp_b'));
        self::assertSame(1, self::release($wpdb, 'same', 'wp_a'));
        self::assertNull(self::lookUp($wpdb, 'same', 'wp_a'));
        self::assertSame('1999999990', self::lookUp($wpdb, 'same', 'wp_b'));
        self::assertSame(1, self::claim($wpdb, 'tmswp_form_jti_x', '1999999990', 'wp_a'));
        self::assertSame(1, self::claim($wpdb, 'tmswp_form_jti_x', '1999999990', 'wp_b'));
        self::assertSame(1, self::collectGarbage($wpdb, 2000000000, 'wp_a'));
        self::assertSame('1999999990', self::lookUp($wpdb, 'tmswp_form_jti_x', 'wp_b'));
    }

    public function testQueueAffectedRowsRejectsNegativeCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('affected rows must not be negative');

        WpdbTestDouble::create()->queueAffectedRows(-1);
    }

    /**
     * get_results()は、実wpdbと同じく、行が無いとき(キューが空)とエラーのときに空の配列を返す。OBJECT_Kは1列目の値を
     * キーにし(同じキーは最初の行)、対応しない出力の形は例外にする。出力の形の定数を定義するため、別のプロセスで動かす。
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetResultsReturnsAnEmptyArrayWithoutRowsAndOnError(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();

        foreach ([OBJECT, OBJECT_K, ARRAY_A, ARRAY_N] as $output) {
            self::assertSame([], $wpdb->get_results('SELECT * FROM wp_t', $output), $output);
            $wpdb->failNextRead('Table does not exist');
            self::assertSame([], $wpdb->get_results('SELECT * FROM wp_t', $output), $output);
            self::assertSame('Table does not exist', $wpdb->last_error);
        }

        $wpdb->queueResultSet([['id' => '1', 'c' => 'a'], ['id' => '2', 'c' => 'b'], ['id' => '1', 'c' => 'c']]);
        self::assertEquals([1 => (object) ['id' => '1', 'c' => 'a'], 2 => (object) ['id' => '2', 'c' => 'b']], $wpdb->get_results('SELECT id, c FROM wp_t', OBJECT_K));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WpdbTestDouble emulates get_results() only with OBJECT, OBJECT_K, ARRAY_A and ARRAY_N');

        $wpdb->get_results('SELECT * FROM wp_t', 'object');
    }

    /**
     * JtiStore::isClaimed()のSELECTは、キューではなく獲得済みの状態から、大文字と小文字を区別せずに値を返す。獲得して
     * いない名前・解放・掃除の後はnull。キューの値は、ほかのget_var()のために残る。
     */
    public function testJtiLookupAnswersFromTheClaimedState(): void
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueVar('queued');
        self::claim($wpdb, 'tmswp_form_jti_a', '2000000000');
        self::claim($wpdb, 'tmswp_form_jti_b', '1999999990');

        self::assertSame('2000000000', self::lookUp($wpdb, 'TMSWP_FORM_JTI_A'));
        self::assertNull(self::lookUp($wpdb, 'tmswp_form_jti_c'));
        self::release($wpdb, 'tmswp_form_jti_a');
        self::assertNull(self::lookUp($wpdb, 'tmswp_form_jti_a'));
        self::assertSame('1999999990', self::lookUp($wpdb, 'tmswp_form_jti_b'));
        self::collectGarbage($wpdb, 2000000000);
        self::assertNull(self::lookUp($wpdb, 'tmswp_form_jti_b'));
        self::assertSame('queued', $wpdb->get_var('SELECT 1'));
    }

    /**
     * 獲得の後のinsert_idは、行を作れば正の値、重複で無視されれば0。値そのものは実物のサーバーの自動採番の設定に
     * 依存するため、代役は正か0かだけを約束する(クラスのdocblockの1)。
     */
    public function testJtiClaimSetsAPositiveOrZeroInsertId(): void
    {
        $wpdb = WpdbTestDouble::create();
        $insertIds = [];

        foreach ([['wp_a', 'tmswp_form_jti_a'], ['wp_b', 'tmswp_form_jti_a'], ['wp_a', 'tmswp_form_jti_a'], ['wp_a', 'tmswp_form_jti_b']] as [$table, $name]) {
            self::claim($wpdb, $name, '2000000000', $table);
            $insertIds[] = $wpdb->insert_id > 0 ? 'positive' : $wpdb->insert_id;
        }

        self::assertSame(['positive', 'positive', 0, 'positive'], $insertIds);
    }

    /**
     * TRUNCATE TABLEとDROP TABLEは、実物と同じく、そのテーブルの獲得済みのjtiを消す。別のテーブルと、ALTER・
     * DROP INDEX・DROP VIEWは、状態を変えない。テーブル名は、バッククォートで囲んだ形と囲まない形の両方を受け付ける。
     */
    public function testTruncateAndDropTableForgetTheClaimsOfTheTable(): void
    {
        $wpdb = WpdbTestDouble::create();

        foreach (['wp_a', 'wp_b', 'wp_c', 'we`ird'] as $table) {
            self::claim($wpdb, 'tmswp_form_jti_x', '2000000000', $table);
        }

        self::assertTrue($wpdb->query('ALTER TABLE wp_a ADD extra int NULL'));
        self::assertTrue($wpdb->query('DROP INDEX autoload ON wp_a'));
        self::assertTrue($wpdb->query('DROP VIEW wp_a_view'));
        self::assertSame('2000000000', self::lookUp($wpdb, 'tmswp_form_jti_x', 'wp_a'));
        self::assertTrue($wpdb->query('TRUNCATE TABLE wp_a'));
        self::assertNull(self::lookUp($wpdb, 'tmswp_form_jti_x', 'wp_a'));
        self::assertSame('2000000000', self::lookUp($wpdb, 'tmswp_form_jti_x', 'wp_b'));
        self::assertSame(1, self::claim($wpdb, 'tmswp_form_jti_x', '2000000000', 'wp_a'));
        self::assertTrue($wpdb->query('DROP TABLE IF EXISTS `wp_b`;'));
        self::assertNull(self::lookUp($wpdb, 'tmswp_form_jti_x', 'wp_b'));
        self::assertTrue($wpdb->query('truncate wp_c'));
        self::assertNull(self::lookUp($wpdb, 'tmswp_form_jti_x', 'wp_c'));
        self::assertTrue($wpdb->query('DROP TABLE `we``ird` CASCADE'));
        self::assertNull(self::lookUp($wpdb, 'tmswp_form_jti_x', 'we`ird'));
        self::assertSame('2000000000', self::lookUp($wpdb, 'tmswp_form_jti_x', 'wp_a'));
    }

    /**
     * DROP TABLEは末尾のRESTRICT・CASCADEを受け付けてそのテーブルの獲得を消す。TRUNCATEは句の無い形(末尾の`;`だけ)を
     * 受け付ける(TRUNCATEのRESTRICT・CASCADEは、testDdlWhoseTableIsAmbiguousIsRejectedで例外になることを確かめる)。
     */
    public function testTruncateAndDropTableAcceptOnlyTheirOwnTrailingClauses(): void
    {
        $wpdb = WpdbTestDouble::create();

        foreach (['TRUNCATE TABLE wp_a;', 'DROP TABLE wp_b RESTRICT', 'DROP TABLE IF EXISTS wp_c CASCADE;'] as $i => $sql) {
            $table = ['wp_a', 'wp_b', 'wp_c'][$i];
            self::claim($wpdb, 'tmswp_form_jti_x', '2000000000', $table);
            self::assertTrue($wpdb->query($sql), $sql);
            self::assertNull(self::lookUp($wpdb, 'tmswp_form_jti_x', $table), $sql);
        }
    }

    /** 行やテーブルの同一性に作用しないALTER(列・索引の追加や変更)は、trueを返して状態を変えない。 */
    public function testOrdinaryAlterKeepsTheClaims(): void
    {
        $wpdb = WpdbTestDouble::create();
        self::claim($wpdb, 'tmswp_form_jti_x', '2000000000', 'wp_a');

        foreach ([
            'ALTER TABLE wp_a ADD COLUMN extra int NULL',
            'ALTER TABLE wp_a MODIFY extra bigint NULL',
            'ALTER TABLE wp_a ADD COLUMN partition_key int NULL, ADD KEY renamed_at (extra)',
        ] as $sql) {
            self::assertTrue($wpdb->query($sql), $sql);
        }

        self::assertSame('2000000000', self::lookUp($wpdb, 'tmswp_form_jti_x', 'wp_a'));
    }

    /** テーブルの名前を変える・パーティションやテーブルスペースを操作するALTERと、RENAME TABLEは例外にする。 */
    #[DataProvider('alterAffectingRows')]
    public function testAlterAffectingTheTableOrItsRowsIsRejected(string $sql, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("WpdbTestDouble does not emulate the query: {$sql}; {$message}");

        WpdbTestDouble::create()->query($sql);
    }

    /** @return iterable<string, array{string, string}> */
    public static function alterAffectingRows(): iterable
    {
        $alter = 'it rejects ALTER containing RENAME, PARTITION or TABLESPACE';

        yield 'ALTERのRENAME' => ['ALTER TABLE wp_a RENAME TO wp_b', $alter];
        yield 'ALTERのRENAME(小文字・TOなし)' => ['alter table wp_a rename wp_b', $alter];
        yield 'TRUNCATE PARTITION' => ['ALTER TABLE wp_a TRUNCATE PARTITION p0', $alter];
        yield 'DROP PARTITION' => ['ALTER TABLE wp_a DROP PARTITION p0', $alter];
        yield 'DISCARD TABLESPACE' => ['ALTER TABLE wp_a DISCARD TABLESPACE', $alter];
        yield 'RENAME TABLE' => ['RENAME TABLE wp_a TO wp_b', 'it emulates DDL, INSERT, UPDATE, DELETE, REPLACE, START TRANSACTION, COMMIT, ROLLBACK, SET SESSION innodb_lock_wait_timeout and the queries of JtiStore'];
    }

    /**
     * 受け付けない形のTRUNCATE・DROP(対象のテーブルを1つに決められない形・一時表・SQLのコメントを含む形・列挙して
     * いないDROP)は、黙って状態を残さずに例外にする。
     */
    #[DataProvider('ambiguousTableDdl')]
    public function testDdlWhoseTableIsAmbiguousIsRejected(string $sql, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("WpdbTestDouble does not emulate the query: {$sql}; {$message}");

        WpdbTestDouble::create()->query($sql);
    }

    /** @return iterable<string, array{string, string}> */
    public static function ambiguousTableDdl(): iterable
    {
        $unsupported = 'it emulates TRUNCATE and DROP TABLE of a single table named without the database, DROP INDEX and DROP VIEW';
        $comment = 'it rejects DROP and TRUNCATE containing an SQL comment';

        yield '複数のテーブル' => ['DROP TABLE wp_a, wp_b', $unsupported];
        yield 'データベース名で修飾' => ['TRUNCATE TABLE wordpress.wp_a', $unsupported];
        yield 'テーブル名が無い' => ['DROP TABLE ', $unsupported];
        yield 'DROP DATABASE' => ['DROP DATABASE wordpress', $unsupported];
        yield '列挙していないDROP' => ['DROP TRIGGER wp_a_trigger', $unsupported];
        yield 'TRUNCATEのRESTRICT' => ['TRUNCATE TABLE wp_a RESTRICT', $unsupported];
        yield 'TRUNCATEのCASCADE' => ['TRUNCATE TABLE wp_a CASCADE', $unsupported];
        yield '一時表' => ['DROP TEMPORARY TABLE IF EXISTS wp_a', 'it does not tell temporary tables from regular tables'];
        yield 'キーワードの間のコメント' => ['DROP /* x */ TABLE wp_a', $comment];
        yield 'テーブル名の前のコメント' => ['DROP TABLE/* x */ wp_a', $comment];
        yield 'コメントを挟んだDROP DATABASE' => ['DROP /* x */ DATABASE wordpress', $comment];
        yield '行末のコメント(--)' => ['TRUNCATE TABLE wp_a -- x', $comment];
        yield '行末のコメント(#)' => ['DROP TABLE wp_a # x', $comment];
    }

    /** get_var()の先頭でない位置と、get_row()の対応しない出力の形・先頭でない行は、黙って値を返さずに例外にする。 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetRowAndGetVarRejectUnsupportedPositionsAndOutputs(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $failures = [];

        foreach ([
            static fn(): mixed => $wpdb->get_var('SELECT a, b FROM wp_t', 1),
            static fn(): mixed => $wpdb->get_var('SELECT a FROM wp_t', 0, 1),
            static fn(): mixed => $wpdb->get_row('SELECT * FROM wp_t', OBJECT_K),
            static fn(): mixed => $wpdb->get_row('SELECT * FROM wp_t', OBJECT, 1),
        ] as $read) {
            try {
                $read();
            } catch (InvalidArgumentException $exception) {
                $failures[] = $exception->getMessage();
            }
        }

        self::assertSame([
            'WpdbTestDouble emulates get_var() only for the first column of the first row',
            'WpdbTestDouble emulates get_var() only for the first column of the first row',
            'WpdbTestDouble emulates get_row() only with OBJECT, ARRAY_A and ARRAY_N and the first row',
            'WpdbTestDouble emulates get_row() only with OBJECT, ARRAY_A and ARRAY_N and the first row',
        ], $failures);
        self::assertSame([], $wpdb->queries());
    }

    /** JtiStore::isClaimed()と同じSQLで、獲得済みの値を読む。 */
    private static function lookUp(WpdbTestDouble $wpdb, string $name, string $table = 'wp_options'): ?string
    {
        return $wpdb->get_var((string) $wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $table, $name));
    }

    /** JtiStore::release()と同じSQLで、名前を手放す。 */
    private static function release(WpdbTestDouble $wpdb, string $name, string $table = 'wp_options'): int|bool
    {
        return $wpdb->query((string) $wpdb->prepare('DELETE FROM %i WHERE option_name = %s', $table, $name));
    }

    /** JtiStore::claim()と同じSQLで、名前と値(期限のUNIX時刻の10進の文字列)を獲得する。 */
    private static function claim(WpdbTestDouble $wpdb, string $name, string $expires, string $table = 'wp_options'): int|bool
    {
        return $wpdb->query((string) $wpdb->prepare("INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $table, $name, $expires));
    }

    /** JtiStore::gc()と同じSQLで、$now(UNIX時刻)より前に期限が切れたjtiを消す。 */
    private static function collectGarbage(WpdbTestDouble $wpdb, int $now, string $table = 'wp_options'): int|bool
    {
        return $wpdb->query((string) $wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d', $table, $wpdb->esc_like('tmswp_form_jti_') . '%', $now));
    }

    /**
     * 名前ごとに獲得をやり直した結果(1は獲得できた=無かった、0は重複=残っていた)。
     *
     * @param list<string> $names
     *
     * @return array<string, int|bool>
     */
    private static function claimAgain(WpdbTestDouble $wpdb, array $names): array
    {
        $results = [];

        foreach ($names as $name) {
            $results[$name] = self::claim($wpdb, $name, '2000009999');
        }

        return $results;
    }
}

/** 文字列にできるオブジェクトの値。 */
final readonly class WpdbTestDoubleTestText implements Stringable
{
    public function __construct(
        private string $text,
    ) {}

    public function __toString(): string
    {
        return $this->text;
    }
}
