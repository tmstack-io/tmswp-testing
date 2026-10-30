# tmswp/testing

TMStack WordPress Framework(TMSWP)のパッケージと、TMSWPを使うテーマ・プラグインのテストで使う支援コードです。開発用の依存として入れます。WordPressを起動しないユニットテスト(PHPUnit 11とBrain Monkey)の基底クラスと、メタ・オプションの保存先をメモリに差し替える実装を提供します。

## 動作環境

- PHP 8.3〜8.5
- tmswp/core `^0.10`(composer.jsonの`require`)
- PHPUnit `^11.5`・Brain Monkey `^2.6`・Mockery `^1.6`・psr/log `^3.0`を依存として持ちます。

## 導入

1. `composer require --dev tmswp/testing`を実行します。`require`(本番の依存)には入れません。
2. `phpunit.xml.dist`(bootstrapは`tests/bootstrap.php`、testsuiteは`tests/Unit`)と、Composerのオートローダを読むだけの`tests/bootstrap.php`を置きます。スターターから作ったテーマ・プラグインには既にあります。
3. テストのクラスは`TMSWP\Testing\UnitTestCase`を継承します。

tmswp/testingはKernelの拡張ではないため、`tmswp-kernel.php`の`KernelConfig`には登録しません。テストの中でKernelをbootするときだけ、`StorageExtension`をそのテストの`KernelConfig`の`extensions`に渡します。

```php
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\MemoryMetaStorage;
use TMSWP\Testing\MemoryOptionStorage;
use TMSWP\Testing\StorageExtension;
use TMSWP\Testing\UnitTestCase;

final class NewsletterOptionTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        Kernel::boot(KernelConfig::theme(
            \dirname(__DIR__, 3) . '/app',
            'App',
            debug: true,
            extensions: [new StorageExtension(new MemoryMetaStorage(), new MemoryOptionStorage())],
        ));
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        parent::tearDown();
    }
}
```

完全な例(`phpunit.xml.dist`・`tests/bootstrap.php`・Brain Monkeyで関数を差し替えるテスト・オプショングループのテスト)は、レシピ`vendor/tmswp/testing/rules/testing.md`にあります。

## 提供するもの

| 名前 | 役割 |
|---|---|
| `UnitTestCase` | ユニットテストの基底。Brain MonkeyとMockeryの検証を全テストに保証し、翻訳関数をソースの文字列を返すスタブにする。`createTempDirectory()`と、別のPHPのプロセスに渡す`composerAutoloadFile()`を持つ |
| `UsesTempDirectories` | テストごとの一時ディレクトリを、テストごとに専用の親ディレクトリの下に作り、テストの後に親ディレクトリごと消す |
| `MemoryMetaStorage`・`MemoryOptionStorage`・`StorageExtension` | メタ・オプションの保存先をメモリに差し替える |
| `RecordingLogger` | 書かれたログを記録するPSR-3のロガー |
| `WpdbTestDouble` | ユニット層用の`wpdb`の代役。実行したSQLを記録し、読み取りの結果と、書き込みが影響する行の数・作る行のIDをキューから返す。戻り値と状態(`rows_affected`・`insert_id`・`last_error`)は実際の`wpdb`と同じ意味にし、代役が対応しない形のSQLと、影響する行の数・IDを入れていない書き込みは例外にする。トランザクションの文(`START TRANSACTION`・`COMMIT`・`ROLLBACK`・`SET SESSION innodb_lock_wait_timeout`)を受け付け、実行中かどうかとロック待ちの秒数を持つ(ロールバックの効果・ロックの競合・デッドロックは再現しない)。upsert(`INSERT … ON DUPLICATE KEY UPDATE`)は`INSERT`として扱い、影響する行の数が0(変化なし)なら`insert_id`を0にする |
| `MetaStorageContract` | `MetaStorageInterface`の実装が契約を満たすかを確かめるテストの共通部分(データベースに依存しない部分) |
| `WpCliPharDeprecations` | TMSWPのmonorepoの統合テストで、本物のWP-CLIのプロセスの標準エラーから、WP-CLIのphar自身が出したPHPの非推奨の行(WP-CLI 2.12.0がPHP 8.5で出すもの)だけを取り除く |
| `RealTables` | WordPressのテストライブラリ(`WP_UnitTestCase`)の統合テストで、CREATE TABLEの一時テーブルへの書き換えを外して実際のテーブルを作り、テストの後に、`start()`で宣言したテーブル(接頭辞を除いた名前)のうち、その時点に無かったものだけを名前で消す。DB全体の差分は使わないため、ほかの接続が作ったテーブルと宣言していないテーブルは消さない。MySQLのSHOW TABLESは一時テーブルを返さないため、MySQLでMigratorを使うテストに要る。DDLは暗黙にコミットするので、DDLより前に書いた行は`onTearDown()`で消す |
| `IntegrationTestCase`・`MetaStorageContractTestCase`・`BootsKernelBeforeInit` | TMSWPのmonorepoの統合テストの基盤(`IntegrationTestCase::useRealTables()`は`RealTables`をそのテストで使う)。monorepoの`tests/bootstrap-integration.php`がfixtureのテーマ・プラグイン(`tests/fixtures/`)を有効にして起動したWordPressの上で動く。利用側のプロジェクトのテストでは使わない |

`IntegrationTestCase`・`MetaStorageContractTestCase`・`BootsKernelBeforeInit`が使うWordPressのテストライブラリ(`WP_UnitTestCase`と、それが使うPHPUnit 9.6)は、依存に含みません。

## レシピ

- ユニットテストを書く(PHPUnitの設定・bootstrap・Brain Monkey・Kernelをbootするテスト)と、MySQLでMigratorを使う統合テスト(`RealTables`): `vendor/tmswp/testing/rules/testing.md`
- オプション・メタのテスト: `vendor/tmswp/core/rules/options.md`・`vendor/tmswp/core/rules/meta.md`の「テスト」

テストの層の設計は、TMSWPのmonorepo([tmstack-io/tmswp](https://github.com/tmstack-io/tmswp))の[docs/10-quality.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/10-quality.md)の「テスト構成(PHPUnit 2 層)」の節にあります。

## 開発

このパッケージは[tmstack-io/tmswp](https://github.com/tmstack-io/tmswp)のmonorepoで開発しています。単独で取り出したときは、`composer install && composer test`でユニットテストを実行できます。

## ライセンス

GPL-2.0-or-later(`LICENSE`)
