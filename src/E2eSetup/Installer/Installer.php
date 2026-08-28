<?php

declare(strict_types=1);

namespace WebmanTech\Testing\E2eSetup\Installer;

use Closure;
use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use WebmanTech\Testing\E2eSetup\Definition\AppDefinition;
use WebmanTech\Testing\E2eSetup\Definition\Definition;
use WebmanTech\Testing\E2eSetup\SetupConfig;

/**
 * e2e 应用安装编排器（框架无关）：
 * create-project（或本地骨架复制）→ patch composer.json → composer update → reinstall → sync。
 *
 * 仅依赖 php + symfony/process + symfony/console，不 import 组件其他域（预留独立迁移）。
 *
 * 应用定义经 Definition::normalize 产出 AppDefinition DTO，这里一律命名属性访问。
 */
final class Installer
{
    /** @var array<string, AppDefinition> 规范化后的多应用定义 */
    private array $definitions;

    /** @var Closure(array<int, string>, ?string, array<string, string>): void */
    private Closure $runner;

    /**
     * @param array<mixed, mixed> $definitions 应用名 => 应用配置（见 Definition）
     * @param string $baseDir 定义文件所在目录（相对路径解析基准，须为绝对路径）
     * @param (callable(array<int, string>, ?string, array<string, string>): void)|null $runner 命令执行器（测试注入用，默认透传输出）
     */
    public function __construct(array $definitions, string $baseDir, ?callable $runner = null)
    {
        $this->definitions = Definition::normalize($definitions, $baseDir);
        $this->runner = $runner !== null ? Closure::fromCallable($runner) : function (array $command, ?string $cwd, array $env): void {
            $process = new Process($command, $cwd, array_merge(getenv() ?: [], $env));
            $process->setTimeout(null);
            $exitCode = $process->run(static function (string $type, string $buffer): void {
                fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
            });
            if ($exitCode !== 0) {
                throw new RuntimeException(
                    '命令执行失败(exit ' . $exitCode . '): ' . implode(' ', array_map('escapeshellarg', $command)),
                );
            }
        };
    }

    /**
     * 从应用定义文件装配安装器（默认 {cwd}/e2e/e2e-setup.php，返回 SetupConfig 实例或「应用名 => 应用配置」数组）
     *
     * @param (callable(array<int, string>, ?string, array<string, string>): void)|null $runner
     */
    public static function fromConfigFile(string $configFile, ?callable $runner = null): self
    {
        if (!is_file($configFile)) {
            throw new RuntimeException("定义文件不存在: {$configFile}（可先运行 e2e-setup init 生成）");
        }
        $definitions = require $configFile;
        // rector.php 风格的类配置（SetupConfig）与旧数组写法兼容，统一转数组后走同一校验
        if ($definitions instanceof SetupConfig) {
            $definitions = $definitions->toArray();
        }
        if (!is_array($definitions)) {
            throw new RuntimeException("定义文件必须返回 SetupConfig 实例或数组: {$configFile}");
        }

        return new self($definitions, dirname(realpath($configFile) ?: $configFile), $runner);
    }

