<?php

declare(strict_types=1);

namespace WebmanTech\Testing;

use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use WebmanTech\Testing\Config\TestingConfig;
use WebmanTech\Testing\Http\HttpClientFactory;
use WebmanTech\Testing\Http\RequestFactory;
use WebmanTech\Testing\Http\TestResponse;

/**
 * 真实 webman 进程编排
 *
 * 整个测试进程共享一个 server 实例（host/port 不经组件配置：以与 webman 相同的方式
 * 读取 config('process.webman.listen')——helpers 由被测应用 composer autoload.files
 * 自动加载，配置数据读取前经 TestingConfig::ensureConfigLoaded 确保已加载，应用侧
 * 如何切换端口（如环境变量 APP_PORT 驱动）组件无需关注，天然与应用进程一致），
 * 由 shutdown function 保证停止，避免各测试文件反复启停。
 */
final class Server
{
    /**
     * workerman master 进程的 pid 文件（相对 runtime 目录）
     */
    private const PID_FILE = 'webman.pid';

    private static ?self $instance = null;

    private ?Process $process = null;

    /**
     * 监听地址（从应用 config/process.php 读取，惰性解析缓存）
     */
    private ?string $baseUrl = null;

    /**
     * 是否已引导被测应用 webman 环境（幂等：重复引导会重复注册路由）
     */
    private bool $bootstrapped = false;

    private ?ClientInterface $httpClient = null;

    private ?ClientInterface $customHttpClient = null;

    private function __construct(
        private readonly TestingConfig $config,
    ) {
    }

    /**
     * 进程级单例；首次调用携带的配置生效，后续调用忽略（避免各测试的配置互相覆盖）
     */
    public static function instance(?TestingConfig $config = null): self
    {
        if (self::$instance === null) {
            $config ??= TestingConfig::fromConfig([]);
            // 后续 config() 读取（listen 等）依赖 webman 配置数据已加载，先确保
            TestingConfig::ensureConfigLoaded($config->appDir);
            self::$instance = new self($config);
            // 无论测试进程如何退出，都尝试停掉 server 进程
            register_shutdown_function(fn() => self::$instance?->stop());
        }

        return self::$instance;
    }

    public function config(): TestingConfig
    {
        return $this->config;
    }

    public function appDir(): string
    {
        return $this->config->appDir;
    }

    /**
     * server 的 runtime 目录；$sub 为相对子路径（如 logs/webman-2026-01-01.log）
     */
    public function runtimePath(?string $sub = null): string
    {
        return rtrim($this->config->appDir, '/') . '/runtime' . ($sub !== null ? '/' . ltrim($sub, '/') : '');
    }

    public function baseUrl(): string
    {
        if ($this->baseUrl === null) {
            [$scheme, $host, $port] = self::resolveListen($this->readListen());
            $this->baseUrl = $scheme . '://' . $host . ':' . $port;
        }

        return $this->baseUrl;
    }

    /**
     * 解析 webman 进程的 listen 配置为 [scheme, host, port]
     *
     * 0.0.0.0/:: 表示监听任意地址，客户端请求应使用本机回环地址 127.0.0.1。
     *
     * @return array{0:string, 1:string, 2:int}
     */
    public static function resolveListen(string $listen): array
    {
        $parts = parse_url($listen);
        if ($parts === false || !isset($parts['host'])) {
            throw new InvalidArgumentException("无法解析 listen 地址: {$listen}");
        }
        $host = in_array($parts['host'], ['0.0.0.0', '::', '[::]'], true) ? '127.0.0.1' : $parts['host'];

        return [$parts['scheme'] ?? 'http', $host, (int)($parts['port'] ?? 80)];
    }

    /**
     * 引导被测应用 webman 环境（与 webman worker 进程内一致的组件初始化）
     *
     * 非 HTTP 测试（tests/Unit 直接使用 webman 组件）场景：测试进程默认只加载了读取监听地址
     * 所需的最小配置，容器/中间件/路由/bootstrap 类均未初始化，组件行为可能与 webman 进程内
     * 不一致。本方法以与 webman 相同的方式引导：require 被测应用 support/bootstrap.php
     * （webman worker 的 onWorkerStart 加载的同一文件，$worker 传 null——测试进程无 Worker
     * 实例，Bootstrap 接口本就允许），其内部完整加载配置（含 process）、执行 config/bootstrap.php
     * 与插件 bootstrap 类、注册中间件与路由。
     *
     * 幂等：仅首次调用生效（重复引导会重复注册路由）。
     */
    public function bootstrapWebman(): void
    {
        if ($this->bootstrapped) {
            return;
        }
        // 与 webman 相同的方式：worker_start() 也是 require 应用侧 support/bootstrap.php
        // （骨架文件通常为一行转发，应用可自定义扩展）
        $file = rtrim($this->config->appDir, '/') . '/support/bootstrap.php';
        if (!is_file($file)) {
            throw new RuntimeException("引导 webman 环境需要被测应用提供 support/bootstrap.php（未找到 {$file}）");
        }
        try {
            require_once $file;
        } finally {
            // webman bootstrap 会 set_error_handler（错误转异常）压入栈顶，测试框架（PHPUnit/Pest）
            // 会检测 error handler 栈变化并标记 risky，引导后弹出恢复
            restore_error_handler();
        }
        $this->bootstrapped = true;
    }

