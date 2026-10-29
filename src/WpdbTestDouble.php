<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use InvalidArgumentException;
use Mockery;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Stringable;
use wpdb;

// ユニット層には WordPress 本体が無く、wpdb クラスが存在しない。Connection などの型宣言 \wpdb を満たすため、
// この test double を読み込んだときに限り、振る舞いを持たない空の wpdb 型を宣言する。
// wpdb の振る舞いはすべて下の WpdbTestDouble が持つ（偽 wpdb の実装はここだけに置く）。
if (!class_exists(wpdb::class, false)) {
    Mockery::declareClass(wpdb::class);
}

/**
 * ユニット層用の wpdb フェイク。
 *
 * 実行した SQL と insert/update/delete の呼び出しを記録し、読み取り結果はキューから返す。prepare()は実wpdbと同じ
 * SQLを返し(`%`はplaceholder_escape()に置き換わる)、実行したSQLは実wpdbのqueryフィルタと同じく
 * remove_placeholder_escape()を通して記録する。
 *
 * prepare()が実wpdbと意図して違うのは次の5つだけ。
 * 1. placeholder_escape()の文字列は、実wpdbではプロセスで1つ、代役ではインスタンスで1つ。
 * 2. 文字列でもnullでもないSQLは、InvalidArgumentExceptionにする。
 * 3. 文字列の値は、mysqli_real_escape_string()の代わりに同じ文字を置き換える(utf8mb4の接続と通常のSQLモードと同じ
 *    結果。NO_BACKSLASH_ESCAPESと不正なバイト列は対象外)。
 * 4. allow_unsafe_unquoted_parametersは、実wpdbの既定値(true)の振る舞いだけを持つ。
 * 5. `%i`の位置の値(番号付きの書式で使う引数を含む)が、配列・resource・null・Stringableでないオブジェクトなら、
 *    InvalidArgumentExceptionにする。実wpdbはこれらにPHPの警告・非推奨の通知・TypeErrorを出し、結果はPHPの弱い
 *    型の変換と診断の順序で決まるため、再現しない。
 *
 * 対応する操作は、同じ順序の操作を同じインスタンスに流したとき、戻り値と状態(rows_affected・insert_id・last_error)
 * が実wpdbと同じになるようにする。ただし、JtiStoreの獲得の後のinsert_idは、下の1のとおり正か0かだけを約束する。
 * 代役が決められない形は、黙って値を返さずにInvalidArgumentExceptionにする。
 *
 * 状態は実wpdbと同じく扱う。query()・get_results()・get_row()・get_var()・insert()・update()・delete()は、開始時に
 * 実wpdbのflush()と同じくrows_affectedを0に、last_errorを空にする(読み取り・DDL・失敗の後のrows_affectedは0)。
 * insert_idは、INSERT・REPLACEが行を作れば新しいID、行を作らなければ(0件・失敗)0にし、ほかの操作では変えない。
 *
 * query()が対応する形は次の4つだけ。
 * 1. JtiStoreの獲得(INSERT IGNORE)・解放(option_name = %sのDELETE)・掃除(LIKEと期限のDELETE)。直前のprepare()に
 *    JtiStoreと同じSQLを渡し、その戻り値を実行したときだけ、この形とみなす。獲得済みのoption_nameと値を`%i`の
 *    テーブルごとに覚え、実wpdbと同じ件数を返す。テーブルはwp_optionsと同じ構造(AUTO_INCREMENTのoption_id、
 *    大文字と小文字を区別しないoption_nameの一意キー)とみなす。獲得の後のinsert_idは、行を作れば正の値、重複で
 *    無視されれば0にする。値そのものは、実物のサーバーの自動採番の設定(重複で無視された挿入が採番を消費するか
 *    等。innodb_autoinc_lock_mode)に依存するため、一致を約束しない(JtiStoreはinsert_idを使わない)。掃除の値は
 *    10進の数字だけを扱う。この形でないINSERT IGNOREと、option_nameを含むDELETEは例外にする。
 * 2. DDL。実wpdbと同じくtrueを返す。受け付けるのは次の形だけで、ほかのDROP・TRUNCATEは例外にする。
 *    - CREATE・ALTER。状態を変えない。ただし、単語のRENAME・PARTITION・TABLESPACE(大文字と小文字を区別しない)を
 *      含むALTERは、テーブルの名前や行に作用するため例外にする。単独の文のRENAME TABLEも例外にする。
 *    - 単一のテーブルの`TRUNCATE [TABLE] t`と`DROP TABLE [IF EXISTS] t [RESTRICT|CASCADE]`。そのテーブルの獲得済みの
 *      jtiも消す(実物では行が消えるため)。テーブル名は、データベース名で修飾しない1つだけを受け付ける。
 *    - `DROP INDEX`・`DROP VIEW`。テーブルの行に作用しないため、状態を変えない。
 *    `DROP TEMPORARY TABLE`は、代役が一時表と通常表を区別して持たないため例外にする。DROP・TRUNCATEの文字列に
 *    SQLのコメント(`/*`・`--`・`#`)が含まれていれば、形を判定せずに例外にする。
 * 3. INSERT・UPDATE・DELETE・REPLACE。代役は行を持たないため、queueAffectedRows()で入れた、影響した行の数を返す。
 *    行を作ったINSERT・REPLACEのinsert_idは、queueInsertId()で入れたIDにする(AUTO_INCREMENTの列の無いテーブル
 *    では0を入れる)。入れていなければ例外にする。
 * 4. failNextWrite()を予約した呼び出し。形に関係なくfalseを返す。
 * SELECT等、ほかの形は例外にする。update()・delete()も、queueAffectedRows()で入れた数を返す(MySQLが実際に変えた
 * 行の数で、同じ値への更新は0)。insert()は1行を挿入するため、実wpdbと同じく1を返し、insert_idは
 * queueInsertId()で入れたIDにする。
 *
 * get_results()は、行が無いとき(キューが空)とエラーのとき、実wpdbと同じく空の配列を返す。出力の形はOBJECT・
 * OBJECT_K(1列目の値をキーにする)・ARRAY_A・ARRAY_Nだけを受け付ける。get_row()はOBJECT・ARRAY_A・ARRAY_Nと
 * 先頭の行だけを、get_var()は先頭の行の先頭の列だけを受け付ける(ほかの位置は例外)。get_var()は、実wpdbと同じく
 * 空文字をnullで返す。JtiStore::isClaimed()の形(直前のprepare()の戻り値)なら、キューではなく、そのテーブルの
 * 獲得済みの状態から値を返す。
 *
 * failNextWrite() で、書き込み系の呼び出しを実 wpdb の失敗と同じく false で返させられる。
 * failNextRead()で、読み取り系の呼び出しを実wpdbの失敗と同じくlast_errorを残して失敗させられる(get_row()・
 * get_var()はnull、get_results()は空の配列を返す)。
 */
