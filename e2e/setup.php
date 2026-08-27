<?php
/**
 * E2E 测试应用安装命令
 *
 * 生成物（e2e/webman）是被 git 忽略的可抛弃目录：官方骨架升级时删除对应目录后
 * 重新执行本命令即可（始终 composer create-project 最新版 webman，保证完整性测试基于最新骨架）。
 *
 * 用法：
 *   php e2e/setup.php webman            # 完整安装 webman e2e 应用（删除重建）
 *   php e2e/setup.php webman --sync     # 仅同步自有代码（dev 快速迭代）
 *   php e2e/setup.php webman --vcs      # testing 组件经 GitHub VCS dev-main 安装（默认 path 引用当前仓库代码）
 *
 * 完整安装流程（顺序关键）：
 *   1. composer create-project 官方骨架（workerman/webman 最新版）
 *   2. patch composer.json（testing 组件 repository + 测试依赖）
 *   3. composer update（安装依赖）
 *   4. composer reinstall webman/console（触发 Install 落地 webman CLI 入口）
 *   5. copy webman-src 自有代码到应用目录（覆盖式，保证自有 config 覆盖在骨架默认配置之后生效）
 */

declare(strict_types=1);

const ROOT_DIR = __DIR__ . '/..';

// testing 组件（被测对象）引用方式：
// - 默认 path repository 指向当前仓库（本地/CI 直接验证当前代码，改动即时生效）
// - --vcs 切换为 GitHub VCS dev-main（验证真实发布链路，需先推送 main 才能拉到最新代码）
const TESTING_PACKAGE = 'webman-tech/testing';
const TESTING_VCS_URL = 'https://github.com/webman-tech/testing.git';

function app_definition(): array
{
    return [
        'skeleton' => 'workerman/webman',
        'target_dir' => ROOT_DIR . '/e2e/webman',
        'src_dir' => ROOT_DIR . '/e2e/webman-src',
        'require' => [
            // crontab 副作用演示（webman-src/app/process/Crontab.php）
            'workerman/crontab' => '^1.0',
        ],
        'require_dev' => [
            'pestphp/pest' => '^3.8',
            // webman CLI 入口（webmanCommand() 依赖 `webman` 可执行文件）
            'webman/console' => '^2.0',
            // 被测组件本身（path 指向当前仓库 / --vcs 拉取 GitHub main）
            TESTING_PACKAGE => 'dev-main',
            // 组件的 PSR-18 HTTP 客户端（自动发现；组件不强制依赖 guzzle）
            'guzzlehttp/guzzle' => '^7.8',
        ],
    ];
}

function main(array $argv): int
{
    $def = app_definition();
    $syncOnly = in_array('--sync', $argv, true);
    $useVcs = in_array('--vcs', $argv, true);
    $names = array_values(array_filter(array_slice($argv, 1), fn($arg) => !str_starts_with($arg, '--')));

    if ($names !== ['webman']) {
        fwrite(STDERR, "用法: php e2e/setup.php webman [--sync] [--vcs]\n");
        return 1;
    }

    if ($syncOnly) {
        sync_src($def);
        echo "==> 同步完成。运行测试: cd e2e/webman && vendor/bin/pest\n";
        return 0;
    }

    install_app($def, $useVcs);

    return 0;
}

function install_app(array $def, bool $useVcs): void
{
    $target = $def['target_dir'];
    $src = $def['src_dir'];

    echo "==> [1/5] 生成官方骨架 {$def['skeleton']} -> {$target}\n";
    if (is_dir($target)) {
        run_cmd(['rm', '-rf', $target]);
    }
    run_cmd(['composer', 'create-project', $def['skeleton'], $target, '--no-interaction', '--no-progress']);

    echo "==> [2/5] patch composer.json\n";
    patch_composer_json($def, $useVcs);

    echo "==> [3/5] composer update（安装依赖）\n";
    run_cmd(['composer', 'update', '--no-interaction', '--no-progress'], $target, [
        // 同一 git 仓库时 path repository 自动 carry-over 该版本，CI detached HEAD 兜底
        'COMPOSER_ROOT_VERSION' => 'dev-main',
    ]);

    // 批量 composer update 时 composer 进程内 autoloader 未就绪，包内 Install.php 不触发；
    // 单包 reinstall 会刷新 autoloader，走 post-package-install -> support\Plugin::install 真实安装链，
    // 落地 `webman` CLI 入口与 config/plugin/webman/console（webmanCommand() 依赖）
    echo "==> [4/5] composer reinstall webman/console（触发 Install 落地 webman CLI 入口）\n";
    run_cmd(['composer', 'reinstall', '--no-interaction', '--no-progress', 'webman/console'], $target, [
        'COMPOSER_ROOT_VERSION' => 'dev-main',
    ]);

    echo "==> [5/5] 同步自有代码 {$src} -> {$target}\n";
    sync_src($def);

    echo "==> 完成。运行测试: cd {$target} && vendor/bin/pest\n";
}

