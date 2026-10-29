<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use Closure;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Kernel\KernelContext;
use WP_Hook;

/**
 * 統合テストで、一時ディレクトリのapp/を持つKernelを、実際のテーマ・プラグインと同じくinitより前にbootし、
 * WordPressのinit(do_action('init'))で登録を進めるための基盤。
 *
 * 統合テストはWordPressの読み込み(initの発火)の後に動くため、bootの間だけ$wp_actionsを、テーマなら
 * functions.phpの読み込み時(after_setup_themeの前)、プラグインなら本体ファイルの読み込み時(plugins_loadedの前)の
 * 状態に戻す。initは、bootがinitに登録したコールバックだけを持つinitで発火する(fixtureのテーマ・プラグインや
 * WordPress自身のinitのコールバックを二度実行しないため)。
 *
 * initの後のboot(本番の遅延bootのキャッチアップ・debugの遅延bootの例外)は縮退経路であり、それを確かめる
 * テストだけがKernel::boot()を直接呼ぶ。
 */
trait BootsKernelBeforeInit
{
    /** @var list<Closure(string): void> */
    private array $appAutoloaders = [];

    /**
     * initより前の時点の状態でbootし、bootがinitに登録したコールバックを、WordPressのinitと同じ順
     * (priorityの昇順、同じpriorityの中は登録の順)で返す。書き換えた$wp_actionsはbootの後に戻す。
     *
     * @return list<array{hook: string, priority: int, function: mixed, acceptedArgs: int}>
     */
    private function bootBeforeInit(KernelConfig $config): array
    {
        $before = self::registeredCallbacks();
        $actions = $GLOBALS['wp_actions'];
        self::assertIsArray($actions);
        $firstUnfired = $config->context === KernelContext::Theme ? 'after_setup_theme' : 'plugins_loaded';
        $position = array_search($firstUnfired, array_keys($actions), true);
        self::assertIsInt($position);
        $GLOBALS['wp_actions'] = \array_slice($actions, 0, $position, true);

        try {
            Kernel::boot($config);
        } finally {
            $GLOBALS['wp_actions'] = $actions;
        }

        $callbacks = array_values(array_filter(
            array_diff_key(self::registeredCallbacks(), $before),
            static fn(array $callback): bool => $callback['hook'] === 'init',
        ));
        usort($callbacks, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $callbacks;
    }

    /**
     * 渡したコールバックだけを登録したinitを、do_action('init')で発火する。コールバックの中でinitに足された
     * コールバック(Kernel::defer()の予約等)も、WordPressと同じくそのinitの中で実行される。発火の後は元のinitに戻す。
     *
     * @param list<array{hook: string, priority: int, function: mixed, acceptedArgs: int}> $callbacks
     */
    private static function fireInit(array $callbacks): void
    {
        $filters = $GLOBALS['wp_filter'];
        self::assertIsArray($filters);
        $original = $filters['init'] ?? null;
        $init = new WP_Hook();

        foreach ($callbacks as $callback) {
            self::assertIsCallable($callback['function']);
            $init->add_filter('init', $callback['function'], $callback['priority'], $callback['acceptedArgs']);
        }

        $filters['init'] = $init;
        $GLOBALS['wp_filter'] = $filters;

        try {
            do_action('init');
        } finally {
            // initの中でほかのフックに足されたコールバックは残し、initだけを元に戻す
            $after = $GLOBALS['wp_filter'];
            self::assertIsArray($after);

            if ($original instanceof WP_Hook) {
                $after['init'] = $original;
            } else {
                unset($after['init']);
            }

            $GLOBALS['wp_filter'] = $after;
        }
    }

    /**
     * 登録済みのフックのコールバックを、フック・priority・WordPressのIDで一意なキーにして返す。
     *
     * @return array<string, array{hook: string, priority: int, function: mixed, acceptedArgs: int}>
     */
    private static function registeredCallbacks(): array
    {
        $filters = $GLOBALS['wp_filter'];
        self::assertIsArray($filters);
        $callbacks = [];

        foreach ($filters as $hook => $wpHook) {
            self::assertInstanceOf(WP_Hook::class, $wpHook);

            foreach ($wpHook->callbacks as $priority => $entries) {
                self::assertIsArray($entries);

                foreach ($entries as $id => $entry) {
                    // WordPressのIDは_wp_filter_build_unique_id()が作る文字列
                    self::assertIsString($id);
                    self::assertIsArray($entry);
                    $acceptedArgs = $entry['accepted_args'] ?? null;
                    self::assertIsInt($acceptedArgs);
                    $callbacks["{$hook}\n{$priority}\n{$id}"] = [
                        'hook' => (string) $hook,
                        'priority' => (int) $priority,
                        'function' => $entry['function'] ?? null,
                        'acceptedArgs' => $acceptedArgs,
                    ];
                }
            }
        }

        return $callbacks;
    }

    /** app/のクラスを、名前空間に対応するパスから読み込むautoloadを登録する(unregisterAppAutoloaders()で外す)。 */
    private function registerAppAutoloader(KernelConfig $config): void
    {
        $namespace = $config->appNamespace;
        $this->appAutoloaders[] = $autoloader = static function (string $class) use ($namespace, $config): void {
            if (str_starts_with($class, $namespace . '\\')) {
                require $config->appDir . '/' . str_replace('\\', '/', substr($class, \strlen($namespace) + 1)) . '.php';
            }
        };
        spl_autoload_register($autoloader);
    }

    /** registerAppAutoloader()で登録したautoloadを外す(tear_downで呼ぶ)。 */
    private function unregisterAppAutoloaders(): void
    {
        foreach ($this->appAutoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        $this->appAutoloaders = [];
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("ファイルを書き込めません: {$path}");
        }
    }
}
