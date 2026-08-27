## 项目概述

webman 的真实进程测试组件（laravel 式体验、webman 式实现）：复刻 laravel 的 TestResponse 断言 API，底层用「真实 webman 进程 + HTTP 请求」替代 laravel 的「进程内 kernel->handle」。

**架构定位**：组件价值 = Server 进程编排能力（一切「需要真实 webman 进程」的测试形态的公共底座）+ 便捷层（HTTP 断言 / CLI 命令 / 副作用等待）。HTTP 是最常用的包装，非 HTTP 测试（CLI 命令、自定义 process、crontab 副作用）同样是一等公民。

**设计边界**（勿越界）：
- 不做进程内模拟 HTTP 请求（webman 常驻内存模型下脆弱，社区「复制 App.php」方案已证明）
- 不做容器魔法（actingAs/mock/withoutMiddleware/swap 需同进程；替代物 withToken/actingViaToken + 应用侧 reset 端点模式）
- 不做 RefreshDatabase/seed/expectsDatabaseQueryCount（webman 无官方 migrate，且依赖进程内容器）；InteractsWithDatabase 的定位是「测试进程直接连库断言」——sqlite 需用文件库（:memory: 仅存在于 server 进程内，测试进程连不上），DSN 用 `webmanRuntimePath()` 定位同源文件
- 不硬依赖 pest（断言用 `PHPUnit\Framework\Assert` 静态方法，pest/phpunit 双兼容）

## 开发命令

```bash
composer test      # 运行组件自身单测（pest）
composer phpstan   # PHPStan level 9 静态分析
```

本包**非** webman 插件：无 copy 模板、无 Install；配置由被测应用自行维护 `config/testing.php`（webman 自动加载为 `config('testing')`，测试进程自动读取同一文件，见下文「Server 配置源与优先级」）。

## 目录结构

- `src/`（按功能模块划分子目录，根目录仅保留核心入口；命名空间与目录一一对应，如 `Http/TestResponse.php` → `WebmanTech\Testing\Http\TestResponse`）：
  - `Server.php`：进程编排核心（单例、启停、就绪等待、残留进程清理、PSR-18 发送）
  - `TestCase.php`：测试基类，组合 5 个 Concerns（对齐 laravel `Illuminate\Foundation\Testing\TestCase` 组合模式）；phpunit 直接继承；pest 经 `pest()->extend(TestCase::class)->in(...)` 绑定（laravel 骨架同款机制）；全部能力收敛为基类方法，无全局函数；继承 `PHPUnit\Framework\TestCase as BaseTestCase`
  - `Config/`：配置域
    - `TestingConfig.php`：配置（数组构造 + webman 配置文件，只做配置传导）
  - `Http/`：HTTP 域（请求发送 + 响应断言）
    - `HttpClientFactory.php`：PSR-18 客户端自动发现（自定义注入优先 → guzzle；都没有抛可读异常提示）
    - `RequestFactory.php`：请求选项 → PSR-7 Request（选项键收口为 `RequestFactory::OPT_*` 常量：headers/json/form_params/query/raw_body/allow_redirects，语义对齐 guzzle；新增选项先登记常量再实现解析）
    - `TestResponse.php`：laravel 风格断言，包装 PSR-7 Response
  - `Console/`：CLI 域
    - `CommandResult.php`：webman CLI 命令结果 + 断言（对应 laravel `Illuminate\Testing\PendingCommand`）
  - `Concerns/`：能力按 laravel 同名 Concern 拆分组合（MakesHttpRequests/InteractsWithAuthentication/InteractsWithConsole/InteractsWithDatabase/InteractsWithServer）
    - `MakesHttpRequests.php`：`$this->get/post/put/patch/delete[Json]/head/options[Json]/json` + headers/cookie/redirects/auth 配置族（laravel 同签名；`webmanRequest` 组装默认 headers 与 cookies/allow_redirects，`webmanSend` 为发送层钩子可 stub，宿主类需提供 webmanServer()）
    - `InteractsWithAuthentication.php`：actingViaToken（withToken 的语义别名，对应 laravel 同名 Concern）
    - `InteractsWithConsole.php`：webmanCommand（对应 laravel `InteractsWithConsole::artisan()`，执行 `php <command> <args>`，命令名取 TestingConfig::command，默认 webman）
    - `InteractsWithDatabase.php`：PDO 实现 assertDatabaseHas/Missing/Count/Empty/SoftDeleted/NotSoftDeleted；表名/列名白名单校验 + bindValue 参数绑定防注入；`setDatabaseConnection(new PDO(...))` 注入连接（对应 laravel 同名 Concern）
    - `InteractsWithServer.php`：webmanServer/webmanRuntimePath/webmanWaitFor（laravel 无对应物：webman 为常驻进程，需真实进程编排）
  - `Exceptions/`：WebmanTestingTimeoutException
- `README.md`：用户向文档（安装、用法、API 概览、常见场景）

## 关键实现约束

### Server 进程编排（src/Server.php）

- **单例**：`Server::instance(?TestingConfig $config)` 首次携带配置生效、后续忽略；`register_shutdown_function` 保证停止
- **端口**：取 `TestingConfig::port`，未配置时用 TestingConfig 私有 FALLBACK_PORT（18787 = 8787 + 10000，避开 webman 官方默认端口）；端口与业务侧共享的 `config/testing.php` 同源（应用在 config/process.php 以 `config('testing.port') ?: 18787` 读取同一来源），无环境变量注入
- **就绪等待**：仅依赖 stdoutReady 就绪标志（workerman 标准输出，可能被块缓冲；未配置时默认等待「Start success」）
- **残留进程清理**（均在「当前 server 必然未运行」的时机调用）：
  - `cleanupStaleInstance()`：pid 文件 + SIGTERM → 5s → SIGKILL 兜底 → unlink（防止 "already running" 以 exitCode=0 退出）
  - `cleanupOrphanWorkers()`：按 cwd（lsof）匹配本应用目录的所有 WorkerMan 进程 SIGKILL（worker 的 ps 命令行不含启动文件路径，只能以 cwd 识别归属；孤儿会跑定时任务污染时序断言）