    /**
     * @return array<int, string>
     */
    public function appNames(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * @return AppDefinition
     */
    public function definition(string $appName): AppDefinition
    {
        if (!isset($this->definitions[$appName])) {
            throw new RuntimeException('未知应用: ' . $appName . '（可选: ' . implode('|', $this->appNames()) . '）');
        }

        return $this->definitions[$appName];
    }

    /**
     * 完整安装指定应用（删除重建）
     *
     * @param array{vcs?: bool} $options
     */
    public function install(string $appName, array $options = []): void
    {
        $def = $this->definition($appName);
        $target = $def->targetDir;
        $src = $def->srcDir;

        $this->echo("==> [1/5] 生成骨架 {$def->skeleton} -> {$target}");
        // PHP 原生递归删除（跨平台；rm -rf 等系统命令在 Windows 不可用）
        $this->removeDir($target);
        if (is_dir($def->skeleton)) {
            // 本地骨架目录：复制代替 create-project
            $this->copyDir($def->skeleton, $target);
        } else {
            // create-project 参数顺序：package <directory> <version>（version 必须在 directory 之后）
            $create = ['composer', 'create-project', $def->skeleton, $target];
            if ($def->skeletonVersion !== '') {
                $create[] = $def->skeletonVersion;
            }
            $create[] = '--no-interaction';
            $create[] = '--no-progress';
            $this->run($create);
        }

        $this->echo('==> [2/5] patch composer.json');
        $this->patchComposerJson($def, (bool)($options['vcs'] ?? false));

        $this->echo('==> [3/5] composer update（安装依赖）');
        $this->run(['composer', 'update', '--no-interaction', '--no-progress'], $target, [
            // 同一 git 仓库时 path repository 自动 carry-over 该版本，CI detached HEAD 兜底
            'COMPOSER_ROOT_VERSION' => 'dev-main',
        ]);

        // 批量 composer update 时 composer 进程内 autoloader 未就绪，包内 Install.php 不触发；
        // 单包 reinstall 会刷新 autoloader，走 post-package-install -> support\Plugin::install 真实安装链
        // （webman 插件场景：落地 `webman` CLI 入口与 config/plugin/<package>/；laravel 场景通常为空）
        $reinstallPackages = $def->reinstallPackages;
        if ($reinstallPackages !== []) {
            $this->echo('==> [4/5] composer reinstall（触发 Install 落地 CLI 入口与配置模板）');
            $this->run(
                array_merge(['composer', 'reinstall', '--no-interaction', '--no-progress'], $reinstallPackages),
                $target,
                ['COMPOSER_ROOT_VERSION' => 'dev-main'],
            );
        } else {
            $this->echo('==> [4/5] 跳过 reinstall（reinstall_packages 为空）');
        }

        $this->echo("==> [5/5] 同步自有代码 {$src} -> {$target}");
        $this->copyDir($src, $target);
        $this->echo("==> 完成。运行测试: cd {$target} && vendor/bin/pest");
    }

    /**
     * 仅同步自有代码（dev 快速迭代）
     * 注意：必须在 install 之后执行，否则自有 config 覆盖会缺前置的插件默认配置
     */
    public function sync(string $appName): void
    {
        $def = $this->definition($appName);
        $src = rtrim($def->srcDir, '/');
        $target = $def->targetDir;
        if (!is_dir($src)) {
            throw new RuntimeException("源目录不存在: {$src}");
        }
        if (!is_dir($target)) {
            throw new RuntimeException("目标目录不存在: {$target}（先完整安装: e2e-setup install {$appName}）");
        }

        $this->copyDir($src, $target);
        $this->echo("==> 同步完成。运行测试: cd {$target} && vendor/bin/pest");
    }

    /**
     * @param AppDefinition $def
     */
    private function patchComposerJson(AppDefinition $def, bool $useVcs): void
    {
        $file = $def->targetDir . '/composer.json';
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException("无法读取 {$file}");
        }
        try {
            $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("composer.json 解析失败: {$file}（" . $e->getMessage() . '）', 0, $e);
        }
        if (!is_array($json)) {
            throw new RuntimeException("composer.json 解析结果不是数组: {$file}");
        }

        // 被测包 repository：同 path 的包合并为单条 path repository（versions 映射），vcs 各一条；
        // --vcs 时未显式声明 path/vcs 的包改经 GitHub VCS 安装（url 由 name 推断）
        /** @var array<string, array<string, string>> $pathGroups */
        $pathGroups = [];
        /** @var array<string, array<string, string>> $vcsUrls */
        $vcsUrls = [];
        foreach ($def->packages as $package) {
            $version = $package->version;
            if ($package->vcs !== null || $useVcs) {
                $url = $package->vcs ?? 'https://github.com/' . $package->name . '.git';
                $vcsUrls[$url][$package->name] = $version;
                continue;
            }
            // 非 vcs 模式：Definition 保证 path 存在（未显式声明时默认项目根）
            if ($package->path === null) {
                throw new RuntimeException('包 ' . $package->name . ' 缺少 path（定义校验应保证不会发生）');
            }
            $pathGroups[$package->path][$package->name] = $version;
        }
        $repositories = is_array($json['repositories'] ?? null) ? $json['repositories'] : [];
        foreach ($pathGroups as $path => $versions) {
            $repositories[] = [
                'type' => 'path',
                'url' => $path,
                'options' => [
                    'symlink' => true,
                    'versions' => $versions,
                ],
            ];
        }
        foreach ($vcsUrls as $url => $_versions) {
            $repositories[] = [
                'type' => 'vcs',
                'url' => $url,
            ];
        }
        if ($repositories !== []) {
            $json['repositories'] = $repositories;
        }

        $require = is_array($json['require'] ?? null) ? $json['require'] : [];
        foreach ($def->require as $package => $version) {
            $require[$package] = $version;
        }
        foreach ($def->requireOverride as $package => $version) {
            $require[$package] = $version;
        }
        $json['require'] = $require;
        $requireDev = is_array($json['require-dev'] ?? null) ? $json['require-dev'] : [];
        foreach ($def->requireDev as $package => $version) {
            $requireDev[$package] = $version;
        }
        $json['require-dev'] = $requireDev;
        // tests 命名空间（Pest.php 的 pest()->extend(Tests\TestCase::class) 自动加载依赖；laravel 骨架自带，webman 骨架缺失需补齐）
        $autoloadDev = is_array($json['autoload-dev'] ?? null) ? $json['autoload-dev'] : [];
        $psr4 = is_array($autoloadDev['psr-4'] ?? null) ? $autoloadDev['psr-4'] : [];
        $psr4['Tests\\'] = 'tests/';
        $autoloadDev['psr-4'] = $psr4;
        $json['autoload-dev'] = $autoloadDev;
        $config = is_array($json['config'] ?? null) ? $json['config'] : [];
        $allowPlugins = is_array($config['allow-plugins'] ?? null) ? $config['allow-plugins'] : [];
        $allowPlugins['pestphp/pest-plugin'] = true;
        $config['allow-plugins'] = $allowPlugins;
        $json['config'] = $config;
        // 骨架自带 minimum-stability: dev + prefer-stable: true（webman）；laravel 骨架无则补齐，保证 dev-main 可解析
        $json['minimum-stability'] = 'dev';
        $json['prefer-stable'] = true;

        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException("composer.json 编码失败: {$file}");
        }
        file_put_contents($file, $encoded . "\n");
    }

    /**
     * 递归删除目录（PHP 原生实现，跨平台；不用 rm -rf 等系统命令）
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                // 符号链接：只删链接本身（rm -rf 语义）；isDir() 会跟随链接目标，rmdir 会失败
                if (!unlink((string)$item)) {
                    throw new RuntimeException("无法删除符号链接: {$item}");
                }
            } elseif ($item->isDir()) {
                if (!rmdir((string)$item)) {
                    throw new RuntimeException("无法删除目录: {$item}");
                }
            } elseif (!unlink((string)$item)) {
                throw new RuntimeException("无法删除文件: {$item}");
            }
        }
        if (!rmdir($dir)) {
            throw new RuntimeException("无法删除目录: {$dir}");
        }
    }

    /**
     * @param array<int, string> $command
     * @param array<string, string> $env
     */
    private function run(array $command, ?string $cwd = null, array $env = []): void
    {
        ($this->runner)($command, $cwd, $env);
    }

    private function echo(string $message): void
    {
        echo $message . "\n";
    }

    /**
     * 递归复制目录（覆盖式），打印每个复制文件
     */
    private function copyDir(string $src, string $target): void
    {
        $src = rtrim($src, '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $targetPath = $target . '/' . substr((string)$item, strlen($src) + 1);
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                if (!is_dir(dirname($targetPath))) {
                    mkdir(dirname($targetPath), 0755, true);
                }
                copy((string)$item, $targetPath);
                $this->echo("  copy {$targetPath}");
            }
        }
    }
}