final class WpdbTestDouble extends wpdb
{
    /** JtiStore::claim()のSQL(prepare()に渡す形) */
    private const JTI_CLAIM_SQL = "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')";

    /** JtiStore::release()のSQL */
    private const JTI_RELEASE_SQL = 'DELETE FROM %i WHERE option_name = %s';

    /** JtiStore::isClaimed()のSQL */
    private const JTI_READ_SQL = 'SELECT option_value FROM %i WHERE option_name = %s';

    /** JtiStore::gc()のSQL */
    private const JTI_GARBAGE_COLLECTION_SQL = 'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d';

    /** @var string */
    public $prefix = 'wp_';

    /** @var string */
    public $options = 'wp_options';

    /** @var int */
    public $insert_id = 0;

    /** @var int */
    public $rows_affected = 0;

    /** @var string */
    public $last_error = '';

    /**
     * 実 wpdb が実行したクエリの数。この代役のメソッドは数えない(WpDatabaseGuard は、呼び出しの間に増えたときだけ
     * last_error を見る)。データベースのエラーを再現するテストが、エラーと一緒に増やす。
     *
     * @var int
     */
    public $num_queries = 0;

    /** @var list<string> */
    private array $tmswpQueries = [];

    /** @var list<array{table: string, data: array<string, mixed>, format: list<string>|string|null}> */
    private array $tmswpInsertCalls = [];

    /** @var list<array{table: string, data: array<string, mixed>, where: array<string, mixed>, format: list<string>|string|null, where_format: list<string>|string|null}> */
    private array $tmswpUpdateCalls = [];

    /** @var list<array{table: string, where: array<string, mixed>, where_format: list<string>|string|null}> */
    private array $tmswpDeleteCalls = [];

    /** @var list<list<array<string, mixed>>> */
    private array $tmswpResultSets = [];

    /** @var list<array<string, mixed>|null> */
    private array $tmswpRows = [];

    /** @var list<string|null> */
    private array $tmswpVars = [];

    /** @var array<string, array<string, string>> 獲得済みのjti(テーブル => option_name => 値) */
    private array $tmswpClaimedJtis = [];

    private string $tmswpLastQuery = '';

    /** 直前のprepare()が返したSQL。失敗したprepare()の後はnull。JtiStoreの形の判定は、このSQLを実行したときだけ行う。 */
    private ?string $tmswpLastPrepared = null;

    /** JtiStoreの獲得で採番した最後の値。獲得の後のinsert_idを正の値にするために使う(値の一致は約束しない)。 */
    private int $tmswpAutoIncrement = 0;

    /** @var list<int> 行を作るINSERT・REPLACE・insert()のinsert_idにするID */
    private array $tmswpInsertIds = [];

    /** @var list<int> update()・delete()・query()の書き込みが返す、影響した行の数 */
    private array $tmswpAffectedRows = [];

    /** 次の書き込み系呼び出しを失敗させるときに wpdb::$last_error へ入れる値。null なら失敗させない。 */
    private ?string $tmswpNextWriteError = null;

    /** 次の読み取り系呼び出しを失敗させるときに wpdb::$last_error へ入れる値。null なら失敗させない。 */
    private ?string $tmswpNextReadError = null;

    /** @var list<mixed> */
    private array $tmswpLastArgs = [];

    /** @var list<string> */
    private array $tmswpPrepareNotices = [];

    private ?string $tmswpPlaceholderEscape = null;

    public static function create(): self
    {
        $reflection = new ReflectionClass(self::class);
        $wpdb = $reflection->newInstanceWithoutConstructor();

        if (!$wpdb instanceof self) {
            throw new RuntimeException('failed to create wpdb test double');
        }

        return $wpdb;
    }

