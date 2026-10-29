# ユニットテストを書く(tmswp/testing)

テーマ・プラグインの`app/`のクラスを、WordPressを起動しないユニットテスト(PHPUnit 11とBrain Monkey)で確かめる。`TMSWP\Testing\UnitTestCase`を継承し、WordPressの関数はBrain Monkeyで差し替え、オプション・メタの保存先はメモリの実装に差し替える。
例の名前空間`App\`は、プロジェクトの名前空間(`tmswp-kernel.php`の`KernelConfig`の`appNamespace`)に読み替える。テストの名前空間は`App\Tests\`である。

- テストは`tests/Unit/`に置き、`app/`のディレクトリの構成をミラーする(`app/Option/NewsletterOption.php`のテストは`tests/Unit/Option/NewsletterOptionTest.php`、名前空間`App\Tests\Unit\Option`)。
- `composer test`(`phpunit`)が実行するのは、`tests/Unit/`のユニットテストだけである。wp-envの統合テストはスターターに無い。
- tmswp/testingは開発用の依存である(`require-dev`)。`app/`のコードから`TMSWP\Testing\`のクラスを使わない。

## 手順

1. `composer require --dev tmswp/testing`を実行する。PHPUnit 11・Brain Monkey・Mockery・psr/logも依存として入る。
2. `phpunit.xml.dist`と`tests/bootstrap.php`を置く(スターターから作ったプロジェクトには既にある。下の「完全な実装例」と同じ内容)。
3. `composer.json`の`autoload-dev`で、`App\Tests\`を`tests/`に割り当てる(スターターには既にある)。足したときは`composer dump-autoload`を実行する。
4. テストのクラスを`tests/Unit/<app/からの相対のディレクトリ>/<クラス名>Test.php`に作り、`UnitTestCase`を継承した`final class`にする。
5. WordPressの関数を呼ぶコードは、テストの中でBrain Monkeyの`Functions\expect()` / `Functions\when()`で関数を差し替える。
6. オプショングループ・エンティティのメタを読み書きするコードは、`setUp()`で`StorageExtension`を渡したKernelをbootし、`tearDown()`で`Kernel::reset()`する。
7. `composer test`を実行する。テストもPHPStanで検査するときは、`phpstan.neon`の`paths`に`tests`を足す。

## 完全な実装例

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnEmptyTestSuite="true"
         failOnRisky="true"
         failOnWarning="true"
         failOnDeprecation="true"
         failOnNotice="true"
         beStrictAboutOutputDuringTests="true"
         displayDetailsOnTestsThatTriggerDeprecations="true"
         displayDetailsOnTestsThatTriggerNotices="true"
         displayDetailsOnTestsThatTriggerWarnings="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

`tests/bootstrap.php`(Composerのオートローダを読むだけにする。WordPressの定数・関数を定義しない):

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
```

`composer.json`の`autoload-dev`(`App`はプロジェクトの名前空間):

```json
{
    "autoload-dev": {
        "psr-4": {
            "App\\Tests\\": "tests/"
        }
    }
}
```

テストの対象。`app/Service/UnsubscribeLink.php`(WordPressの関数を呼ぶクラス):

```php
<?php

declare(strict_types=1);

namespace App\Service;

final class UnsubscribeLink
{
    /** 配信の停止のページのURL。トークンはURLエンコードしてクエリに入れる */
    public static function url(string $token): string
    {
        return home_url('/unsubscribe/') . '?token=' . rawurlencode($token);
    }
}
```

`app/Option/NewsletterOption.php`(オプショングループ):

```php
<?php

declare(strict_types=1);

namespace App\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;

#[AsOptionGroup(prefix: 'newsletter_')]
enum NewsletterOption: string
{
    use OptionAccess;

    /** 配信を有効にするか */
    #[OptionSchema(type: MetaValueType::Boolean, default: false)]
    case Enabled = 'enabled';

    /** 差出人のメールアドレス。空ならサイトの管理者のメールアドレス */
    #[OptionSchema(type: MetaValueType::String, default: '')]
    case SenderEmail = 'sender_email';

    public static function enabled(): bool
    {
        return self::Enabled->bool();
    }

    public static function senderEmail(): string
    {
        return self::SenderEmail->string();
    }
}
```

