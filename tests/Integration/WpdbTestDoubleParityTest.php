<?php

declare(strict_types=1);

namespace TMSWP\Testing\Tests\Integration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionMethod;
use stdClass;
use Stringable;
use Throwable;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\WpdbTestDouble;
use wpdb;

/**
 * ユニット層のwpdbの代役(WpdbTestDouble)が、実wpdbと同じSQLを返すことを確かめる。
 *
 * 同じ入力を実wpdb(MySQLに接続済みのグローバルの$wpdb)と代役のprepare()に通し、それぞれのplaceholder_escape()
 * を同じ印に置き換えて比べる。例外になる入力では、例外のクラスとメッセージを比べる。実wpdbが_doing_it_wrong()を
 * 出す入力では、代役もnoticeを記録する。
 */
final class WpdbTestDoubleParityTest extends IntegrationTestCase
{
    /** @return iterable<string, array{string, list<mixed>, bool}> 実wpdb::prepare()の引数と、実wpdbが_doing_it_wrong()を出すか */
    public static function prepareInputs(): iterable
    {
        yield '%s・%d・%f' => ['SELECT * FROM t WHERE a = %s AND b = %d AND c = %f', ['x', 3, 1.5], false];
        yield '値と一緒の%%' => ['SELECT id %% 2 FROM t WHERE id = %d', [1], false];
        yield '%を含む値' => ['SELECT * FROM t WHERE a LIKE %s', ['%news\_%'], false];
        yield '引用符・バックスラッシュ・制御文字・マルチバイト' => ['SELECT * FROM t WHERE a = %s', ["O'Brien \"q\" \\ \n \r \0 \x1a 日本語"], false];
        yield '%iとバッククォート' => ['SELECT %i FROM t', ['we`ird'], false];
        yield '%iにStringable' => ['SELECT %i, %d', [new WpdbTestDoubleParityText('we`ird'), 2], false];
        yield 'nullとbool' => ['SELECT %s, %s, %s, %d, %d', [null, true, false, true, false], false];
        yield '数でない文字列を%d・%fに' => ['SELECT %d, %f', ['12abc', '1.25'], false];
        yield '引用符で囲んだ%s' => ["SELECT * FROM t WHERE a = '%s' AND b = \"%s\"", ['x', 'y'], false];
        yield '解釈できない%の並び' => ['SELECT 100%, a FROM t WHERE d = %Y-%m AND a = %s', ['x'], false];
        yield '番号付き・書式付き' => ['SELECT %1$s, %2$d, %5s', ['a', 2, 'b'], false];
        yield '配列1つで渡す値' => ['SELECT %s, %d', [['a', 1]], false];
        yield '配列1つで渡す値(連番でないキー)' => ['SELECT %i', [[4 => 'a`b']], false];
        yield '配列1つで渡す値(連番でないキーと%s)' => ['SELECT %i, %s', [[2 => 'a`b', 3 => "O'Brien"]], false];
        yield '配列1つで渡す値(文字列のキー)' => ['SELECT %s, %i', [['a' => "O'Brien", 'b' => 'a`b']], false];
        yield '%%の直後の%s' => ['SELECT * FROM t WHERE a LIKE %%%s', ['x'], false];
        yield '%%の直後の%f(プレースホルダに数えない)' => ['SELECT %%%f, %%%%%f', [1.5, 2.5], true];
        yield 'オブジェクトの値' => ['SELECT %s, %d, %s', [new stdClass(), new DateTimeImmutable('2026-06-13 09:10:11', new DateTimeZone('UTC')), new WpdbTestDoubleParityText('text')], true];
        yield '値が足りない' => ['SELECT %s, %s', ['a'], true];
        yield '値が多い' => ['SELECT %s', ['a', 'b'], true];
        yield 'プレースホルダ1つに配列で複数の値' => ['SELECT %s', [['a', 'b']], true];
        yield '識別子と値に同じ引数' => ['SELECT %1$i, %1$s', ['a'], true];
        yield 'プレースホルダの無いSQL' => ['SELECT 1', [], true];
    }