    /**
     * wpdb の出力形式定数を定義する（実 WordPress では wp-includes/class-wpdb.php が定義する）。
     *
     * ユニット層では WP 定数を定義しない（docs/10 §4.4）。ただし wpdb 境界そのものである
     * Connection::select()/selectRow() は ARRAY_A を参照するため、それを通すテストに限ってこの定数が要る。
     * 定数は取り消せずプロセス全体に漏れるので、呼び出すテストは必ず #[RunInSeparateProcess] で分離する。
     * 分離されずに二度定義されると define() の警告で失敗する（failOnWarning）。
     */
    public static function defineOutputConstants(): void
    {
        \define('OBJECT', 'OBJECT');
        \define('OBJECT_K', 'OBJECT_K');
        \define('ARRAY_A', 'ARRAY_A');
        \define('ARRAY_N', 'ARRAY_N');
    }

    /**
     * 次の書き込み系呼び出し（query / insert / update / delete）を、実 wpdb の失敗と同じく false で返させる。
     *
     * $lastError が空でなければ wpdb::$last_error に設定する（実 wpdb は DB エラー時に MySQL のエラー文を入れる）。
     * 空のままなら、last_error を伴わずに false だけが返る経路を再現する。
     */
    public function failNextWrite(string $lastError = ''): void
    {
        $this->tmswpNextWriteError = $lastError;
    }

