<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Config;

use InvalidArgumentException;

/**
 * Testing 配置（配置传导：显式传参 > 配置文件 > 默认值）
 *
 * - 自动读取被测应用下的配置文件 `config/testing.php`（键名与 fromConfig 一致；
 *   被测应用侧可同时用 webman 的 config('testing') 读取同一文件，保证两进程配置同源）
 * - 本类只做配置传导与必要校验，不做环境变量等旁路配置源
 */
final class TestingConfig
{
    /**
     * 端口未配置时的默认值（避开 webman 官方默认 8787，防止与常规项目冲突；与被测应用侧约定一致）
     */
    private const FALLBACK_PORT = 18787;

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
        public readonly string $host,
        public readonly int    $port,
        public readonly string $phpBinary,
        public readonly string $entryFile,
        public readonly array  $serverEnv,
        public readonly string $stdoutReady,
        public readonly float  $startTimeout,
        public readonly int    $processTimeout,
        public readonly float  $stopTimeout,
        public readonly string $command,
        array   $httpClient,
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
        $fileConfig = self::readWebmanConfigFile($appDir);

        return new self(
            appDir: $appDir,
            host: $config['host'] ?? $fileConfig['host'] ?? '127.0.0.1',
            port: $config['port'] ?? $fileConfig['port'] ?? self::FALLBACK_PORT,
            phpBinary: $config['phpBinary'] ?? $fileConfig['phpBinary'] ?? PHP_BINARY,
            entryFile: $config['entryFile'] ?? $fileConfig['entryFile'] ?? 'start.php',
            serverEnv: $config['serverEnv'] ?? $fileConfig['serverEnv'] ?? [],
            stdoutReady: $config['stdoutReady'] ?? $fileConfig['stdoutReady'] ?? 'Start success',
            startTimeout: $config['startTimeout'] ?? $fileConfig['startTimeout'] ?? 30.0,
            processTimeout: $config['processTimeout'] ?? $fileConfig['processTimeout'] ?? 600,
            stopTimeout: $config['stopTimeout'] ?? $fileConfig['stopTimeout'] ?? 10.0,
            command: $config['command'] ?? $fileConfig['command'] ?? 'webman',
            httpClient: $config['httpClient'] ?? $fileConfig['httpClient'] ?? [],
        );
    }

    /**
     * 读取被测应用的配置文件（config/testing.php，被测应用侧可用 webman 的 config('testing') 读取同一文件）：
     * 测试进程已加载 webman 框架（config() 可用）时优先用 config()（完整 webman 语义），
     * 否则直接 require 配置文件（测试进程通常未加载 webman 框架，配置需自包含）
     */
    private static function readWebmanConfigFile(string $appDir): array
    {
        if (function_exists('config')) {
            $config = config('testing');
            if (is_array($config)) {
                return $config;
            }
        }

        $file = rtrim($appDir, '/') . '/config/testing.php';
        if (!is_file($file)) {
            return [];
        }
        try {
            $config = require $file;
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(sprintf(
                '读取 testing 配置文件失败: %s（配置文件在测试进程内直接执行，请避免 base_path() 等 webman 专属函数，可用 getcwd() 代替）: %s',
                $file,
                $e->getMessage(),
            ), 0, $e);
        }
        if (!is_array($config)) {
            throw new InvalidArgumentException("testing 配置文件需返回数组: {$file}");
        }

        return $config;
    }
}
