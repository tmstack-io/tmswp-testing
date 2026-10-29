<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use TMSWP\Core\Exception\RuntimeException;

/**
 * 本物のWP-CLIのプロセスの出力から、WP-CLIのphar自身のコードが出したPHPの非推奨の行だけを取り除く(統合テストの支援)。
 *
 * WP-CLI 2.12.0に同梱のphp-cli-tools(`lib/cli/Colors.php`の95行)は、PHP 8.5で、色付きの出力(`WP_CLI::error()`等)の
 * たびに「Using null as an array offset is deprecated」の非推奨を出す。PHPは同じ通知を、ログの形(`[日時] PHP Deprecated: …`)と
 * 表示の形(`Deprecated: …`)の2行で標準エラーに書くため、WP-CLIの出力を完全一致で照合するテストが、PHP 8.5でだけ外れる。
 * このクラスは、その対処として、非推奨の行のうち、通知の元のファイルのパスが`phar:///usr/local/bin/wp/`(wp-envのcliの
 * WP-CLIのphar)で始まるものだけを取り除く。FWやテストのコードが出した非推奨と、そのほかの行は1文字も変えない。
 * WP-CLIがPHP 8.5に対応したら、このクラスと、これを使う統合テストの補助を外す。
 *
 * @internal TMSWPのmonorepoの統合テストだけが使う
 */
final class WpCliPharDeprecations
{
    /** wp-envのcliのコンテナのWP-CLIのpharの中のファイルのパスの接頭辞 */
    public const string PHAR_PREFIX = 'phar:///usr/local/bin/wp/';

    /**
     * $outputから、WP-CLIのphar自身の非推奨の行を取り除く。行の区切りと、残す行の中身は変えない。
     *
     * @return array{output: string, removed: int} 取り除いた後の出力と、取り除いた行の数
     */
    public static function strip(string $output): array
    {
        $lines = preg_split('/(?<=\n)/', $output, -1, PREG_SPLIT_NO_EMPTY);

        if ($lines === false) {
            throw new RuntimeException('cannot split the output of WP-CLI into lines');
        }

        $kept = '';
        $removed = 0;

        foreach ($lines as $line) {
            if (self::isPharDeprecation(rtrim($line, "\r\n"))) {
                ++$removed;

                continue;
            }

            $kept .= $line;
        }

        return ['output' => $kept, 'removed' => $removed];
    }

    /**
     * $line(行の終わりの改行を除いたもの)が、WP-CLIのphar自身のPHPの非推奨の行か。ログの形(先頭の`[日時] `は
     * 無くてもよい)の`PHP Deprecated:`と、表示の形の`Deprecated:`のどちらかで始まり、`in <ファイル> on line <行>`の
     * ファイルが`phar:///usr/local/bin/wp/`で始まる行だけが真になる。
     */
    public static function isPharDeprecation(string $line): bool
    {
        return preg_match('#\A(?:\[[^\]\n]*\] )?(?:PHP )?Deprecated: .* in ' . preg_quote(self::PHAR_PREFIX, '#') . '\S+ on line \d+\z#', $line) === 1;
    }
}
