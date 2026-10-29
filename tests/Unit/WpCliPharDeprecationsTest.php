<?php

declare(strict_types=1);

namespace TMSWP\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpCliPharDeprecations;

/**
 * WpCliPharDeprecationsが、WP-CLIのphar自身の非推奨の行だけを取り除き、ほかの行を変えないこと。
 */
final class WpCliPharDeprecationsTest extends UnitTestCase
{
    /** CIのPHP 8.5の統合テストで、WP-CLI 2.12.0が標準エラーに書いた非推奨の2行(ログの形と表示の形) */
    private const string LOGGED = '[29-Oct-2026 01:46:02 UTC] PHP Deprecated:  Using null as an array offset is deprecated, use an empty string instead in phar:///usr/local/bin/wp/vendor/wp-cli/php-cli-tools/lib/cli/Colors.php on line 95';

    private const string DISPLAYED = 'Deprecated: Using null as an array offset is deprecated, use an empty string instead in phar:///usr/local/bin/wp/vendor/wp-cli/php-cli-tools/lib/cli/Colors.php on line 95';

    /** CIの標準エラーの非推奨の2行を取り除き、続くWP-CLIのエラーの行はそのまま残す。取り除いた行の数を返す。 */
    public function testRemovesTheDeprecationsOfWpCliPharAndKeepsTheRest(): void
    {
        $error = "Error: --force does not take a value\n";

        self::assertSame(['output' => $error, 'removed' => 2], WpCliPharDeprecations::strip(self::LOGGED . "\n" . self::DISPLAYED . "\n" . $error));
        self::assertSame(['output' => "line 1\nline 2", 'removed' => 1], WpCliPharDeprecations::strip("line 1\n" . self::DISPLAYED . "\nline 2"));
    }

    /** 非推奨の行が無ければ、出力を1文字も変えない(空の出力・改行で終わらない出力・CRLFを含む)。 */
    #[DataProvider('outputsWithoutPharDeprecations')]
    public function testLeavesOutputWithoutPharDeprecationsAsItIs(string $output): void
    {
        self::assertSame(['output' => $output, 'removed' => 0], WpCliPharDeprecations::strip($output));
    }

    /** @return iterable<string, array{string}> */
    public static function outputsWithoutPharDeprecations(): iterable
    {
        yield 'empty' => [''];
        yield 'error' => ["Error: --force does not take a value\n"];
        yield 'no trailing newline' => ["Success: done\nfiles: 2"];
        yield 'crlf and blank lines' => ["a\r\n\r\nb\n\n"];
    }

    /**
     * 取り除くのは、非推奨の行で、かつ元のファイルがWP-CLIのpharの中のものだけ。FWやテストのファイルの非推奨、
     * pharの中の非推奨でない通知(Warning等)、非推奨の文言を含むだけの行は残す。
     */
    #[DataProvider('linesToKeep')]
    public function testKeepsLinesThatAreNotDeprecationsOfWpCliPhar(string $line): void
    {
        self::assertFalse(WpCliPharDeprecations::isPharDeprecation($line));
        self::assertSame(['output' => $line . "\n", 'removed' => 0], WpCliPharDeprecations::strip($line . "\n"));
    }

    /** @return iterable<string, array{string}> */
    public static function linesToKeep(): iterable
    {
        yield 'deprecation of a framework file (log)' => ['[29-Oct-2026 01:46:02 UTC] PHP Deprecated:  Something is deprecated in /var/www/html/wp-content/plugins/tmswp/packages/core/src/Kernel/Kernel.php on line 10'];
        yield 'deprecation of a framework file (display)' => ['Deprecated: Something is deprecated in /var/www/html/wp-content/plugins/tmswp/packages/core/src/Kernel/Kernel.php on line 10'];
        yield 'deprecation of another phar' => ['Deprecated: Something is deprecated in phar:///usr/local/bin/other/lib/file.php on line 1'];
        yield 'warning in the phar' => ['Warning: Something happened in phar:///usr/local/bin/wp/vendor/wp-cli/php-cli-tools/lib/cli/Colors.php on line 95'];
        yield 'notice in the phar (log)' => ['[29-Oct-2026 01:46:02 UTC] PHP Notice:  Something happened in phar:///usr/local/bin/wp/php/WP_CLI/Runner.php on line 1'];
        yield 'error line mentioning a deprecation' => ['Error: Deprecated: in phar:///usr/local/bin/wp/x.php on line 1 was reported'];
        yield 'indented deprecation' => [' ' . self::DISPLAYED];
    }

    /** ログの形(日時の有無)と表示の形の、どれもWP-CLIのpharの非推奨と判定する。 */
    public function testRecognizesBothFormsOfTheDeprecationOfWpCliPhar(): void
    {
        self::assertTrue(WpCliPharDeprecations::isPharDeprecation(self::LOGGED));
        self::assertTrue(WpCliPharDeprecations::isPharDeprecation(self::DISPLAYED));
        self::assertTrue(WpCliPharDeprecations::isPharDeprecation('PHP Deprecated:  Using null as an array offset is deprecated, use an empty string instead in phar:///usr/local/bin/wp/vendor/wp-cli/php-cli-tools/lib/cli/Colors.php on line 95'));
    }
}