    /**
     * 統合層のPHPUnit 9は属性のデータプロバイダを読まないため、注釈で指定する。
     *
     * @dataProvider prepareInputs
     *
     * @param list<mixed> $args
     */
    public function testPrepareReturnsTheSameSqlAsRealWpdb(string $query, array $args, bool $doingItWrong): void
    {
        $wpdb = self::wpdb();
        $double = WpdbTestDouble::create();

        if ($doingItWrong) {
            $this->setExpectedIncorrectUsage('wpdb::prepare');
        }

        // スタブのwpdb::prepare()は$queryにliteral-stringを要求するため、データプロバイダのSQLはリフレクションで渡す
        $real = self::outcome($wpdb, static fn(): mixed => (new ReflectionMethod($wpdb, 'prepare'))->invokeArgs($wpdb, [$query, ...$args]));
        $doubled = self::outcome($double, static fn(): mixed => $double->prepare($query, ...$args));

        self::assertSame($real, $doubled);
        self::assertSame($doingItWrong, $double->prepareNotices() !== []);
    }

    /**
     * 実wpdbがPHPの診断(警告・非推奨の通知・TypeError)を出す`%i`の値の入力。代役は再現しない(WpdbTestDoubleの
     * クラスのdocblockの5)。
     *
     * @return iterable<string, array{string, list<mixed>, string}> 実wpdb::prepare()の引数と、get_debug_type()の型の名前
     */
    public static function identifierInputsTheDoubleDoesNotEmulate(): iterable
    {
        $resource = fopen('php://memory', 'r');

        yield '%iに配列' => ['SELECT %i, %d', [['a', 'b'], 2], 'array'];
        yield '%1$iと%dで同じ配列' => ['SELECT %1$i, %d', [['a', 'b'], 2], 'array'];
        yield '%iに入れ子の配列' => ['SELECT %i, %d', [[['a'], 'b'], 2], 'array'];
        yield '%iにresource' => ['SELECT %i', [$resource], 'resource (stream)'];
        yield '%iにnull' => ['SELECT %i', [null], 'null'];
        yield '%iにstdClass' => ['SELECT %i', [new stdClass()], 'stdClass'];
    }

    /**
     * 実wpdbはPHPの診断を出し(PHPUnitが警告・非推奨の通知を変えた例外か、TypeError)、代役は再現せずに
     * InvalidArgumentExceptionで止まる。
     *
     * @dataProvider identifierInputsTheDoubleDoesNotEmulate
     *
     * @param list<mixed> $args
     */
    public function testDoubleRejectsIdentifierInputsForWhichRealWpdbRaisesPhpDiagnostic(string $query, array $args, string $type): void
    {
        $wpdb = self::wpdb();
        $double = WpdbTestDouble::create();

        $real = self::outcome($wpdb, static fn(): mixed => (new ReflectionMethod($wpdb, 'prepare'))->invokeArgs($wpdb, [$query, ...$args]));
        $doubled = self::outcome($double, static fn(): mixed => $double->prepare($query, ...$args));

        // 統合層のPHPUnit 9のクラスは、静的解析の対象のPHPUnitに無いため、名前で比べる
        self::assertIsString($real);
        self::assertContains(strstr($real, ':', true), ['TypeError', 'PHPUnit\Framework\Error\Warning', 'PHPUnit\Framework\Error\Deprecated', 'PHPUnit\Framework\Error\Notice']);
        self::assertSame("InvalidArgumentException: WpdbTestDouble does not emulate %i with {$type}: real wpdb raises a PHP diagnostic for this input", $doubled);
    }

    public function testPlaceholderEscapeHasTheSameShapeAndRoundTrips(): void
    {
        $wpdb = self::wpdb();
        $double = WpdbTestDouble::create();
        $sql = "SELECT '100%' LIKE '%a%'";

        self::assertMatchesRegularExpression('/\A\{[0-9a-f]{64}\}\z/', $wpdb->placeholder_escape());
        self::assertMatchesRegularExpression('/\A\{[0-9a-f]{64}\}\z/', $double->placeholder_escape());
        self::assertSame(self::marked($wpdb, $wpdb->add_placeholder_escape($sql)), self::marked($double, $double->add_placeholder_escape($sql)));
        self::assertSame($sql, $wpdb->remove_placeholder_escape($wpdb->add_placeholder_escape($sql)));
        self::assertSame($sql, $double->remove_placeholder_escape($double->add_placeholder_escape($sql)));
    }

