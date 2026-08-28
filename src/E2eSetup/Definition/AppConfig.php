<?php

declare(strict_types=1);

namespace WebmanTech\Testing\E2eSetup\Definition;

/**
 * 单个 e2e 应用配置（rector.php 风格的类写法，经 SetupConfig::app() 注册）。
 *
 * 方法即配置键（驼峰 ↔ 定义数组下划线），可链式调用；未调用的项走默认值，
 * 与数组写法的默认值语义一致（skeleton_version 默认空、packages 默认 path=项目根、version 默认 dev-main）。
 * 校验与路径解析统一在 Definition::normalize，本类只做写法转换。
 */
final class AppConfig
{
    private string $skeleton = '';

    private ?string $skeletonVersion = null;

    private string $targetDir = '';

    private string $srcDir = '';

    /** @var array<int, array{name: string, path: ?string, vcs: ?string, version: string}> */
    private array $packages = [];

    /** @var array<string, string> */
    private array $require = [];

    /** @var array<string, string> */
    private array $requireDev = [];

    /** @var array<string, string> */
    private array $requireOverride = [];

    /** @var array<int, string> */
    private array $reinstallPackages = [];

    public static function configure(): self
    {
        return new self();
    }

    /**
     * 骨架包名（如 workerman/webman、laravel/laravel）或本地骨架目录路径（存在则复制代替 create-project）
     *
     * @param string|null $version create-project 骨架版本约束（可选，如 laravel/laravel 的 ^12.0）
     */
    public function skeleton(string $skeleton, ?string $version = null): self
    {
        $this->skeleton = $skeleton;
        $this->skeletonVersion = $version;

        return $this;
    }

    public function targetDir(string $dir): self
    {
        $this->targetDir = $dir;

        return $this;
    }

    public function srcDir(string $dir): self
    {
        $this->srcDir = $dir;

        return $this;
    }

    /**
     * 声明被测包（可多次调用累积）；path/vcs 均不传时默认 path=项目根
     *
     * @param string|null $path 本地目录（path repository），与 $vcs 互斥
     * @param string|null $vcs 远程仓库地址（vcs repository），与 $path 互斥
     */
    public function package(string $name, ?string $path = null, ?string $vcs = null, string $version = 'dev-main'): self
    {
        $this->packages[] = [
            'name' => $name,
            'path' => $path,
            'vcs' => $vcs,
            'version' => $version,
        ];

        return $this;
    }

    /**
     * @param array<string, string> $packages 包名 => 版本约束
     */
    public function require(array $packages): self
    {
        $this->require = $packages;

        return $this;
    }

    /**
     * @param array<string, string> $packages 包名 => 版本约束
     */
    public function requireDev(array $packages): self
    {
        $this->requireDev = $packages;

        return $this;
    }

    /**
     * 覆盖骨架既有依赖（如版本冲突）
     *
     * @param array<string, string> $packages 包名 => 版本约束
     */
    public function requireOverride(array $packages): self
    {
        $this->requireOverride = $packages;

        return $this;
    }

    /**
     * 需 Install 落地的包（默认空；webman 插件场景声明 webman/console 等）
     *
     * @param array<int, string> $packages
     */
    public function reinstallPackages(array $packages): self
    {
        $this->reinstallPackages = $packages;

        return $this;
    }

    /**
     * @return array<string, mixed> 应用配置数组（与 Definition::normalize 输入同构）
     */
    public function toArray(): array
    {
        $def = [
            'skeleton' => $this->skeleton,
            'target_dir' => $this->targetDir,
            'src_dir' => $this->srcDir,
            'packages' => [],
            'require' => $this->require,
            'require_dev' => $this->requireDev,
            'require_override' => $this->requireOverride,
            'reinstall_packages' => $this->reinstallPackages,
        ];
        if ($this->skeletonVersion !== null) {
            // 键序与数组写法的 skeleton_version 位置一致（紧跟 skeleton）
            $def = ['skeleton' => $def['skeleton'], 'skeleton_version' => $this->skeletonVersion] + $def;
        }
        foreach ($this->packages as $package) {
            $item = ['name' => $package['name']];
            if ($package['path'] !== null) {
                $item['path'] = $package['path'];
            }
            if ($package['vcs'] !== null) {
                $item['vcs'] = $package['vcs'];
            }
            if ($package['version'] !== 'dev-main') {
                $item['version'] = $package['version'];
            }
            $def['packages'][] = $item;
        }

        return $def;
    }
}
