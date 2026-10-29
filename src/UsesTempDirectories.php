<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use FilesystemIterator;
use Patchwork\CodeManipulation\Stream as PatchworkStream;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * テストごとの一時ディレクトリを作り、テストの後に片付ける。
 *
 * 一時ディレクトリは、テストごとに`sys_get_temp_dir()`の下に作る専用の親ディレクトリの中に置く。
 * `KernelConfig`がappDirの親(`dirname(appDir)`)に書く探索結果ファイル(`tmswp-discovery.php`)も
 * その親ディレクトリに収まるため、同時に走る別のテストの実行(PHPの別の版での並列の実行など)と干渉せず、
 * 片付けで親ディレクトリごと消える。
 */
trait UsesTempDirectories
{
    /** このテストの一時ディレクトリをまとめる親ディレクトリ。最初のcreateTempDirectory()で作る。 */
    private ?string $tempParentDirectory = null;

    /**
     * このテスト専用の親ディレクトリの下に、空の一時ディレクトリを作って返す。
     *
     * @param string $prefix ディレクトリ名の先頭に付ける文字列(何のためのディレクトリかを示す)
     */
    protected function createTempDirectory(string $prefix): string
    {
        $this->tempParentDirectory ??= $this->makeDirectory(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmswp-test-' . uniqid('', true));

        return $this->makeDirectory($this->tempParentDirectory . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid('', true));
    }

    /**このテストで作った一時ディレクトリを、探索結果ファイル等の中身を含めて親ディレクトリごと消す。*/
    protected function cleanupTempDirectories(): void
    {
        if ($this->tempParentDirectory !== null && is_dir($this->tempParentDirectory)) {
            $this->deleteDirectory($this->tempParentDirectory);
        }

        $this->tempParentDirectory = null;
    }

    private function makeDirectory(string $dir): string
    {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("一時ディレクトリを作成できません: {$dir}");
        }

        return $dir;
    }

    /**
     * ディレクトリを中身ごと消す。Patchwork(Brain Monkeyが読み込む)のstream wrapperが有効な間は、
     * それを外して消す。
     *
     * Patchworkのstream wrapperは、ファイル操作のたびに`stream_wrapper_restore()`でwrapperを外し、
     * `stream_wrapper_register()`で付け直す。`stream_wrapper_restore()`は外したwrapperの登録
     * (stream factoryのresource)を解放しないため、1回の操作ごとに約250バイトがプロセスの終わりまで残る
     * (antecedent/patchwork 2.2.4まで。上流のPR #225)。多くのファイルを消すとこれが積み上がるため、
     * 解放する`stream_wrapper_unregister()`で外してから標準のwrapperに戻し、消した後にPatchworkの
     * wrapperを付け直す。消す間はPHPのファイルを読み込まないため、Patchworkのコードの書き換えは要らない。
     */
    private function deleteDirectory(string $dir): void
    {
        if (!class_exists(PatchworkStream::class, false)) {
            $this->deleteTree($dir);

            return;
        }

        try {
            if (!stream_wrapper_unregister('file') || !stream_wrapper_restore('file')) {
                self::fail('Patchworkのstream wrapperを外せません');
            }

            $this->deleteTree($dir);
        } finally {
            PatchworkStream::wrap();
        }
    }

    private function deleteTree(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($dir);
    }
}