    /**
     * JtiStoreの獲得(INSERT IGNORE)と掃除(LIKEと期限のDELETE)を、実wpdbと代役に同じ順で流し、返す件数と、
     * 掃除の後に残った名前(獲得のやり直しが重複の0になる)が同じになる。期限内・期限ちょうど・期限切れ、大文字の名前、
     * esc_like()で戻した`_`の位置に別の文字がある名前、前に文字が付いた名前、別の名前を混ぜる。
     */
    public function testJtiGarbageCollectionDeletesTheSameOptionsAsRealWpdb(): void
    {
        $claims = [
            'tmswp_parity_jti_expired' => '1999999990',
            'TMSWP_PARITY_JTI_UPPER' => '1999999999',
            'tmswp_parity_jti_boundary' => '2000000000',
            'tmswp_parity_jti_valid' => '2000003600',
            'tmswp_parityXjti_expired' => '1999999990',
            'xtmswp_parity_jti_expired' => '1999999990',
            'tmswp_parity_other' => '1999999990',
        ];
        $expected = [
            'claims' => array_fill_keys(array_keys($claims), 1),
            'deleted' => 2,
            'claimedAgain' => ['tmswp_parity_jti_expired' => 1, 'TMSWP_PARITY_JTI_UPPER' => 1] + array_fill_keys(\array_slice(array_keys($claims), 2), 0),
        ];

        self::assertSame($expected, self::collectGarbageAfterClaims(self::wpdb(), $claims));
        self::assertSame($expected, self::collectGarbageAfterClaims(WpdbTestDouble::create(), $claims));
    }

    /**
     * JtiStoreの獲得・解放・isClaimed()の読み取りは、実wpdb(wp_optionsの照合順序)と同じく、option_nameの大文字と
     * 小文字を区別しない。獲得のinsert_idは、行を作れば新しいID(実wpdbの値はwp_optionsの自動採番のため、正の数で
     * あることを比べる)、重複で無視されれば0。
     */
    public function testJtiClaimAndReleaseIgnoreCaseLikeRealWpdb(): void
    {
        $expected = [
            ['claim', 1, 'new id', '2000000000'],
            ['claim', 0, 0, '2000000000'],
            ['release', 1, null, null],
            ['release', 0, null, null],
            ['claim', 1, 'new id', '2000000000'],
        ];

        self::assertSame($expected, self::claimAndReleaseInMixedCase(self::wpdb()));
        self::assertSame($expected, self::claimAndReleaseInMixedCase(WpdbTestDouble::create()));
    }

    /**
     * 書き込みの戻り値が実wpdbと同じ意味になる。DDLはtrue、insert()は1、update()・delete()・query()の書き込みは
     * MySQLが変えた行の数(同じ値への更新は0)。代役には、テストが宣言するのと同じく、影響する行の数を入れる。
     */
    public function testWritesReturnTheSameValuesAsRealWpdb(): void
    {
        $double = WpdbTestDouble::create();

        foreach ([2, 0, 0, 1, 2] as $rows) {
            $double->queueAffectedRows($rows);
        }

        foreach ([1, 2, 3] as $id) {
            $double->queueInsertId($id);
        }

        $expected = [
            'create' => true,
            'insert' => [1, 1, 2],
            'updateMatchingTwoRows' => [2, 2],
            'updateToTheSameValue' => [0, 0],
            'deleteMatchingNoRow' => [0, 0],
            'queryInsert' => [1, 3],
            'queryDelete' => [2, 2],
            'drop' => true,
        ];

        self::assertSame($expected, self::writeRows(self::wpdb()));
        self::assertSame($expected, self::writeRows($double));
    }