`tests/Unit/Service/UnsubscribeLinkTest.php`(WordPressの関数をBrain Monkeyで差し替える):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\UnsubscribeLink;
use Brain\Monkey\Functions;
use TMSWP\Testing\UnitTestCase;

final class UnsubscribeLinkTest extends UnitTestCase
{
    public function testUrlAppendsTheEncodedToken(): void
    {
        Functions\expect('home_url')->once()->with('/unsubscribe/')->andReturn('https://example.test/unsubscribe/');

        self::assertSame('https://example.test/unsubscribe/?token=a%2Bb%3D', UnsubscribeLink::url('a+b='));
    }
}
```

`tests/Unit/Option/NewsletterOptionTest.php`(Kernelをbootし、オプションの保存先をメモリにする):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Option;

use App\Option\NewsletterOption;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\MemoryMetaStorage;
use TMSWP\Testing\MemoryOptionStorage;
use TMSWP\Testing\StorageExtension;
use TMSWP\Testing\UnitTestCase;

final class NewsletterOptionTest extends UnitTestCase
{
    private MemoryOptionStorage $options;

    protected function setUp(): void
    {
        parent::setUp();
        // Kernel::boot()が読むWordPressの版と発火済みのフック
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];

        $this->options = new MemoryOptionStorage();
        Kernel::boot(KernelConfig::theme(
            \dirname(__DIR__, 3) . '/app',
            'App',
            debug: true,
            extensions: [new StorageExtension(new MemoryMetaStorage(), $this->options)],
        ));
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        parent::tearDown();
    }

    public function testDefaultsAreUsedWhenNothingIsStored(): void
    {
        self::assertFalse(NewsletterOption::enabled());
        self::assertSame('', NewsletterOption::senderEmail());
    }

    public function testValuesAreStoredUnderThePrefixedOptionName(): void
    {
        self::assertTrue(NewsletterOption::SenderEmail->set('news@example.test'));

        self::assertSame('news@example.test', NewsletterOption::senderEmail());
        self::assertSame('news@example.test', $this->options->get('newsletter_sender_email'));
    }
}
```

### UnitTestCase

- `setUp()`でBrain Monkeyを始め、翻訳関数(`__()`・`esc_html__()`等)をソースの文字列を返すスタブにする。`tearDown()`でBrain MonkeyとMockeryの期待(`expect()`の回数等)を検証する。`setUp()`・`tearDown()`を上書きするときは、`parent::setUp()`を最初に、`parent::tearDown()`を最後に呼ぶ。
- WordPressの関数は、`Functions\when('関数名')->justReturn(値)`(呼ばれ方を問わない)か`Functions\expect('関数名')->once()->with(...)->andReturn(値)`(呼ばれ方も確かめる)で差し替える。差し替えていないWordPressの関数を呼ぶと、未定義の関数のエラーになる(`add_action()`・`apply_filters()`等のフックの関数は、Brain Monkeyが定義する)。
- フックの登録は`Brain\Monkey\Actions\expectAdded()`・`Brain\Monkey\Filters\expectAdded()`で確かめる。
- `$this->createTempDirectory('名前')`は、テストごとの空の一時ディレクトリを返す。テストの後に消える。

### Kernelをbootするテスト

- オプショングループ(`OptionAccess`)・エンティティの`::meta()`は、起動したKernelを要る。上の例のように、`$GLOBALS['wp_version']`・`$GLOBALS['wp_actions']`を置いてから`Kernel::boot()`し、`tearDown()`で`Kernel::reset()`とグローバルの削除をする。bootは`$wpdb`を要さない。
- `debug: true`で起動すると、`app/`をその場で探索する。探索結果ファイル(`tmswp-discovery.php`)は読まず、書き換えもしない。
- `StorageExtension`は、Kernelの既定のメタ・オプションの保存先を、渡したインスタンスに差し替える拡張である。`MemoryMetaStorage`・`MemoryOptionStorage`は値をメモリに持ち、テストからその値を直接読み書きできる(オプションは接頭辞の付いた名前で入る)。
- メモリの保存先は値をそのまま保存する。WordPressと違い、`false`は`''`に、数値は文字列にならない。`#[AsOptionGroup(storage: ...)]`・`#[UseMetaStorage(...)]`で保存先を指定したクラスは、差し替わらない。
- エンティティのメタのテストは、`MemoryMetaStorage`に`ObjectType::Post`・投稿のID・キーで値を入れ、`WP_Post`をMockeryの代役(`Mockery::mock(WP_Post::class)`に`ID`・`post_type`を入れたもの)にして`::meta($post)`で読む(vendor/tmswp/core/rules/meta.mdの「テスト」)。
- ログを確かめるときは、`RecordingLogger`をログのチャネルにする(`logChannels: static fn(): array => ['default' => new \TMSWP\Core\Log\LogChannel($logger)]`)。記録は`$logger->records`(`level`・`message`・`context`の配列のlist)にたまる。

