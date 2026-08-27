<?php

// 副作用等待：crontab 进程随 server 启动，按秒调度写副作用文件，webmanWaitFor 轮询等待
//
// 注意：workerman/crontab 的调度按整分钟对齐（new Crontab() 后等到下一个 xx:00 才首次触发），
// 涉及时序的等待 timeout 需覆盖跨分钟边界（60 - date('s') + 10）。

test('crontab 副作用可被 webmanWaitFor 轮询到', function () {
    $this->webmanServer()->ensureStarted();

    $runtimePath = $this->webmanRuntimePath();
    $initial = e2e_crontab_count($runtimePath);

    // 跨过整分钟后每秒执行必然增长
    $current = $this->webmanWaitFor(function () use ($initial, $runtimePath) {
        clearstatcache();
        $count = e2e_crontab_count($runtimePath);

        return $count > $initial ? $count : false;
    }, 60 - (int)date('s') + 10, 0.3);

    expect($current)->toBeGreaterThan($initial);
});