    /**
     * 同じインスタンスに操作の列を流したとき、各操作の戻り値と、その後の状態(rows_affected・insert_id・last_errorの
     * 有無)が実wpdbと同じになる。書き込みの後のDDL・読み取り・読み取りの失敗(rows_affectedは0)、0件と失敗の
     * INSERT・insert()(insert_idは0)、AUTO_INCREMENTの列の無いテーブルへのinsert()(insert_idは0)、get_var()の
     * 空文字・'0'・NULLを含む。代役には、テストが宣言するのと同じく、影響する行の数・ID・読む値を入れる。
     */
    public function testOperationSequenceLeavesTheSameStateAsRealWpdb(): void
    {
        $double = WpdbTestDouble::create();

        foreach ([1, 0, 1, 1, 0] as $rows) {
            $double->queueAffectedRows($rows);
        }

        foreach ([1, 2, 3, 4, 0] as $id) {
            $double->queueInsertId($id);
        }

        foreach (['a', '', '0', null] as $value) {
            $double->queueVar($value);
        }

        $expected = [
            'insert' => [1, 1, 'id', false],
            'ddlAfterInsert' => [true, 0, 'id', false],
            'queryInsert' => [1, 1, 'id', false],
            'readAfterInsert' => ['a', 0, 'id', false],
            'zeroRowInsert' => [0, 0, 0, false],
            'insertAfterZeroRows' => [1, 1, 'id', false],
            'update' => [1, 1, 'id', false],
            'failedReadAfterUpdate' => [null, 0, 'id', true],
            'failedInsert' => [false, 0, 0, true],
            'queryInsertAfterFailure' => [1, 1, 'id', false],
            'failedQueryInsert' => [false, 0, 0, true],
            'insertWithoutAutoIncrement' => [1, 1, 0, false],
            'emptyString' => [null, 0, 0, false],
            'zeroString' => ['0', 0, 0, false],
            'sqlNull' => [null, 0, 0, false],
            'deleteMatchingNoRow' => [0, 0, 0, false],
            'drop' => [true, 0, 0, false],
        ];

        self::assertSame($expected, self::runOperationSequence(self::wpdb(), $double));
        self::assertSame($expected, self::runOperationSequence($double, $double));
    }

    /**
     * jtiの獲得・解放・掃除・isClaimed()の読み取りは、テーブル(`%i`)ごとに分かれる。2つのテーブルで同じ名前を使い、
     * 各操作の戻り値・rows_affected・獲得の後のinsert_id・その後の両方のテーブルの読み取りが実wpdbと同じになる。
     * TRUNCATEとDROP(作り直し)は、そのテーブルの獲得を消す。
     *
     * 獲得の後のinsert_idは、正('positive')か0かで比べる。実物の値は、サーバーの自動採番の設定(重複で無視された
     * 挿入が採番を消費するか等)で変わり、代役はその値の一致を約束しないため(WpdbTestDoubleのdocblockの1)。
     * 2つのテーブルの初回の獲得・重複の後の獲得でも、正か0かは一致する。
     */
    public function testJtiStateIsSeparatedByTableLikeRealWpdb(): void
    {
        $expected = [
            ['claim a', 1, 1, 'positive', ['1999999990', null]],
            ['claim b', 1, 1, 'positive', ['1999999990', '1999999990']],
            ['claim a again', 0, 0, 0, ['1999999990', '1999999990']],
            ['claim other in a', 1, 1, 'positive', ['1999999990', '1999999990']],
            ['release a', 1, 1, null, [null, '1999999990']],
            ['gc b', 1, 1, null, [null, null]],
            ['claim a', 1, 1, 'positive', ['1999999990', null]],
            ['release b', 0, 0, null, ['1999999990', null]],
            ['claim b', 1, 1, 'positive', ['1999999990', '1999999990']],
            ['truncate a', true, 0, null, [null, '1999999990']],
            ['claim a after truncate', 1, 1, 'positive', ['1999999990', '1999999990']],
            ['drop b', true, 0, null, ['1999999990', null]],
            ['create b', true, 0, null, ['1999999990', null]],
            ['claim b after drop', 1, 1, 'positive', ['1999999990', '1999999990']],
        ];

        self::assertSame($expected, self::claimInTwoTables(self::wpdb()));
        self::assertSame($expected, self::claimInTwoTables(WpdbTestDouble::create()));
    }

    /**
     * DROP INDEX・DROP VIEWはテーブルの行に作用しないため、獲得済みのjtiが残る(重複の獲得は0)。各段の戻り値・
     * rows_affected・読み取りを実wpdbと比べる。ビューは一時表を参照できないため、テーブルを参照しないビューにする
     * (実物では、ビューの作成と削除が暗黙のコミットを起こすが、この列が変えるのは一時表だけ)。
     */
    public function testDropIndexAndDropViewKeepTheClaimsLikeRealWpdb(): void
    {
        $expected = [
            ['claim', 1, 1, '1999999990'],
            ['drop index', true, 0, '1999999990'],
            ['create view', true, 0, '1999999990'],
            ['drop view', true, 0, '1999999990'],
            ['claim again', 0, 0, '1999999990'],
        ];

        self::assertSame($expected, self::dropIndexAndView(self::wpdb()));
        self::assertSame($expected, self::dropIndexAndView(WpdbTestDouble::create()));
    }

