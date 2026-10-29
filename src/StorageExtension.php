<?php

declare(strict_types=1);

namespace TMSWP\Testing;

use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Option\OptionStorageInterface;

/**
 * Kernel の既定のメタ・オプションのストレージを、渡した実装に差し替える拡張。boot の中の register() で Container の
 * MetaStorageInterface・OptionStorageInterface の id に登録する(rules/meta.md・options.md の「テスト」の拡張を1つにしたもの)。
 */
final class StorageExtension implements ExtensionInterface
{
    public function __construct(
        private readonly MetaStorageInterface $meta,
        private readonly OptionStorageInterface $options,
    ) {}

    public function register(Kernel $kernel): void
    {
        $kernel->container()->set(MetaStorageInterface::class, $this->meta);
        $kernel->container()->set(OptionStorageInterface::class, $this->options);
    }
}