/**
 * 仅同步自有代码（dev 快速迭代）
 * 注意：必须在 composer update 之后执行，否则自有 config 覆盖会缺前置的插件默认配置
 */
function sync_src(array $def): void
{
    $src = rtrim($def['src_dir'], '/');
    $target = $def['target_dir'];
    if (!is_dir($src)) {
        fwrite(STDERR, "源目录不存在: {$src}\n");
        exit(1);
    }
    if (!is_dir($target)) {
        fwrite(STDERR, "目标目录不存在: {$target}（先完整安装: php e2e/setup.php webman）\n");
        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $targetPath = $target . str_replace($src, '', (string)$item);
        if ($item->isDir()) {
            if (!is_dir($targetPath)) {
                mkdir($targetPath, 0755, true);
            }
        } else {
            if (!is_dir(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0755, true);
            }
            copy((string)$item, $targetPath);
            echo "  sync {$targetPath}\n";
        }
    }
}

/**
 * patch 应用的 composer.json：
 * - 追加 testing 组件 repository（默认 path 指向当前仓库，--vcs 切 GitHub VCS）
 * - 追加测试依赖（保留官方骨架全部 scripts 等原有内容）
 */
function patch_composer_json(array $def, bool $useVcs): void
{
    $file = $def['target_dir'] . '/composer.json';
    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException("无法读取 {$file}");
    }
    $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

    if ($useVcs) {
        $json['repositories'][] = [
            'type' => 'vcs',
            'url' => TESTING_VCS_URL,
        ];
    } else {
        $json['repositories'][] = [
            'type' => 'path',
            'url' => ROOT_DIR,
            'options' => [
                'symlink' => true,
                'versions' => [TESTING_PACKAGE => 'dev-main'],
            ],
        ];
    }
    foreach ($def['require'] as $package => $version) {
        $json['require'][$package] = $version;
    }
    foreach ($def['require_dev'] as $package => $version) {
        $json['require-dev'][$package] = $version;
    }
    // tests 命名空间（Pest.php 的 pest()->extend(Tests\TestCase::class) 自动加载依赖）
    $json['autoload-dev']['psr-4']['Tests\\'] = 'tests/';
    $json['config'] = array_merge($json['config'] ?? [], [
        'allow-plugins' => array_merge($json['config']['allow-plugins'] ?? [], [
            'pestphp/pest-plugin' => true,
        ]),
    ]);
    // 骨架自带 minimum-stability: dev + prefer-stable: true；显式设置保证 dev-main 可解析
    $json['minimum-stability'] = 'dev';
    $json['prefer-stable'] = true;

    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * 执行命令并透传输出，失败时中止
 */
function run_cmd(array $command, ?string $cwd = null, array $env = []): void
{
    $commandLine = implode(' ', array_map('escapeshellarg', $command));
    $prefix = '';
    if ($cwd !== null) {
        // cd 必须由 shell 内建执行（外部 cd 无法改变父 shell 的 cwd）
        $prefix = 'cd ' . escapeshellarg($cwd) . ' && ';
    }
    if ($env !== []) {
        // 环境变量通过 env 命令传递（key=value 整体转义后 shell 会将其当作命令）
        $prefix .= 'env ';
        foreach ($env as $key => $value) {
            $prefix .= escapeshellarg("{$key}={$value}") . ' ';
        }
    }

    passthru($prefix . $commandLine, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "\n命令执行失败(exit {$exitCode}): {$commandLine}\n");
        exit($exitCode);
    }
}

exit(main($argv));