    /**
     * 次の読み取り系呼び出し（get_results / get_row / get_var）を、実 wpdb の失敗と同じく失敗させる。
     *
     * 実wpdbの読み取り系はデータベースのエラーでもfalseを返さず、get_row()・get_var()はnullを、get_results()は
     * 空の配列を返して、wpdb::$last_errorにMySQLのエラー文を残す。キューの値は消費しない。
     */
    public function failNextRead(string $lastError): void
    {
        if ($lastError === '') {
            throw new InvalidArgumentException('a failed read must leave the error in last_error');
        }

        $this->tmswpNextReadError = $lastError;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function queueResultSet(array $rows): void
    {
        $this->tmswpResultSets[] = $rows;
    }

    /** @param array<string, mixed>|null $row */
    public function queueRow(?array $row): void
    {
        $this->tmswpRows[] = $row;
    }

    public function queueVar(?string $value): void
    {
        $this->tmswpVars[] = $value;
    }

    /**
     * 次の書き込み(update()・delete()と、query()で実行するINSERT・UPDATE・DELETE・REPLACE)が返す、影響した行の数を
     * 入れる。代役はテーブルの行を持たず、実wpdbが返す数(MySQLが実際に変えた行の数)を決められないため、テストが
     * 入れる。入れずに書き込むと例外になる。
     */
    public function queueAffectedRows(int $rows): void
    {
        if ($rows < 0) {
            throw new InvalidArgumentException('affected rows must not be negative');
        }

        $this->tmswpAffectedRows[] = $rows;
    }

    /**
     * 次に行を作るINSERT・REPLACE(insert()と、query()で実行して1行以上を作るもの)のinsert_idを入れる。新しいIDか、
     * AUTO_INCREMENTの列の無いテーブルなら0になるかを代役は決められないため、テストが入れる。入れずに行を作ると
     * 例外になる。0件や失敗のINSERT・REPLACEは、実wpdbと同じくinsert_idを0にし、入れたIDを使わない。
     */
    public function queueInsertId(int $id): void
    {
        if ($id < 0) {
            throw new InvalidArgumentException('insert id must not be negative');
        }

        $this->tmswpInsertIds[] = $id;
    }

    /** @return list<string> */
    public function queries(): array
    {
        return $this->tmswpQueries;
    }

    /** @return list<array{table: string, data: array<string, mixed>, format: list<string>|string|null}> */
    public function insertCalls(): array
    {
        return $this->tmswpInsertCalls;
    }

    /** @return list<array{table: string, data: array<string, mixed>, where: array<string, mixed>, format: list<string>|string|null, where_format: list<string>|string|null}> */
    public function updateCalls(): array
    {
        return $this->tmswpUpdateCalls;
    }

    /** @return list<array{table: string, where: array<string, mixed>, where_format: list<string>|string|null}> */
    public function deleteCalls(): array
    {
        return $this->tmswpDeleteCalls;
    }

    /**
     * wpdb::prepare()のnotice(実wpdbが_doing_it_wrong()で出すもの)の記録。
     *
     * @return list<string>
     */
    public function prepareNotices(): array
    {
        return $this->tmswpPrepareNotices;
    }

    /**
     * 実wpdb::prepare()(WordPress 7.1のwp-includes/class-wpdb.php)と同じ手順でSQLを組み立てる。
     *
     * allow_unsafe_unquoted_parametersは実wpdbの既定値(true)の振る舞いだけを持つ。文字列の値は、MySQLへの接続が
     * 無いためmysqli_real_escape_string()と同じ文字を同じ形でエスケープする(utf8mb4の接続と同じ結果)。
     * 実wpdbが_doing_it_wrong()を出す場合は、noticeをprepareNotices()に記録し、実wpdbと同じ値を返す。
     * 戻り値の`%`はすべてplaceholder_escape()に置き換わっている(実wpdbと同じ)。
     *
     * 戻り値の型は実wpdb(スタブ)の宣言に合わせる。実wpdbと同じく、nullを返す場合は値の無いreturnで返す。
     *
     * @param mixed $query
     * @param mixed ...$args
     *
     * @return string|void
     */
    public function prepare($query, ...$args)
    {
        if ($query === null) {
            return;
        }

        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        $this->tmswpLastQuery = $query;
        $this->tmswpLastPrepared = null;

        if (!str_contains($query, '%')) {
            $this->tmswpPrepareNotices[] = 'The query argument of wpdb::prepare() must have a placeholder.';
        }

        $allowedFormat = '(?:[1-9][0-9]*[$])?[-+0-9]*(?: |0|\'.)?[-+0-9]*(?:\.[0-9]+)?';
        $query = str_replace("'%s'", '%s', $query);
        $query = str_replace('"%s"', '%s', $query);
        $query = (string) preg_replace("/%(?:%|$|(?!({$allowedFormat})?[sdfFi]))/", '%%\1', $query);
        $split = preg_split("/(^|[^%]|(?:%%)+)(%(?:{$allowedFormat})?[sdfFi])/", $query, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($split === false) {
            throw new RuntimeException('failed to split SQL placeholders');
        }

        $splitCount = \count($split);
        $placeholderCount = intdiv($splitCount - 1, 3);
        $passedAsArray = \count($args) === 1 && \is_array($args[0]);
        // 実wpdbと同じく、配列1つで渡された値はキーを保つ(識別子の引数の位置はキーで照らし合わせる)
        $args = $passedAsArray ? $args[0] : $args;
        $this->tmswpLastArgs = array_values($args);

        $newQuery = '';
        $key = 2;
        $argId = 0;
        $identifierArgs = [];
        $stringArgs = [];

        while ($key < $splitCount) {
            $placeholder = $split[$key];
            $format = substr($placeholder, 1, -1);
            $type = substr($placeholder, -1);

            if ($type === 'f' && str_ends_with($split[$key - 1], '%')) {
                // `%%%f`等: 直前の`%`の数で、この`%f`がプレースホルダかリテラルかを決める
                $preceding = $split[$key - 2] . $split[$key - 1];
                $length = \strlen($preceding);
                $k = 1;

                while ($k <= $length && $preceding[$length - $k] === '%') {
                    ++$k;
                }

                $placeholder = '%' . ($k % 2 === 1 ? '%' : '') . $format . $type;
                --$placeholderCount;
            } else {
                if ($type === 'f') {
                    $type = 'F';
                    $placeholder = '%' . $format . $type;
                }

                if ($type === 'i') {
                    $placeholder = '`%' . $format . 's`';
                    $identifierArgs[] = self::argumentPosition($format, $argId);
                } elseif ($type !== 'd' && $type !== 'F') {
                    $stringArgs[] = self::argumentPosition($format, $argId);

                    if ($format === '' && !str_ends_with($split[$key - 1], '%')) {
                        $placeholder = "'%" . $format . "s'";
                    }
                }
            }

            $newQuery .= $split[$key - 2] . $split[$key - 1] . $placeholder;
            $key += 3;
            ++$argId;
        }

        $query = $newQuery . $split[$key - 2];

        if (array_intersect($identifierArgs, $stringArgs) !== []) {
            $this->tmswpPrepareNotices[] = 'Arguments cannot be prepared as both an Identifier and Value.';

            return;
        }

        $argsCount = \count($args);

        if ($argsCount !== $placeholderCount) {
            if ($placeholderCount === 1 && $passedAsArray) {
                $this->tmswpPrepareNotices[] = 'The query only expected one placeholder, but an array of multiple placeholders was sent.';

                return;
            }

            $this->tmswpPrepareNotices[] = "The query does not contain the correct number of placeholders ({$placeholderCount}) for the number of arguments passed ({$argsCount}).";

            if ($argsCount < $placeholderCount) {
                $maxNumbered = 0;

                for ($i = 2; $i < $splitCount; $i += 3) {
                    $maxNumbered = max($maxNumbered, (int) substr($split[$i], 1));
                }

                if ($maxNumbered === 0 || $argsCount < $maxNumbered) {
                    return '';
                }
            }
        }

        $escapedArgs = [];

        foreach ($args as $i => $value) {
            if (\in_array($i, $identifierArgs, true)) {
                $escapedArgs[] = self::escapeIdentifierValue($value);
            } elseif (\is_int($value) || \is_float($value)) {
                $escapedArgs[] = $value;
            } else {
                if (!\is_scalar($value) && $value !== null) {
                    $this->tmswpPrepareNotices[] = 'Unsupported value type (' . \gettype($value) . ').';
                    $value = '';
                }

                $escapedArgs[] = $this->realEscape($value);
            }
        }

        $this->tmswpLastPrepared = $this->add_placeholder_escape(vsprintf($query, $escapedArgs));

        return $this->tmswpLastPrepared;
    }

    /**
     * 実wpdbと同じく、prepare()の戻り値の`%`を置き換える文字列(`{` + 64桁の16進 + `}`)を返す。インスタンスごとに1つ。
     */
    public function placeholder_escape(): string
    {
        $this->tmswpPlaceholderEscape ??= '{' . hash_hmac('sha256', uniqid('', true), random_bytes(16)) . '}';

        return $this->tmswpPlaceholderEscape;
    }

    /**
     * 実wpdbと同じく、`%`をすべてplaceholder_escape()に置き換える。
     *
     * @param mixed $query
     */
    public function add_placeholder_escape($query): string
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        return str_replace('%', $this->placeholder_escape(), $query);
    }

    /**
     * 実wpdbと同じく、placeholder_escape()を`%`に戻す。実wpdbはqueryフィルタ(優先度0)でこれを呼ぶため、
     * この代役のquery()と読み取り系も、記録する前にこれを通す。
     *
     * @param mixed $query
     */
    public function remove_placeholder_escape($query): string
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        return str_replace($this->placeholder_escape(), '%', $query);
    }

