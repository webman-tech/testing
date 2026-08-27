<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Concerns;

use WebmanTech\Testing\Config\TestingConfig;
use WebmanTech\Testing\Server;
use WebmanTech\Testing\Exceptions\WebmanTestingTimeoutException;

/**
 * webman server 编排（laravel 无对应物：webman 为常驻进程，需真实进程编排）
 *
 * 共享 server（进程级单例）、runtime 目录定位、副作用轮询等待。
 */
trait InteractsWithServer
{
    /**
     * 共享的 webman server（进程级单例；首次调用可携带配置）
     */
    public function webmanServer(?TestingConfig $config = null): Server
    {
        return Server::instance($config);
    }

    /**
     * server 的 runtime 目录（副作用日志/文件断言的锚点）；$sub 为相对子路径
     */
    public function webmanRuntimePath(?string $sub = null): string
    {
        return $this->webmanServer()->runtimePath($sub);
    }

    /**
     * 引导被测应用 webman 环境（见 Server::bootstrapWebman()）
     *
     * 非 HTTP 测试（tests/Unit 直接使用 webman 组件）场景：在测试闭包/setUp 中调用，
     * 使测试进程内配置/容器/路由/中间件与 webman 进程内一致（幂等）。
     */
    public function webmanBootstrap(): void
    {
        $this->webmanServer()->bootstrapWebman();
    }

    /**
     * 通用副作用轮询：$probe 返回真值即返回该值，超时抛 WebmanTestingTimeoutException
     *
     * 典型场景：等待定时任务副作用文件增长、等待日志落盘。
     * 注意 workerman/crontab 按整分钟对齐调度（new Crontab() 后等到下一个 xx:00 才首次触发），
     * 涉及分钟级 cron 的等待需将 timeout 传为 `60 - date('s') + 10` 以覆盖跨分钟边界。
     */
    public function webmanWaitFor(callable $probe, float $timeout = 10.0, float $interval = 0.3): mixed
    {
        $deadline = microtime(true) + $timeout;
        $last = null;
        while (microtime(true) < $deadline) {
            $last = $probe();
            if ($last) {
                return $last;
            }
            usleep((int)($interval * 1_000_000));
        }

        throw new WebmanTestingTimeoutException(sprintf(
            '等待超时(%.1fs)，最后的探测返回: %s',
            $timeout,
            var_export($last, true),
        ));
    }
}