    /**
     * wp_optionsと同じ構造のテーブルでjtiを獲得し、DROP INDEX・CREATE VIEW・DROP VIEWを挟んで、もう一度獲得する。
     * 各段の名前・戻り値・rows_affectedと、その後のisClaimed()の形の読み取りを返す。
     *
     * @return list<array{string, int|bool, int, string|null}>
     */
    private static function dropIndexAndView(wpdb $db): array
    {
        $table = $db->prefix . 'tmswp_parity_options_index';
        $view = $db->prefix . 'tmswp_parity_view';
        $name = 'tmswp_parity_jti_index';
        $results = [];
        self::assertTrue($db->query("CREATE TABLE {$table} (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL DEFAULT '', option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', UNIQUE KEY option_name (option_name), KEY autoload (autoload)) {$db->get_charset_collate()}"));
        $claim = $db->prepare("INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $table, $name, '1999999990');
        self::assertIsString($claim);
        $run = static function (string $label, string $sql) use ($db, $table, $name, &$results): void {
            $result = $db->query($sql);
            $rowsAffected = (int) $db->rows_affected;
            $lookup = $db->prepare('SELECT option_value FROM %i WHERE option_name = %s', $table, $name);
            self::assertIsString($lookup);
            $results[] = [$label, $result, $rowsAffected, $db->get_var($lookup)];
        };

        try {
            $run('claim', $claim);
            $run('drop index', "DROP INDEX autoload ON {$table}");
            $run('create view', "CREATE VIEW {$view} AS SELECT 1 AS x");
            $run('drop view', "DROP VIEW {$view}");
            $claimAgain = $db->prepare("INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $table, $name, '1999999990');
            self::assertIsString($claimAgain);
            $run('claim again', $claimAgain);
        } finally {
            $db->query("DROP VIEW IF EXISTS {$view}");
            self::assertTrue($db->query("DROP TABLE {$table}"));
        }

        return $results;
    }

    /**
     * get_results()は、行が無いときとエラーのときに空の配列を返し、行があるときは出力の形(OBJECT・OBJECT_K・ARRAY_A・
     * ARRAY_N)ごとに同じ形で返す。代役には、実wpdbが読む行と同じ行(MySQLが返す文字列)を入れる。
     */
    public function testGetResultsReturnsTheSameShapesAsRealWpdb(): void
    {
        $wpdb = self::wpdb();
        $double = WpdbTestDouble::create();
        $table = $wpdb->prefix . 'tmswp_parity_rows';
        self::assertTrue($wpdb->query("CREATE TABLE {$table} (id int unsigned NOT NULL PRIMARY KEY, name varchar(20) NOT NULL, flag tinyint NOT NULL)"));
        self::assertSame(1, $wpdb->insert($table, ['id' => 2, 'name' => 'b', 'flag' => 0]));
        self::assertSame(1, $wpdb->insert($table, ['id' => 1, 'name' => 'a', 'flag' => 1]));
        $rows = [['id' => '1', 'name' => 'a', 'flag' => '1'], ['id' => '2', 'name' => 'b', 'flag' => '0']];

        foreach ([OBJECT, OBJECT_K, ARRAY_A, ARRAY_N] as $output) {
            $double->queueResultSet($rows);
            self::assertEquals($wpdb->get_results("SELECT id, name, flag FROM {$table} ORDER BY id", $output), $double->get_results("SELECT id, name, flag FROM {$table} ORDER BY id", $output), $output);
            self::assertSame([], $wpdb->get_results("SELECT id FROM {$table} WHERE id = 0", $output), $output);
            self::assertSame([], $double->get_results("SELECT id FROM {$table} WHERE id = 0", $output), $output);

            $suppressed = $wpdb->suppress_errors(true);

            try {
                self::assertSame([], $wpdb->get_results("SELECT id FROM {$table}_missing", $output), $output);
                self::assertNotSame('', $wpdb->last_error);
            } finally {
                $wpdb->suppress_errors($suppressed);
            }

            $double->failNextRead('Table does not exist');
            self::assertSame([], $double->get_results("SELECT id FROM {$table}_missing", $output), $output);
            self::assertNotSame('', $double->last_error);
        }

        self::assertTrue($wpdb->query("DROP TABLE {$table}"));
    }

    /**
     * 獲得・大文字の名前での重複・大文字の名前での解放・2回目の解放・獲得のやり直しの、それぞれの操作・戻り値・
     * 獲得のinsert_id(正なら'new id')・その後のisClaimed()の形の読み取りの値。
     *
     * @return list<array{string, int|bool, int|string|null, string|null}>
     */
    private static function claimAndReleaseInMixedCase(wpdb $db): array
    {
        $results = [];

        foreach ([
            ['claim', 'tmswp_parity_jti_case'],
            ['claim', 'TMSWP_PARITY_JTI_CASE'],
            ['release', 'TMSWP_PARITY_JTI_CASE'],
            ['release', 'tmswp_parity_jti_case'],
            ['claim', 'tmswp_parity_jti_case'],
        ] as [$operation, $name]) {
            $sql = $operation === 'claim'
                ? $db->prepare("INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $db->options, $name, '2000000000')
                : $db->prepare('DELETE FROM %i WHERE option_name = %s', $db->options, $name);
            self::assertIsString($sql);
            $result = $db->query($sql);
            $insertId = $operation === 'claim' ? ($db->insert_id > 0 ? 'new id' : $db->insert_id) : null;
            $lookup = $db->prepare('SELECT option_value FROM %i WHERE option_name = %s', $db->options, 'tmswp_parity_jti_case');
            self::assertIsString($lookup);
            $results[] = [$operation, $result, $insertId, $db->get_var($lookup)];
        }

        return $results;
    }

    /**
     * 一時のテーブルを作り、insert()・update()・delete()・query()で書き込んで消す。それぞれの戻り値と、rows_affected・
     * insert_idを返す。
     *
     * @return array<string, mixed>
     */
    private static function writeRows(wpdb $db): array
    {
        $table = $db->prefix . 'tmswp_parity_writes';
        $result = [];
        $result['create'] = $db->query("CREATE TABLE {$table} (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(20) NOT NULL, flag tinyint NOT NULL DEFAULT 0)");
        $result['insert'] = [$db->insert($table, ['name' => 'a', 'flag' => 0]), $db->insert($table, ['name' => 'b', 'flag' => 0]), $db->insert_id];
        $result['updateMatchingTwoRows'] = [$db->update($table, ['flag' => 1], ['flag' => 0]), $db->rows_affected];
        $result['updateToTheSameValue'] = [$db->update($table, ['flag' => 1], ['name' => 'a']), $db->rows_affected];
        $result['deleteMatchingNoRow'] = [$db->delete($table, ['name' => 'none']), $db->rows_affected];
        $result['queryInsert'] = [$db->query("INSERT INTO {$table} (name) VALUES ('c')"), $db->insert_id];
        $result['queryDelete'] = [$db->query("DELETE FROM {$table} WHERE flag = 1"), $db->rows_affected];
        $result['drop'] = $db->query("DROP TABLE {$table}");

        return $result;
    }

    /**
     * testOperationSequenceLeavesTheSameStateAsRealWpdbの操作の列を$dbに流す。代役では、失敗させる操作の前に
     * $doubleで失敗を予約する(実wpdbでは、無いテーブル・一意キーの重複で失敗する)。insert_idは、実wpdbの値が
     * 自動採番で環境により変わるため、正なら'id'にして比べる。
     *
     * @return array<string, array{mixed, int, int|string, bool}>
     */
    private static function runOperationSequence(wpdb $db, WpdbTestDouble $double): array
    {
        $isDouble = $db === $double;
        $a = $db->prefix . 'tmswp_parity_sequence';
        $b = $db->prefix . 'tmswp_parity_sequence_keys';
        $state = static fn(mixed $result): array => [$result, (int) $db->rows_affected, (int) $db->insert_id > 0 ? 'id' : (int) $db->insert_id, $db->last_error !== ''];
        $fail = static function (string $method, string $error) use ($double, $isDouble): void {
            if ($isDouble) {
                $method === 'write' ? $double->failNextWrite($error) : $double->failNextRead($error);
            }
        };
        $result = [];
        $suppressed = $db->suppress_errors(true);

        try {
            self::assertTrue($db->query("CREATE TABLE {$a} (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(20) NOT NULL, UNIQUE KEY name (name))"));
            self::assertTrue($db->query("CREATE TABLE {$b} (k varchar(20) NOT NULL PRIMARY KEY)"));
            $result['insert'] = $state($db->insert($a, ['name' => 'a']));
            $result['ddlAfterInsert'] = $state($db->query("ALTER TABLE {$a} ADD COLUMN extra int NULL"));
            $result['queryInsert'] = $state($db->query("INSERT INTO {$a} (name) VALUES ('b')"));
            $result['readAfterInsert'] = $state($db->get_var("SELECT name FROM {$a} WHERE name = 'a'"));
            // 0件のINSERT ... SELECTの戻り値・rows_affected・insert_idを比べる。選ぶ元は空の別のテーブルにする(MySQLは1つの文で
            // 同じ一時テーブルを2回参照できない)
            $result['zeroRowInsert'] = $state($db->query("INSERT INTO {$a} (name) SELECT k FROM {$b} WHERE 1 = 0"));
            $result['insertAfterZeroRows'] = $state($db->insert($a, ['name' => 'c']));
            $result['update'] = $state($db->update($a, ['name' => 'c2'], ['name' => 'c']));
            $fail('read', 'Table does not exist');
            $result['failedReadAfterUpdate'] = $state($db->get_var("SELECT name FROM {$a}_missing"));
            $fail('write', 'Duplicate entry');
            $result['failedInsert'] = $state($db->insert($a, ['name' => 'a']));
            $result['queryInsertAfterFailure'] = $state($db->query("INSERT INTO {$a} (name) VALUES ('d')"));
            $fail('write', 'Table does not exist');
            $result['failedQueryInsert'] = $state($db->query("INSERT INTO {$a}_missing (name) VALUES ('x')"));
            $result['insertWithoutAutoIncrement'] = $state($db->insert($b, ['k' => 'z']));
            $result['emptyString'] = $state($db->get_var("SELECT ''"));
            $result['zeroString'] = $state($db->get_var("SELECT '0'"));
            $result['sqlNull'] = $state($db->get_var('SELECT NULL'));
            $result['deleteMatchingNoRow'] = $state($db->delete($a, ['name' => 'none']));
            self::assertTrue($db->query("DROP TABLE {$b}"));
            $result['drop'] = $state($db->query("DROP TABLE {$a}"));
        } finally {
            $db->suppress_errors($suppressed);
        }

        return $result;
    }

    /**
     * wp_optionsと同じ構造の2つのテーブルで、同じ名前のjtiを獲得・解放・掃除し、TRUNCATE・DROPと作り直しを挟む。
     * 各操作の名前・戻り値・rows_affected・獲得の後のinsert_id(正なら'positive'。獲得でなければnull)と、その後の
     * 2つのテーブルのisClaimed()の形の読み取りを返す。
     *
     * @return list<array{string, int|bool, int, int|string|null, array{string|null, string|null}}>
     */
    private static function claimInTwoTables(wpdb $db): array
    {
        $tables = ['a' => $db->prefix . 'tmswp_parity_options_a', 'b' => $db->prefix . 'tmswp_parity_options_b'];
        $name = 'tmswp_parity_jti_same';
        $create = static fn(string $table): string => "CREATE TABLE {$table} (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL DEFAULT '', option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', UNIQUE KEY option_name (option_name)) {$db->get_charset_collate()}";
        $results = [];

        foreach ($tables as $table) {
            self::assertTrue($db->query($create($table)));
        }

        $lookup = static function (string $table) use ($db, $name): ?string {
            $sql = $db->prepare('SELECT option_value FROM %i WHERE option_name = %s', $table, $name);
            self::assertIsString($sql);

            return $db->get_var($sql);
        };
        // 2つのテーブルの読み取りは、foreachで集めず要素ごとに書く。PHPStan 2.3は、クロージャの中のforeachで集めた
        // 配列を参照で受け取る$resultsに足すと、その配列を要素が1つの配列と推論するため
        $run = static function (string $label, string $sql) use ($db, $tables, $lookup, &$results): void {
            $result = $db->query($sql);
            $rowsAffected = (int) $db->rows_affected;
            $insertId = str_starts_with($label, 'claim') ? ((int) $db->insert_id > 0 ? 'positive' : (int) $db->insert_id) : null;

            $results[] = [$label, $result, $rowsAffected, $insertId, [$lookup($tables['a']), $lookup($tables['b'])]];
        };
        $claim = static function (string $table, string $claimed = 'tmswp_parity_jti_same') use ($db): string {
            $sql = $db->prepare("INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $table, $claimed, '1999999990');
            self::assertIsString($sql);

            return $sql;
        };
        $release = static function (string $table) use ($db, $name): string {
            $sql = $db->prepare('DELETE FROM %i WHERE option_name = %s', $table, $name);
            self::assertIsString($sql);

            return $sql;
        };
        $gc = static function (string $table) use ($db): string {
            $sql = $db->prepare('DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d', $table, $db->esc_like('tmswp_parity_jti_') . '%', 2000000000);
            self::assertIsString($sql);

            return $sql;
        };

        $run('claim a', $claim($tables['a']));
        $run('claim b', $claim($tables['b']));
        $run('claim a again', $claim($tables['a']));
        $run('claim other in a', $claim($tables['a'], 'tmswp_parity_jti_other'));
        $run('release a', $release($tables['a']));
        $run('gc b', $gc($tables['b']));
        $run('claim a', $claim($tables['a']));
        $run('release b', $release($tables['b']));
        $run('claim b', $claim($tables['b']));
        $run('truncate a', "TRUNCATE TABLE {$tables['a']}");
        $run('claim a after truncate', $claim($tables['a']));
        $run('drop b', "DROP TABLE {$tables['b']}");
        $run('create b', $create($tables['b']));
        $run('claim b after drop', $claim($tables['b']));

        foreach ($tables as $table) {
            self::assertTrue($db->query("DROP TABLE {$table}"));
        }

        return $results;
    }

    /**
     * $claimsを獲得し、時刻2000000000で掃除し、名前ごとに獲得をやり直す。SQLはJtiStoreと同じで、接頭辞だけを変える。
     *
     * @param array<string, string> $claims option_name => 値(期限のUNIX時刻)
     *
     * @return array{claims: array<string, int|bool>, deleted: int|bool, claimedAgain: array<string, int|bool>}
     */
    private static function collectGarbageAfterClaims(wpdb $db, array $claims): array
    {
        $claim = static function (string $name, string $expires) use ($db): int|bool {
            $sql = $db->prepare("INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $db->options, $name, $expires);
            self::assertIsString($sql);

            return $db->query($sql);
        };
        $result = ['claims' => [], 'deleted' => false, 'claimedAgain' => []];

        foreach ($claims as $name => $expires) {
            $result['claims'][$name] = $claim($name, $expires);
        }

        $sql = $db->prepare('DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d', $db->options, $db->esc_like('tmswp_parity_jti_') . '%', 2000000000);
        self::assertIsString($sql);
        $result['deleted'] = $db->query($sql);

        foreach (array_keys($claims) as $name) {
            $result['claimedAgain'][$name] = $claim($name, '2000009999');
        }

        return $result;
    }

    /**
     * $prepareの結果を比べられる形にする。SQLは$dbのplaceholder_escape()を印に置き換え、例外(PHPの警告をPHPUnitが
     * 変えた例外を含む)はクラスとメッセージにする。
     *
     * @param Closure(): mixed $prepare
     */
    private static function outcome(wpdb $db, Closure $prepare): mixed
    {
        try {
            return self::marked($db, $prepare());
        } catch (Throwable $exception) {
            return $exception::class . ': ' . $exception->getMessage();
        }
    }

    /** $sqlの中の$dbのplaceholder_escape()を、比べられる印に置き換える。 */
    private static function marked(wpdb $db, mixed $sql): mixed
    {
        return \is_string($sql) ? str_replace($db->placeholder_escape(), '{ESCAPE}', $sql) : $sql;
    }
}

/** 文字列にできるオブジェクトの値。実wpdbは%sの値ではStringableも空文字にし、%iの識別子では文字列にする。 */
final readonly class WpdbTestDoubleParityText implements Stringable
{
    public function __construct(
        private string $text,
    ) {}

    public function __toString(): string
    {
        return $this->text;
    }
}