### そのほかの支援コード

| 名前 | 使いどころ |
|---|---|
| `WpdbTestDouble` | Model(独自テーブル)のユニットテストで、`Model::useConnection(new Connection(WpdbTestDouble::create()))`として渡すwpdbの代役。`queueResultSet()`・`queueRow()`・`queueVar()`で読み取りの結果を入れ(入れなければ行が無いのと同じで、`get_results()`は空の配列を返す)、`queries()`・`insertCalls()`・`updateCalls()`・`deleteCalls()`で実行したSQLと呼び出しを確かめる。代役は行を持たないため、`update()`・`delete()`(Modelの`update()`・`delete()`)の前に影響する行の数を`queueAffectedRows()`で、`insert()`(Modelの`insert()`)の前に作る行のIDを`queueInsertId()`で入れる(入れないと`InvalidArgumentException`になる)。`insert()`は1を返し、`query()`はDDL・INSERT・UPDATE・DELETE・REPLACEだけを受け付ける(SELECT等は例外。詳しくは`WpdbTestDouble`のdocblock)。`tearDown()`で`Model::useConnection(null)`を呼ぶ。`Connection`の`select()`・`selectRow()`を通るテストは、wpdbの出力の形の定数(`ARRAY_A`等)が要るため、テストの中で`WpdbTestDouble::defineOutputConstants()`を呼び、そのテストに`#[RunInSeparateProcess]`を付ける(定数はプロセスに残るため) |
| `MetaStorageContract` | 自分で書いた`MetaStorageInterface`の実装が契約を満たすかを確かめるtrait。テストのクラスでuseし、`storage()`・`objectType()`・`createObject()`を実装する |
| `RealTables` | WordPressのテストライブラリ(`WP_UnitTestCase`)で書いた統合テストで、実際のテーブルを作り、テストの後に、宣言したテーブルのうち`start()`の時点に無かったものだけを消す。MySQLでMigratorを使うテストに使う(下の「MySQLでMigratorを使う統合テスト」) |
| `IntegrationTestCase`・`MetaStorageContractTestCase`・`BootsKernelBeforeInit` | TMSWPのmonorepoの統合テスト(fixtureのテーマ・プラグイン)の基盤。利用側のプロジェクトのテストでは使わない(`IntegrationTestCase::useRealTables()`は、monorepoの統合テストで`RealTables`を使うためのもの) |
| `WpCliPharDeprecations` | TMSWPのmonorepoの統合テストで、本物のWP-CLIのプロセスの標準エラーから、WP-CLIのphar自身が出したPHPの非推奨の行(WP-CLI 2.12.0がPHP 8.5で出すもの)だけを取り除く(`strip()`は取り除いた後の出力と行の数を返す)。利用側のプロジェクトのテストでは使わない |

### MySQLでMigratorを使う統合テスト

スターターに統合テストは無い。プロジェクトで、WordPressのテストライブラリ(`WP_UnitTestCase`。PHPUnit 9.6)の統合テストを自分で用意して、Model・Migratorを実物のDBで確かめるときの注意である。

