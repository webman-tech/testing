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
composer e2e:install   # 重建 e2e 应用（create-project 最新 webman 骨架 + patch + update + sync webman-src）
composer e2e:sync      # 仅同步 e2e 自有代码（dev 快速迭代）
composer e2e:test      # 运行 e2e 测试（cd e2e/webman && vendor/bin/pest）
composer e2e           # 完整 e2e（install + test）
composer e2e:vcs       # testing 组件经 GitHub VCS dev-main 安装（验证发布链路，需先推送 main）
```

本包**非** webman 插件：无 copy 模板、无 Install；配置由被测应用自行维护 `config/testing.php`（webman 自动加载为 `config('testing')`，测试进程自动读取同一文件，见下文「Server 配置源与优先级」）。

## 目录结构

- `src/`（按功能模块划分子目录，根目录仅保留核心入口；命名空间与目录一一对应，如 `Http/TestResponse.php` → `WebmanTech\Testing\Http\TestResponse`）：
  - `Server.php`：进程编排核心（单例、启停、就绪等待、残留进程清理、PSR-18 发送、webman 环境引导 bootstrapWebman）
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
    - `InteractsWithServer.php`：webmanServer/webmanRuntimePath/webmanWaitFor/webmanBootstrap（laravel 无对应物：webman 为常驻进程，需真实进程编排）
  - `Exceptions/`：WebmanTestingTimeoutException
- `README.md`：用户向文档（安装、用法、API 概览、常见场景）
- `skills/`：AI 技能（随包分发，辅助用户正确使用组件）
  - `webman-tech-testing-best-practices`：testing 使用的最佳实践（有立场的推荐写法、常见坑）
- `e2e/`：真实 webman 环境验证（见「测试方式」e2e 段）：`setup.php`（create-project 最新骨架 → patch composer.json → update → sync webman-src）；`webman-src/`（提交的自有代码：config 覆盖/app 演示/tests，见 e2e/README.md）

## 关键实现约束

### Server 进程编排（src/Server.php）

- **单例**：`Server::instance(?TestingConfig $config)` 首次携带配置生效、后续忽略；`register_shutdown_function` 保证停止
- **监听地址（host/port 不经组件配置）**：`Server::baseUrl()` 以与 webman 相同的方式读取 webman 进程的 listen 配置——测试进程加载被测应用 vendor/autoload.php 时，webman-framework 的 composer.json autoload.files 已注册 helpers.php（`config()` 函数可用；但配置数据存储在 `Webman\Config` 静态类，未引导 webman 时为空，`TestingConfig::ensureConfigLoaded(appDir)` 在读取前自动 `Webman\Config::load(appDir/config, ['route'])` 填充，幂等），`readListen()` 直接取 `config('process.webman.listen')`，与 webman 进程内读取同一份配置数据（应用侧如何切换端口——如 process.php env 化 + phpunit.xml 注入应用自定义 env，示例 `APP_PORT`——组件无需关注）。`resolveListen()`：parse_url，0.0.0.0/:: 映射为 127.0.0.1，未指定端口默认 80
- **webman 环境引导**：`bootstrapWebman()`（基类便捷方法 `webmanBootstrap()`）供非 HTTP 测试（tests/Unit 直接使用 webman 组件）在测试进程内复现 worker 进程的初始化——与 worker_start() 相同的方式 require 应用侧 `support/bootstrap.php`（骨架文件通常为一行转发，应用可自定义扩展；`$worker` 传 null，Bootstrap 接口本就允许），其内部完整加载配置（含 process）、.env、时区、autoload.files、中间件、config('bootstrap') 与插件 bootstrap 类、路由。幂等（仅首次生效，重复引导会重复注册路由）；引导会 set_error_handler 压栈，需 finally `restore_error_handler()` 弹出恢复，避免测试框架（PHPUnit/Pest）检测到 error handler 栈变化标记 risky
- **就绪等待**：仅依赖 stdoutReady 就绪标志（workerman 标准输出，可能被块缓冲；未配置时默认等待「Start success」）
- **残留进程清理**（均在「当前 server 必然未运行」的时机调用）：
  - `cleanupStaleInstance()`：pid 文件 + SIGTERM → 5s → SIGKILL 兜底 → unlink（防止 "already running" 以 exitCode=0 退出）
  - `cleanupOrphanWorkers()`：按 cwd（lsof）匹配本应用目录的所有 WorkerMan 进程 SIGKILL（worker 的 ps 命令行不含启动文件路径，只能以 cwd 识别归属；孤儿会跑定时任务污染时序断言）
- **HTTP client（PSR-18，不绑定实现）**：只依赖 psr/http-client + psr/http-message 接口；`HttpClientFactory::create($custom, $options)` 自动发现（`Server::setHttpClient()` 注入的优先 → class_exists 检测 guzzle → 可读异常提示）。guzzle 构造参数经 `TestingConfig::httpClient` 配置化（默认 timeout=10/connect_timeout=2），`http_errors => false` 恒强制（4xx/5xx 不抛异常交由断言）；自定义客户端需自行保证 4xx/5xx 不抛异常
- **请求发送**：`RequestFactory::create()` 把 guzzle 形态的 options（键名收口为 `RequestFactory::OPT_*` 常量，见源码）解析为 PSR-7 Request（Content-Type 自动补、query 替换 uri 已有 query、raw_body 直传流）；PSR-18 无自动重定向，`Server::request()` 手动实现 guzzle 语义（301/302/303 非 GET/HEAD 转 GET、307/308 保持方法与 body、Location 相对路径基于当前 URI 解析）

### Server 配置源与优先级（src/Config/TestingConfig.php）

- **优先级**：显式传参 > config/testing.php > 默认值（本类只做配置传导，不做环境变量等旁路配置源；host/port 不在配置项中——监听地址由 Server 从应用 process.php 读取，见上文 Server 条目）
- **config/testing.php**：`TestingConfig::readWebmanConfigFile()` 直接走 `config('testing')`（与 webman 进程内读取同一份配置数据；helpers 由被测应用 composer autoload.files 自动加载，配置数据由 `ensureConfigLoaded` 确保已加载）。应用侧自行维护该文件，webman 自动加载为 `config('testing')`；键名与 fromConfig 一致

### 时序陷阱（改动前必读）

- workerman/crontab 按整分钟对齐调度（`new Crontab()` 后等到下一个 xx:00 才首次触发），涉及等待需 `webmanWaitFor(..., 60 - date('s') + 10)`
- 日志落盘顺序与副作用行写入存在竞态（同一执行内 start → 副作用行 → end），断言日志内容需轮询等待
- `webmanCommand()` 不启动 HTTP server（只复用 TestingConfig 的 appDir/phpBinary/command 定位信息），但会创建 Server 单例（注册 shutdown function，无害）

## 测试方式

- **组件自身单测**（`tests/Unit/`）：TestResponse 直接构造 PSR-7 Response 验证断言语义；CommandResult 起真实 php 小进程；TestingConfig 验证构造/校验/配置传导（显式 > config/testing.php > 默认值，fixture：`tests/Fixtures/webman-app/`（start.php + config/process.php——listen 读取的 fixture）、`webman-config-app/`（含 config/testing.php 配置文件））；单测环境无 webman 框架，`tests/Pest.php` 按 webman Config 语义模拟 `config()`（经 `$GLOBALS['webman_mock_app_dir']`/`$GLOBALS['webman_mock_config_override']` 控制数据源，各 fromConfig/Server 测试文件 beforeEach 设置）；TestCase 验证单例契约/runtime 拼接/轮询行为；ServerTest 验证 resolveListen 解析（0.0.0.0 映射/默认端口/非法地址）、baseUrl 从模拟 config() 读取、listen 缺失可读异常、bootstrapWebman 缺文件可读异常；MakesHttpRequests 经 stub `webmanSend` 验证请求组装（headers 合并、JSON 选项、cookie 拼接）；RequestFactory/HttpClientFactory 验证 options→PSR-7 解析与自动发现（guzzle 已装场景）；InteractsWithDatabase 用 sqlite :memory:（进程内）验证断言语义与防注入
- **e2e 测试**（真实 HTTP/CLI/进程路径的覆盖）：本仓库 `e2e/` 自建真实 webman 骨架应用（`php e2e/setup.php webman`：composer create-project 最新版 workerman/webman → patch composer.json → composer update → sync `e2e/webman-src` 覆盖）。e2e 应用即组件的最大使用方——Server 进程编排、`$this->` 请求方法（pest extend 绑定）、基类全部方法、TestResponse 断言都在真实 webman 链路下运行（重定向跟随/不跟随双路径、303 POST 转 GET、config/testing.php 的 httpClient 参数生效、监听地址链路——e2e process.php 含 Log::channel/app_path 等 webman 专属写法，验证组件经 config() 读取 listen（phpunit.xml 注入 APP_PORT=18787 后两端同址、业务模式保持 8787）、WebmanBootstrapTest 验证 bootstrapWebman 引导后 config/support\Log/路由/时区与 worker 进程内一致且无 risky）；数据库断言走文件型 sqlite（runtime/e2e.sqlite）：应用侧经 webman/database（support\Db）操作，连接由 config/database.php env 化控制（phpunit.xml 注入 DB_CONNECTION=sqlite，业务默认 mysql，与 APP_PORT 同一模式）；测试进程 PDO 直连 server 同源文件库跨进程验证（:memory: 无法跨进程）；guzzle 由 setup.php 的 require_dev 显式声明（组件不强制依赖，验证自动发现链路）；crontab 进程演示副作用轮询等待。testing 组件默认经 path repository 引用当前仓库代码（本地/CI 直接验证当前 checkout，symlink 即时生效），`--vcs` 切换为 GitHub VCS dev-main（验证发布链路，需先推送 main）。**组件行为改动后必须跑 e2e**：`php e2e/setup.php webman` + `cd e2e/webman && vendor/bin/pest`（CI 已含 e2e job）

## 注意事项

1. 修改本包后：本仓库单测 + phpstan 全量都要跑；e2e 默认 path 引用当前代码直接验证（改动即时生效），`--vcs` 验证发布链路需先推送 main 再重建安装
2. 端口约定：组件不配置 host/port——监听地址直接读应用 `config/process.php` 的 webman 进程 listen（与 webman 同一读取方式：测试进程经 config('process.webman.listen')，配置数据由 ensureConfigLoaded 自动加载，天然一致）。业务端口由应用侧 process.php 自行决定（默认 8787，勿改为测试端口）；「测试用独立端口」为应用侧处理模式：process.php env 化（`getenv('APP_PORT') ?: <业务端口>`）+ phpunit.xml `<php><env name="APP_PORT" value="18787"/>` 注入（测试进程环境 → server 子进程继承 → process.php 读取；组件经 config() 读到同一 listen），e2e 已按此模式配置并验证闭环。**`APP_PORT` 为 e2e 自定义的示例变量名（非 webman 官方约定）**，开发者可自定义命名；数据库连接等其它测试环境切换同理（config/database.php env 化 + phpunit.xml 注入，建议见 skill 的数据库断言章节）
3. 能力全部收敛为类方法，不新增全局函数（用户明确要求：不要使用函数做功能）
4. phpstan level 9：`posix_kill`/`is_file` 的重复调用会被误判纯函数恒真，用带注释的 `@phpstan-ignore-next-line` 说明时序语义
