<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Composer\Autoload\ClassLoader;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ユニット層の共通基底。Brain MonkeyとMockeryの検証を全テストに保証する。
 *
 * FWのソースは翻訳関数(`__()`等)を常に呼ぶため、翻訳関数はソース文字列をそのまま返すスタブにする。
 */
abstract class UnitTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;
    use UsesTempDirectories;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\stubTranslationFunctions();
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * テストを走らせているComposerの`vendor/autoload.php`の絶対パスを返す。
     *
     * 別のPHPのプロセスでテストのコードを動かすときに渡す。monorepoではルートのvendor/、パッケージを単独で
     * 取り出したときはそのパッケージのvendor/を指す。
     */
    protected static function composerAutoloadFile(): string
    {
        $classLoaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();
        self::assertIsString($classLoaderFile);

        return \dirname($classLoaderFile, 2) . '/autoload.php';
    }
}
