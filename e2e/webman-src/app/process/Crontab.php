<?php

namespace app\process;

use Workerman\Crontab\Crontab as WorkermanCrontab;

/**
 * 副作用演示进程：每秒追加一行 runtime/e2e-crontab.log
 * （测试进程经 webmanWaitFor 轮询该文件增长，覆盖 workerman/crontab 按整分钟对齐的时序陷阱）
 */
class Crontab
{
    public function onWorkerStart(): void
    {
        new WorkermanCrontab('*/1 * * * * *', function () {
            file_put_contents(runtime_path() . '/e2e-crontab.log', date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        });
    }
}