    /**
     * 读取被测应用 config/process.php 中 webman 进程的 listen 配置（与 webman 相同的读取方式）
     *
     * 测试进程加载被测应用 vendor/autoload.php 时，webman-framework 的 composer.json
     * autoload.files 已注册 helpers.php，config() 函数恒可用；配置数据（Webman\Config
     * 静态类）在 Server::instance 时经 ensureConfigLoaded 确保已加载——直接走 webman
     * 的 config()，与 webman 进程内读取同一份配置数据（应用侧如何切换端口组件无需关注）
     */
    private function readListen(): string
    {
        $listen = config('process.webman.listen');
        if (!is_string($listen) || $listen === '') {
            throw new InvalidArgumentException(
                "未检测到 webman 进程的 listen 配置：config('process.webman.listen') 需为监听地址字符串（被测应用在 config/process.php 中配置）"
            );
        }

        return $listen;
    }

    /**
     * 幂等启动 server 并等待就绪
     */
    public function ensureStarted(): void
    {
        if ($this->process?->isRunning()) {
            return;
        }

        // 同一应用目录同时只能运行一个 workerman 实例，先清理上轮可能残留的 master
        // （否则新进程会因 "already running" 以 exitCode=0 退出）
        $this->cleanupStaleInstance();
        // worker 进程在 master 异常死亡时会成为孤儿（如定时任务每秒写副作用文件，会污染时序断言），
        // 仅在当前 server 未运行时调用（启动前/停止后），按 cwd 匹配本应用目录，不影响其它项目实例
        $this->cleanupOrphanWorkers();

        $this->process = new Process(
            [$this->config->phpBinary, $this->config->entryFile, 'start'],
            $this->config->appDir,
            $this->config->serverEnv,
            null,
            $this->config->processTimeout,
        );
        $this->process->start();

        $deadline = microtime(true) + $this->config->startTimeout;
        $lastError = '';
        while (microtime(true) < $deadline) {
            if (!$this->process->isRunning()) {
                $this->dumpAndThrow('webman 进程意外退出，exitCode=' . var_export($this->process->getExitCode(), true));
            }
            // workerman 标准输出
            if (str_contains($this->process->getOutput(), (string)$this->config->stdoutReady)) {
                return;
            }
            $lastError = '等待 stdout 就绪标志「' . $this->config->stdoutReady . '」';
            usleep(200_000);
        }

        $this->dumpAndThrow("webman 启动超时({$this->config->startTimeout}s)，最后错误: {$lastError}");
    }

    public function stop(): void
    {
        $process = $this->process;
        $this->process = null;
        if ($process !== null && $process->isRunning()) {
            // workerman master 收到 SIGTERM 会 stop（并通知 worker）
            $process->stop($this->config->stopTimeout);
        }
        // SIGTERM 只发给 master：worker 若未随 master 退出（或 master 被 SIGKILL），
        // 会残留为孤儿进程继续跑定时任务，这里扫尾清理
        $this->cleanupOrphanWorkers();
    }

    /**
     * 注入自定义 PSR-18 HTTP 客户端（覆盖自动发现；任意时刻调用均生效）
     *
     * 自定义客户端需保证 4xx/5xx 响应不抛异常（对应 guzzle 的 http_errors=false），
     * 否则 TestResponse 无法断言错误响应、探活会把就绪误判为失败。
     */
    public function setHttpClient(ClientInterface $client): void
    {
        $this->customHttpClient = $client;
        $this->httpClient = null;
    }

    /**
     * PSR-18 HTTP 客户端（自动发现：自定义注入优先，其次 guzzle，构造参数取 TestingConfig::httpClient）
     */
    public function client(): ClientInterface
    {
        return $this->httpClient ??= HttpClientFactory::create($this->customHttpClient, $this->config->httpClient);
    }

    /**
     * 发送 HTTP 请求（相对 baseUrl 的 uri），自动确保 server 已启动
     *
     * options 为请求选项（headers / json / form_params / query / allow_redirects），
     * 语义与 guzzle 对齐（经 RequestFactory 解析为 PSR-7 请求发送）
     */
    public function request(string $method, string $uri, array $options = []): TestResponse
    {
        $this->ensureStarted();

        $request = RequestFactory::create($method, $this->baseUrl() . $uri, $options);
        $response = $this->client()->sendRequest($request);

        // PSR-18 客户端不自动跟随重定向，手动实现 guzzle 语义（allow_redirects: false | true | ['max' => N]）
        $redirects = $options[RequestFactory::OPT_ALLOW_REDIRECTS] ?? true;
        if ($redirects !== false) {
            $max = is_array($redirects) ? (int)($redirects['max'] ?? 5) : 5;
            for ($i = 0; $i < $max && self::isRedirect($response); $i++) {
                $request = self::redirectRequest($request, $response);
                $response = $this->client()->sendRequest($request);
            }
        }

        return new TestResponse($response);
    }