- **HTTP client（PSR-18，不绑定实现）**：只依赖 psr/http-client + psr/http-message 接口；`HttpClientFactory::create($custom, $options)` 自动发现（`Server::setHttpClient()` 注入的优先 → class_exists 检测 guzzle → 可读异常提示）。guzzle 构造参数经 `TestingConfig::httpClient` 配置化（默认 timeout=10/connect_timeout=2），`http_errors => false` 恒强制（4xx/5xx 不抛异常交由断言）；自定义客户端需自行保证 4xx/5xx 不抛异常
- **请求发送**：`RequestFactory::create()` 把 guzzle 形态的 options（键名收口为 `RequestFactory::OPT_*` 常量，见源码）解析为 PSR-7 Request（Content-Type 自动补、query 替换 uri 已有 query、raw_body 直传流）；PSR-18 无自动重定向，`Server::request()` 手动实现 guzzle 语义（301/302/303 非 GET/HEAD 转 GET、307/308 保持方法与 body、Location 相对路径基于当前 URI 解析）

### Server 配置源与优先级（src/Config/TestingConfig.php）

- **优先级**：显式传参 > config/testing.php > 默认值（本类只做配置传导，不做环境变量等旁路配置源）
- **config/testing.php**：自动读取 `{appDir}/config/testing.php`（应用侧自行维护，webman 会自动加载为 `config('testing')`，测试进程直接 require 同一文件；键名与 fromConfig 一致）。读取方式：测试进程已加载 webman 框架（`function_exists('config')`，需 phpstan stub 支持）时优先用 `config()`（完整 webman 语义）；否则直接 require 文件（测试进程通常未加载 webman 框架），文件内 PHP 异常会被包装为带文件路径与「避免 base_path() 等 webman 专属函数」提示的可读异常

### 时序陷阱（改动前必读）

- workerman/crontab 按整分钟对齐调度（`new Crontab()` 后等到下一个 xx:00 才首次触发），涉及等待需 `webmanWaitFor(..., 60 - date('s') + 10)`
- 日志落盘顺序与副作用行写入存在竞态（同一执行内 start → 副作用行 → end），断言日志内容需轮询等待
- `webmanCommand()` 不启动 HTTP server（只复用 TestingConfig 的 appDir/phpBinary/command 定位信息），但会创建 Server 单例（注册 shutdown function，无害）

## 测试方式

- **组件自身单测**（`tests/Unit/Testing/`）：TestResponse 直接构造 PSR-7 Response 验证断言语义；CommandResult 起真实 php 小进程；TestingConfig 验证构造/校验/配置传导（显式 > config/testing.php > 默认值，fixture：`tests/Fixtures/Testing/webman-app/start.php`、`webman-config-app/`（含 config/testing.php 配置文件））；TestCase 验证单例契约/runtime 拼接/轮询行为；MakesHttpRequests 经 stub `webmanSend` 验证请求组装（headers 合并、JSON 选项、cookie 拼接）；RequestFactory/HttpClientFactory 验证 options→PSR-7 解析与自动发现（guzzle 已装场景）；InteractsWithDatabase 用 sqlite :memory:（进程内）验证断言语义与防注入
- **e2e 测试**（真实 HTTP/CLI/进程路径的覆盖）：[components-monorepo](https://github.com/webman-tech/components-monorepo) 的 `e2e/webman-src` 是组件的最大使用方——Server 进程编排、`$this->` 请求方法（pest extend 绑定）、基类全部方法、TestResponse 断言都在真实 webman 链路下运行（43 个测试，含重定向跟随/不跟随双路径、config/testing.php 的 httpClient 参数生效）；e2e 应用的 testing 配置由 `e2e/webman-src/config/testing.php` 维护（sync 覆盖，验证「应用侧共享配置」链路）；数据库断言走文件型 sqlite（runtime/e2e.sqlite），测试进程 PDO 直连 server 同源文件库跨进程验证（:memory: 无法跨进程）；e2e 应用的 guzzle 由 setup.php 的 require_dev 显式声明（组件不再强依赖，验证自动发现链路）。**组件行为改动后必须跑 e2e**（在 components-monorepo 仓库执行，本包独立维护后经 VCS dev-main 安装，需先推送 main）：`php e2e/setup.php webman` + `cd e2e/webman && vendor/bin/pest`

## 注意事项

1. 修改本包后：本仓库单测 + phpstan 全量都要跑；e2e（components-monorepo 的集成验证）需推送 main 后重建安装才能拉到最新代码
2. e2e 的端口约定：config/process.php 的 `config('testing.port') ?: 18787` 与 TestingConfig 私有 FALLBACK_PORT（18787）需保持一致（两端独立 fallback，改端口时同步两处）
3. 能力全部收敛为类方法，不新增全局函数（用户明确要求：不要使用函数做功能）
4. phpstan level 9：`posix_kill`/`is_file` 的重复调用会被误判纯函数恒真，用带注释的 `@phpstan-ignore-next-line` 说明时序语义
