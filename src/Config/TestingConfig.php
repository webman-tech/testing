<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Config;

use InvalidArgumentException;

/**
 * Testing 配置（配置传导：显式传参 > 配置文件 > 默认值）
 *
 * - 自动读取被测应用下的配置文件 `config/testing.php`（键名与 fromConfig 一致；
 *   被测应用侧可同时用 webman 的 config('testing') 读取同一文件，两进程配置同源）
 * - 监听地址（host/port）不经本类配置：组件以与 webman 相同的方式（config('process.webman.listen')）
 *   读取 webman 进程的 listen 配置，天然与应用进程一致（见 Server::baseUrl()）
 * - 本类只做配置传导与必要校验，不做环境变量等旁路配置源
 */
final class TestingConfig
{
    /**
     * guzzle 自动发现时的默认构造参数（经 httpClient 配置项覆盖；http_errors 恒为 false 不可覆盖）
     */
    private const DEFAULT_HTTP_CLIENT = [
        'timeout' => 10.0,
        'connect_timeout' => 2.0,
    ];

    public readonly array $httpClient;

    public function __construct(
        public readonly string $appDir,
        public readonly string $phpBinary,
        public readonly string $entryFile,
        public readonly array  $serverEnv,
        public readonly string $stdoutReady,
        public readonly float  $startTimeout,
        public readonly int    $processTimeout,
        public readonly float  $stopTimeout,
        public readonly string $command,
        array   $httpClient,
        public readonly array $database = [],
    ) {
        $entryFile = rtrim($appDir, '/') . '/' . $entryFile;
        if (!is_file($entryFile)) {
            throw new InvalidArgumentException(sprintf(
                'appDir(%s) 下未找到入口文件 %s；请确认传入的是 webman 应用目录，'
                . '或通过 TestingConfig::fromConfig([\'appDir\' => ...]) 显式指定',
                $appDir,
                $entryFile,
            ));
        }
        $this->httpClient = array_merge(self::DEFAULT_HTTP_CLIENT, $httpClient);
    }

    /**
     * 数组构造（命名键）
     *
     * 配置源优先级：显式传参 > 配置文件
     * （{appDir}/config/testing.php，被测应用侧可用 webman 的 config('testing') 读取同一文件）> 默认值。
     * appDir 先于配置文件定位（显式传参 > 当前目录），配置文件中的 appDir 同样生效。
     */
    public static function fromConfig(array $config): self
    {
        $appDir = $config['appDir'] ?? (string)getcwd();
        // config('testing') 依赖 webman 配置数据已加载，先确保（未引导时自动加载应用配置目录）
        self::ensureConfigLoaded($appDir);
        $fileConfig = self::readWebmanConfigFile();

        return new self(
            appDir: $appDir,
            phpBinary: $config['phpBinary'] ?? $fileConfig['phpBinary'] ?? PHP_BINARY,
            entryFile: $config['entryFile'] ?? $fileConfig['entryFile'] ?? 'start.php',
            serverEnv: $config['serverEnv'] ?? $fileConfig['serverEnv'] ?? [],
            stdoutReady: $config['stdoutReady'] ?? $fileConfig['stdoutReady'] ?? 'Start success',
            startTimeout: $config['startTimeout'] ?? $fileConfig['startTimeout'] ?? 30.0,
            processTimeout: $config['processTimeout'] ?? $fileConfig['processTimeout'] ?? 600,
            stopTimeout: $config['stopTimeout'] ?? $fileConfig['stopTimeout'] ?? 10.0,
            command: $config['command'] ?? $fileConfig['command'] ?? 'webman',
            httpClient: $config['httpClient'] ?? $fileConfig['httpClient'] ?? [],
            database: $config['database'] ?? $fileConfig['database'] ?? [],
        );
    }

    /**
     * 确保被测应用的 webman 配置数据已加载（幂等，已加载时跳过）
     *
     * 测试进程加载被测应用 vendor/autoload.php 时，webman-framework 的 composer.json
     * autoload.files 已注册 helpers.php（config() 函数可用），但配置数据存储在
     * Webman\Config 静态类中，需 Config::load 填充——未引导 webman 的测试进程里
     * config() 返回空数组。这里在读取前主动加载应用配置目录（与 webman 进程内读取
     * 同一份数据，含 process 等全部配置）；exclude 与框架 bootstrap 一致（route 除外）。
     */
    public static function ensureConfigLoaded(string $appDir): void
    {
        if (config() === []) {
            \Webman\Config::load(rtrim($appDir, '/') . '/config', ['route']);
        }
    }

    /**
     * 读取被测应用的配置文件（config/testing.php，被测应用侧可用 webman 的 config('testing')
     * 读取同一文件）：测试进程加载被测应用 vendor/autoload.php 时，webman-framework 的
     * composer.json autoload.files 已注册 helpers.php，config() 函数恒可用（配置数据由
     * ensureConfigLoaded 确保已加载）——直接走 webman 的 config()，与 webman 进程内
     * 读取同一份配置数据
     */
    private static function readWebmanConfigFile(): array
    {
        $config = config('testing');
        if ($config === null) {
            return [];
        }
        if (!is_array($config)) {
            throw new InvalidArgumentException("testing 配置（config('testing')）需为数组");
        }

        return $config;
    }
}