    private static function isRedirect(ResponseInterface $response): bool
    {
        return in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true);
    }

    /**
     * 构造重定向后的新请求（guzzle 语义：301/302/303 非 GET/HEAD 转 GET；307/308 保持方法与 body）
     */
    private static function redirectRequest(RequestInterface $request, ResponseInterface $response): RequestInterface
    {
        $location = $response->getHeaderLine('Location');
        if ($location === '') {
            throw new RuntimeException('重定向响应缺少 Location header');
        }
        if (str_starts_with($location, '/')) {
            // 相对路径基于当前请求 URI 解析（测试场景 Location 通常为绝对路径）
            $uri = $request->getUri();
            $location = $uri->getScheme() . '://' . $uri->getAuthority() . $location;
        }

        $status = $response->getStatusCode();
        $method = $request->getMethod();
        $toGet = $status !== 307 && $status !== 308 && !in_array($method, ['GET', 'HEAD'], true);
        $method = $toGet ? 'GET' : $method;
        $body = $toGet ? null : $request->getBody();
        if ($body instanceof StreamInterface) {
            // body 流可能已被发送消费，回绕后复用
            $body->rewind();
        }
        $headers = $toGet
            ? array_diff_key($request->getHeaders(), ['Content-Type' => true, 'Content-Length' => true])
            : $request->getHeaders();

        return RequestFactory::create($method, $location, [RequestFactory::OPT_HEADERS => $headers, RequestFactory::OPT_RAW_BODY => $body]);
    }

    private function dumpAndThrow(string $message): never
    {
        $output = $this->process?->getOutput() . $this->process?->getErrorOutput();
        $this->stop();
        throw new RuntimeException($message . "\n--- webman 进程输出 ---\n" . $output);
    }

    /**
     * 清理残留的旧实例：pid 文件存在且进程存活时先 SIGTERM 优雅停止，超时 SIGKILL 兜底
     */
    private function cleanupStaleInstance(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGTERM')) {
            return;
        }

        $pidFile = $this->runtimePath(self::PID_FILE);
        if (!is_file($pidFile)) {
            return;
        }

        $pid = (int)file_get_contents($pidFile);
        if ($pid > 0 && posix_kill($pid, 0)) {
            posix_kill($pid, SIGTERM);
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline) {
                // posix_kill($pid, 0) 为进程存在性探活（有时序副作用，非纯函数）
                /** @phpstan-ignore-next-line booleanAnd.rightAlwaysTrue */
                if (!posix_kill($pid, 0)) {
                    break;
                }
                usleep(100_000);
            }
            /** @phpstan-ignore-next-line if.alwaysTrue — 探活非纯函数，SIGTERM 后进程可能仍在退出中 */
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                usleep(200_000);
            }
        }
        // master 停止时 workerman 会自行删除 pid 文件，这里二次检查后清理残留
        /** @phpstan-ignore-next-line if.alwaysTrue — is_file 结果随时序变化（master 退出会删除文件） */
        if (is_file($pidFile)) {
            unlink($pidFile);
        }
    }

    /**
     * 清理 cwd 为本应用目录的所有 workerman 进程（master 与 worker）。
     *
     * 只在当前 server 未运行时调用（启动前/停止后）；通过 cwd 匹配避免误杀其它项目的实例。
     * （worker 进程的 ps 命令行不含启动文件路径，只能以 cwd 识别归属）
     */
    private function cleanupOrphanWorkers(): void
    {
        if (!function_exists('posix_kill')) {
            return;
        }

        $appDir = realpath($this->config->appDir) ?: $this->config->appDir;
        $output = (string)shell_exec("ps -axo pid=,command= | grep 'WorkerMan' | grep -v grep");
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ($line === '' || !preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
                continue;
            }
            [, $pid, $command] = $m;
            if (!str_contains($command, 'WorkerMan:')) {
                continue;
            }
            $cwd = trim((string)shell_exec(sprintf('lsof -a -p %d -d cwd -Fn 2>/dev/null | grep "^n" | cut -c2-', (int)$pid)));
            if ($cwd === '' || realpath($cwd) !== $appDir) {
                continue;
            }
            posix_kill((int)$pid, SIGKILL);
        }
        // 给 SIGKILL 一点生效时间，避免端口/文件残留
        usleep(200_000);
    }
}