- テストライブラリは、テストの中のCREATE TABLE・DROP TABLEを一時テーブルの文に書き換える。MySQLのSHOW TABLES・SHOW TABLE STATUSは一時テーブルを返さない(MariaDBは11.2から返す)。そのため、MySQLでは`Migrator::migrateAll()`が適用の後の照合で`MigrationException`(`table was not created: ...`)になる。MySQLは、1つの文で同じ一時テーブルを2回参照することもできない。
- テーブルを作るテストは、`set_up()`で`RealTables::start($this, $wpdb, <テストが作るテーブルの、接頭辞を除いた名前>...)`を呼んで実際のテーブルを作り、`tear_down()`で`tearDown()`を呼ぶ。`tearDown()`は、ロールバックの後に`onTearDown()`の後始末を実行し、宣言したテーブルのうち`start()`の時点に実際のテーブルとして無かったものを、名前で1つずつ消してから、COMMITする。DB全体の差分は使わないため、ほかの接続が作ったテーブルは消さない。宣言していないテーブルも消さないため、テストが作るテーブル(Modelの`table()`のテーブル名)はすべて宣言する。ROLLBACK・後始末・DROP・COMMITのどれが失敗しても残りを続け、最後に、起きた順で最初の失敗を投げる。
- DDL(CREATE TABLE・ALTER TABLE・DROP TABLE等。`migrateAll()`のdbDeltaを含む)は暗黙にコミットする。DDLより前にテストが書いた行(ユーザー・ターム・option等。Migratorが保存するハッシュのoption`tmswp_schema_hashes`を含む)は、テストの後のロールバックで戻らない。そうした行は、DDLの後に書くか、作ったIDや名前を控えて`onTearDown()`で消す。`tear_down()`で`parent::tear_down()`より前に書いた削除は、`tearDown()`のロールバックで戻るため、後始末は`onTearDown()`に登録する。

```php
use TMSWP\Testing\RealTables;

final class ContactMigrationTest extends WP_UnitTestCase
{
    private RealTables $realTables;

    public function set_up(): void
    {
        parent::set_up();
        $this->realTables = RealTables::start($this, $GLOBALS['wpdb'], 'contacts');
        $this->realTables->onTearDown(static function (): void {
            delete_option('tmswp_schema_hashes');
        });
    }

    public function tear_down(): void
    {
        try {
            $this->realTables->tearDown();
        } finally {
            parent::tear_down();
        }
    }
}
```

## してはいけないこと

- `PHPUnit\Framework\TestCase`を直接継承して、Brain Monkeyの`setUp()`・`tearDown()`を書き写さない。`UnitTestCase`を継承する。
- `tests/bootstrap.php`で、`ABSPATH`等のWordPressの定数や、WordPressの関数・クラスを定義しない。テストごとにBrain Monkeyで差し替える(wpdbの出力の形の定数は、上の`WpdbTestDouble::defineOutputConstants()`を別のプロセスのテストで呼ぶ)。
- `app/`のコードから`TMSWP\Testing\`のクラスを使わない。tmswp/testingを`require`(本番の依存)に入れない。
- Kernelをbootしたテストで、`tearDown()`の`Kernel::reset()`を省かない(次のテストのbootが、同じ名前空間のKernelの二重のbootとして失敗する)。
- `MetaBag`を直接`new`しない。テストでも`::meta($object)`で取得する。
- `get_option()`・`get_post_meta()`をBrain Monkeyで差し替えて、オプショングループ・メタのテストをしない。保存先は`StorageExtension`で差し替える。
- メモリの保存先で確かめられない動き(WordPressのoptionsテーブル・メタの保存の形、データベースのエラー)を、ユニットテストで確かめたことにしない。WordPressの上で確かめる。

## 確認方法

1. `composer test`が通り、追加したテストが実行されていること(`OK (N tests, …)`の件数が増えていること)。
2. テストの期待を1つ変えて`composer test`が失敗することを確かめ、元に戻す。
3. `composer qa`が通ること。

## 発展

- オプショングループの宣言とテスト: vendor/tmswp/core/rules/options.md
- メタの宣言とテスト: vendor/tmswp/core/rules/meta.md
- 独自テーブルのModelのテスト: vendor/tmswp/core/rules/model.md
- Brain Monkeyの関数の差し替え(`Functions\when()`・`Functions\expect()`・`Functions\stubs()`)とフックの検証: Brain Monkey(`brain/monkey`)のドキュメント
