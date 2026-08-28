<?php

declare(strict_types=1);

namespace WebmanTech\Testing\E2eSetup;

use WebmanTech\Testing\E2eSetup\Definition\AppConfig;

/**
 * e2e 应用定义配置（rector.php 风格的类写法，定义文件 e2e/e2e-setup.php 返回本类实例）。
 *
 * 用法：
 *   return SetupConfig::configure()
 *       ->app('webman', AppConfig::configure()
 *           ->skeleton('workerman/webman')
 *           ->targetDir('webman')
 *           ->srcDir('app-src')
 *           ->package('vendor/your-package')
 *           ...
 *       );
 *
 * 只做「友好写法 → 定义数组」的转换（toArray 产出与旧数组写法同构），
 * 校验与路径解析仍统一在 Definition::normalize（单一校验源）。
 */
final class SetupConfig
{
    /** @var array<string, AppConfig> */
    private array $apps = [];

    public static function configure(): self
    {
        return new self();
    }

    public function app(string $name, AppConfig $app): self
    {
        $this->apps[$name] = $app;

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>> 应用名 => 应用配置数组（与 Definition::normalize 输入同构）
     */
    public function toArray(): array
    {
        $definitions = [];
        foreach ($this->apps as $name => $app) {
            $definitions[$name] = $app->toArray();
        }

        return $definitions;
    }
}