    /**
     * 実wpdbと同じく、行が無いとき(キューが空)とエラーのときは空の配列を返す。
     *
     * @param mixed $query
     * @param mixed $output
     *
     * @return ($output is 'ARRAY_A' ? list<array<mixed>> : ($output is 'ARRAY_N' ? list<array<int, mixed>> : ($output is 'OBJECT_K' ? array<stdClass> : list<stdClass>)))
     */
    public function get_results($query = null, $output = \OBJECT): array
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        if (!\in_array($output, [\OBJECT, \OBJECT_K, \ARRAY_A, \ARRAY_N], true)) {
            throw new InvalidArgumentException('WpdbTestDouble emulates get_results() only with OBJECT, OBJECT_K, ARRAY_A and ARRAY_N');
        }

        $this->tmswpQueries[] = $this->remove_placeholder_escape($query);

        // 実wpdbは、エラーでも行が無くても、空にしたlast_resultから空の配列を返す
        if ($this->consumeReadFailure()) {
            return [];
        }

        $rows = array_shift($this->tmswpResultSets) ?? [];

        return match ($output) {
            \ARRAY_A => $rows,
            \ARRAY_N => array_map(static fn(array $row): array => array_values($row), $rows),
            \OBJECT_K => self::keyedByFirstColumn($rows),
            default => array_map(static fn(array $row): stdClass => (object) $row, $rows),
        };
    }

    /**
     * 実wpdbのOBJECT_Kと同じく、1列目の値をキーにした行のオブジェクトにする。同じキーの行は、最初の行だけを残す。
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<stdClass>
     */
    private static function keyedByFirstColumn(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $key = array_values($row)[0] ?? '';

            if (!\is_string($key) && !\is_int($key)) {
                throw new InvalidArgumentException('WpdbTestDouble emulates OBJECT_K only with a string, int or null first column, got ' . get_debug_type($key));
            }

            $keyed[$key] ??= (object) $row;
        }

        return $keyed;
    }

    /**
     * @param mixed $query
     * @param mixed $output
     * @param mixed $y
     */
    public function get_row($query = null, $output = \OBJECT, $y = 0)
    {
        if ($query === null) {
            return;
        }

        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        if (!\in_array($output, [\OBJECT, \ARRAY_A, \ARRAY_N], true) || $y !== 0) {
            throw new InvalidArgumentException('WpdbTestDouble emulates get_row() only with OBJECT, ARRAY_A and ARRAY_N and the first row');
        }

        $this->tmswpQueries[] = $this->remove_placeholder_escape($query);

        if ($this->consumeReadFailure()) {
            return null;
        }

        $row = array_shift($this->tmswpRows) ?? null;

        if ($row === null || $output === \ARRAY_A) {
            return $row;
        }

        if ($output === \ARRAY_N) {
            return array_values($row);
        }

        return (object) $row;
    }

    /**
     * @param mixed $query
     * @param mixed $x
     * @param mixed $y
     */
    public function get_var($query = null, $x = 0, $y = 0): ?string
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        if ($x !== 0 || $y !== 0) {
            throw new InvalidArgumentException('WpdbTestDouble emulates get_var() only for the first column of the first row');
        }

        $this->tmswpQueries[] = $this->remove_placeholder_escape($query);

        if ($this->consumeReadFailure()) {
            return null;
        }

        // JtiStore::isClaimed(): キューではなく、獲得・解放・掃除と同じ獲得済みの状態から答える
        if ($query === $this->tmswpLastPrepared && $this->tmswpLastQuery === self::JTI_READ_SQL) {
            [$table, $name] = $this->jtiTableAndName('lookup');
            $key = $this->claimedJtiKey($table, $name);
            $value = $key === null ? null : $this->tmswpClaimedJtis[$table][$key];
        } else {
            $value = array_shift($this->tmswpVars);
        }

        // 実wpdbと同じく、空文字はnullで返す
        return $value === '' ? null : $value;
    }

    /**
     * 実wpdb::query()と同じ意味の戻り値を返す。対応する形は、クラスのdocblockの「query()が対応する形」のとおり。
     *
     * @param mixed $query
     *
     * @throws InvalidArgumentException 対応しない形のSQL、または影響した行の数を入れていない書き込み
     */
    public function query($query): int|bool
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        $sql = $this->remove_placeholder_escape($query);
        $this->tmswpQueries[] = $sql;

        if ($this->consumeWriteFailure()) {
            // 実wpdbと同じく、失敗したINSERT・REPLACEはinsert_idを0にする
            if (self::isInsertOrReplace($sql)) {
                $this->insert_id = 0;
            }

            return false;
        }

        $template = $query === $this->tmswpLastPrepared ? $this->tmswpLastQuery : null;

        return match (true) {
            $template === self::JTI_CLAIM_SQL => $this->claimJti(),
            $template === self::JTI_RELEASE_SQL => $this->releaseJti(),
            $template === self::JTI_GARBAGE_COLLECTION_SQL => $this->collectJtiGarbage(),
            preg_match('/^\s*(create|alter|truncate|drop)\s/i', $sql) === 1 => $this->ddl($sql),
            preg_match('/^\s*insert\s+ignore\s/i', $sql) === 1,
            preg_match('/^\s*delete\s/i', $sql) === 1 && str_contains($sql, 'option_name') => throw new InvalidArgumentException("WpdbTestDouble emulates INSERT IGNORE and DELETE of option_name only in the forms of JtiStore prepared with prepare(): {$sql}"),
            preg_match('/^\s*(insert|delete|update|replace)\s/i', $sql) === 1 => $this->writeQuery($sql),
            default => throw new InvalidArgumentException("WpdbTestDouble does not emulate the query: {$sql}; it emulates DDL, INSERT, UPDATE, DELETE, REPLACE and the queries of JtiStore"),
        };
    }

    /**
     * @param mixed $table
     * @param mixed $data
     * @param mixed $format
     */
    public function insert($table, $data, $format = null): int|false
    {
        if (!\is_string($table) || !\is_array($data) || (!\is_array($format) && !\is_string($format) && $format !== null)) {
            throw new InvalidArgumentException('invalid insert arguments');
        }

        $this->tmswpInsertCalls[] = ['table' => $table, 'data' => $this->stringKeyedArray($data), 'format' => $this->normalizeFormat($format)];
        // 実wpdbのinsert()は、実行の前にinsert_idを0にする(失敗ならそのまま)
        $this->insert_id = 0;

        if ($this->consumeWriteFailure()) {
            return false;
        }

        $this->insert_id = $this->consumeInsertId('insert()');

        return $this->rows_affected = 1;
    }

    /**
     * @param mixed $table
     * @param mixed $data
     * @param mixed $where
     * @param mixed $format
     * @param mixed $where_format
     */
    public function update($table, $data, $where, $format = null, $where_format = null): int|false
    {
        if (!\is_string($table) || !\is_array($data) || !\is_array($where) || (!\is_array($format) && !\is_string($format) && $format !== null) || (!\is_array($where_format) && !\is_string($where_format) && $where_format !== null)) {
            throw new InvalidArgumentException('invalid update arguments');
        }

        $this->tmswpUpdateCalls[] = [
            'table' => $table,
            'data' => $this->stringKeyedArray($data),
            'where' => $this->stringKeyedArray($where),
            'format' => $this->normalizeFormat($format),
            'where_format' => $this->normalizeFormat($where_format),
        ];

        if ($this->consumeWriteFailure()) {
            return false;
        }

        return $this->rows_affected = $this->consumeAffectedRows('update()');
    }

    /**
     * @param mixed $table
     * @param mixed $where
     * @param mixed $where_format
     */
    public function delete($table, $where, $where_format = null): int|false
    {
        if (!\is_string($table) || !\is_array($where) || (!\is_array($where_format) && !\is_string($where_format) && $where_format !== null)) {
            throw new InvalidArgumentException('invalid delete arguments');
        }

        $this->tmswpDeleteCalls[] = [
            'table' => $table,
            'where' => $this->stringKeyedArray($where),
            'where_format' => $this->normalizeFormat($where_format),
        ];

        if ($this->consumeWriteFailure()) {
            return false;
        }

        return $this->rows_affected = $this->consumeAffectedRows('delete()');
    }

    /** @param mixed $text */
    public function esc_like($text): string
    {
        if (!\is_string($text)) {
            throw new InvalidArgumentException('text must be string');
        }

        return addcslashes($text, '_%\\');
    }

    /**
     * MySQLのLIKEのパターンを、同じ文字列に合う正規表現にする。`%`は任意の文字列、`_`は任意の1文字、`\`の次の文字は
     * その文字そのもの(esc_like()で戻した文字)。wp_optionsの既定の照合順序と同じく、大文字と小文字を区別しない。
     */
    private static function likePatternToRegex(string $pattern): string
    {
        $chars = preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            throw new InvalidArgumentException('LIKE pattern must be valid UTF-8');
        }

        $regex = '';
        $count = \count($chars);

        for ($i = 0; $i < $count; ++$i) {
            $regex .= match (true) {
                $chars[$i] === '\\' && $i + 1 < $count => preg_quote($chars[++$i], '/'),
                $chars[$i] === '%' => '.*',
                $chars[$i] === '_' => '.',
                default => preg_quote($chars[$i], '/'),
            };
        }

        return '/\A' . $regex . '\z/isu';
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /** JtiStore::claim(): そのテーブルでoption_nameを獲得する。大文字と小文字を区別せずに同じ名前があれば重複(0)。 */
    private function claimJti(): int
    {
        [$table, $name] = $this->jtiTableAndName('claim');
        $expires = $this->tmswpLastArgs[2] ?? null;

        if (!\is_string($expires)) {
            throw new InvalidArgumentException('invalid JTI claim arguments');
        }

        // 行を作らなかった(無視された)INSERT IGNOREのinsert_idは0、作ったら正の値(値はクラスのdocblockの1のとおり約束しない)
        if ($this->claimedJtiKey($table, $name) !== null) {
            $this->insert_id = 0;

            return 0;
        }

        $this->tmswpClaimedJtis[$table][$name] = $expires;
        $this->insert_id = ++$this->tmswpAutoIncrement;

        return $this->rows_affected = 1;
    }

    /** JtiStore::release(): そのテーブルの獲得済みのoption_name(大文字と小文字を区別しない)を手放す。 */
    private function releaseJti(): int
    {
        [$table, $name] = $this->jtiTableAndName('release');
        $key = $this->claimedJtiKey($table, $name);

        if ($key === null) {
            return 0;
        }

        unset($this->tmswpClaimedJtis[$table][$key]);

        return $this->rows_affected = 1;
    }

    /** JtiStore::gc(): そのテーブルの獲得済みのjtiのうち、名前がLIKEのパターンに合い、期限(値)が時刻より前のものを消す。 */
    private function collectJtiGarbage(): int
    {
        $table = $this->tmswpLastArgs[0] ?? null;
        $pattern = $this->tmswpLastArgs[1] ?? null;
        $threshold = $this->tmswpLastArgs[2] ?? null;

        if (!\is_string($table) || !\is_string($pattern) || !\is_int($threshold)) {
            throw new InvalidArgumentException('invalid JTI garbage collection arguments');
        }

        $regex = self::likePatternToRegex($pattern);
        $deleted = 0;

        foreach ($this->tmswpClaimedJtis[$table] ?? [] as $name => $expires) {
            if (!ctype_digit($expires)) {
                throw new InvalidArgumentException("WpdbTestDouble emulates CAST(option_value AS UNSIGNED) only for decimal digits, got '{$expires}'");
            }

            if (preg_match($regex, (string) $name) === 1 && (int) $expires < $threshold) {
                unset($this->tmswpClaimedJtis[$table][$name]);
                ++$deleted;
            }
        }

        return $this->rows_affected = $deleted;
    }

    /**
     * JtiStoreの形のSQLの、テーブル(`%i`の値)とoption_name。
     *
     * @return array{string, string}
     */
    private function jtiTableAndName(string $operation): array
    {
        $table = $this->tmswpLastArgs[0] ?? null;
        $name = $this->tmswpLastArgs[1] ?? null;

        if (!\is_string($table) || !\is_string($name)) {
            throw new InvalidArgumentException("invalid JTI {$operation} arguments");
        }

        return [$table, $name];
    }

    /** そのテーブルの獲得済みのoption_nameのうち、$nameと大文字と小文字を区別せずに同じもの(wp_optionsの照合順序と同じ)。 */
    private function claimedJtiKey(string $table, string $name): ?string
    {
        foreach (array_keys($this->tmswpClaimedJtis[$table] ?? []) as $key) {
            if (mb_strtolower((string) $key) === mb_strtolower($name)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * query()で実行するINSERT・UPDATE・DELETE・REPLACE。影響した行の数は、テストが入れた数を返す。INSERT・REPLACEの
     * insert_idは、行を作らなければ0、作ればテストが入れたID。
     */
    private function writeQuery(string $sql): int
    {
        $rows = $this->consumeAffectedRows("query({$sql})");

        if (self::isInsertOrReplace($sql)) {
            $this->insert_id = $rows === 0 ? 0 : $this->consumeInsertId("query({$sql})");
        }

        return $this->rows_affected = $rows;
    }

    /**
     * DDLはtrueを返す。受け付ける形は、クラスのdocblockの2のとおり。TRUNCATE TABLEとDROP TABLEは、実物と同じく、
     * そのテーブルの獲得済みのjtiを消す。
     *
     * @throws InvalidArgumentException 受け付けない形のDROP・TRUNCATE、SQLのコメントを含むDROP・TRUNCATE、または
     *                                  RENAME・PARTITION・TABLESPACEを含むALTER
     */
    private function ddl(string $sql): bool
    {
        if (preg_match('/^\s*create\s/i', $sql) === 1) {
            return true;
        }

        // テーブルの名前を変える・パーティションやテーブルスペースを操作するALTERは、行やテーブルの同一性に作用する
        if (preg_match('/^\s*alter\s/i', $sql) === 1) {
            if (preg_match('/\b(rename|partition|tablespace)\b/i', $sql) === 1) {
                throw new InvalidArgumentException("WpdbTestDouble does not emulate the query: {$sql}; it rejects ALTER containing RENAME, PARTITION or TABLESPACE");
            }

            return true;
        }

        if (str_contains($sql, '/*') || str_contains($sql, '--') || str_contains($sql, '#')) {
            throw new InvalidArgumentException("WpdbTestDouble does not emulate the query: {$sql}; it rejects DROP and TRUNCATE containing an SQL comment");
        }

        if (preg_match('/^\s*drop\s+temporary\s/i', $sql) === 1) {
            throw new InvalidArgumentException("WpdbTestDouble does not emulate the query: {$sql}; it does not tell temporary tables from regular tables");
        }

        if (preg_match('/^\s*drop\s+(index|view)\s/i', $sql) === 1) {
            return true;
        }

        $unsupported = "WpdbTestDouble does not emulate the query: {$sql}; it emulates TRUNCATE and DROP TABLE of a single table named without the database, DROP INDEX and DROP VIEW";

        if (preg_match('/^\s*(truncate(\s+table)?|drop\s+table(\s+if\s+exists)?)\s/i', $sql, $matches) !== 1) {
            throw new InvalidArgumentException($unsupported);
        }

        $identifier = '(?:`(?:[^`]|``)+`|[A-Za-z0-9_$]+)';
        // RESTRICT・CASCADEはDROP TABLEだけの句。MySQLのTRUNCATEには無く、実物では構文の誤りになる
        $trailingClause = stripos(ltrim($sql), 'drop') === 0 ? '(?:(?:restrict|cascade)\s*)?' : '';

        if (preg_match('/\G\s*(' . $identifier . ')\s*' . $trailingClause . ';?\s*\z/i', $sql, $table, 0, \strlen($matches[0]) - 1) !== 1) {
            throw new InvalidArgumentException($unsupported);
        }

        $name = $table[1];

        if (str_starts_with($name, '`')) {
            $name = str_replace('``', '`', substr($name, 1, -1));
        }

        unset($this->tmswpClaimedJtis[$name]);

        return true;
    }

    private static function isInsertOrReplace(string $sql): bool
    {
        return preg_match('/^\s*(insert|replace)\s/i', $sql) === 1;
    }

    /** queueInsertId()で入れたIDを取り出す。入れていなければ、黙ってIDを決めずに例外にする。 */
    private function consumeInsertId(string $call): int
    {
        $id = array_shift($this->tmswpInsertIds);

        if ($id === null) {
            throw new InvalidArgumentException("WpdbTestDouble cannot tell the insert_id of the row {$call} creates; call queueInsertId() before it");
        }

        return $id;
    }

    /** queueAffectedRows()で入れた、影響した行の数を取り出す。入れていなければ、黙って数を決めずに例外にする。 */
    private function consumeAffectedRows(string $call): int
    {
        $rows = array_shift($this->tmswpAffectedRows);

        if ($rows === null) {
            throw new InvalidArgumentException("WpdbTestDouble cannot tell how many rows {$call} affects; call queueAffectedRows() before it");
        }

        return $rows;
    }

    /**
     * 実wpdbのflush()と同じくlast_errorとrows_affectedを空にし、failNextWrite()の予約があれば消費して
     * 失敗状態にする。
     */
    private function consumeWriteFailure(): bool
    {
        $this->last_error = '';
        $this->rows_affected = 0;

        if ($this->tmswpNextWriteError === null) {
            return false;
        }

        $this->last_error = $this->tmswpNextWriteError;
        $this->tmswpNextWriteError = null;

        return true;
    }

    /**
     * 実wpdbのflush()と同じくlast_errorとrows_affectedを空にし、failNextRead()の予約があれば消費して
     * 失敗状態にする。
     */
    private function consumeReadFailure(): bool
    {
        $this->last_error = '';
        $this->rows_affected = 0;

        if ($this->tmswpNextReadError === null) {
            return false;
        }

        $this->last_error = $this->tmswpNextReadError;
        $this->tmswpNextReadError = null;

        return true;
    }

    /**
     * @param array<mixed> $array
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $array): array
    {
        $normalized = [];

        foreach ($array as $key => $value) {
            if (!\is_string($key)) {
                throw new InvalidArgumentException('array key must be string');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed>|string|null $format
     *
     * @return list<string>|string|null
     */
    private function normalizeFormat(array|string|null $format): array|string|null
    {
        if (!\is_array($format)) {
            return $format;
        }

        $normalized = [];

        foreach ($format as $item) {
            if (!\is_string($item)) {
                throw new InvalidArgumentException('format must contain strings');
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /** sprintfの番号付きの書式(`1$`)なら、その番号の引数の位置(0始まり)。それ以外は$argId。 */
    private static function argumentPosition(string $format, int $argId): int
    {
        $position = strpos($format, '$');

        return $position === false ? $argId : (int) substr($format, 0, $position) - 1;
    }

    /**
     * 実wpdbの_escape_identifier_value()と同じ結果にする。実wpdbは値をそのままstr_replace()に渡し、class-wpdb.phpは
     * strict_typesを宣言しないため、PHPの弱い型の変換が働く。文字列・int・float・Stringableは文字列に、boolは`1`か
     * 空文字になる。実wpdbがPHPの診断を出す値(配列・resource・null・Stringableでないオブジェクト)は、再現せずに
     * InvalidArgumentExceptionにする(クラスのdocblockの5)。
     */
    private static function escapeIdentifierValue(mixed $value): string
    {
        $string = match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value), $value instanceof Stringable => (string) $value,
            \is_bool($value) => $value ? '1' : '',
            default => throw new InvalidArgumentException('WpdbTestDouble does not emulate %i with ' . get_debug_type($value) . ': real wpdb raises a PHP diagnostic for this input'),
        };

        return str_replace('`', '``', $string);
    }

    /**
     * 実wpdb::_real_escape()と同じく、スカラーでない値は空文字にし、mysqli_real_escape_string()がエスケープする文字を
     * エスケープして、placeholder_escape()を当てる。
     */
    private function realEscape(mixed $value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        $escaped = strtr((string) $value, [
            '\\' => '\\\\',
            "\0" => '\\0',
            "\n" => '\\n',
            "\r" => '\\r',
            "'" => "\\'",
            '"' => '\\"',
            "\x1a" => '\\Z',
        ]);

        return $this->add_placeholder_escape($escaped);
    }
}
