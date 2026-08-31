---
name: webman-tech-testing-best-practices
description: webman 真实进程测试。触发：写 HTTP 接口测试、认证与状态隔离、等待异步副作用（crontab/日志）、数据库直连断言、CLI 命令测试、测试环境配置。
---

# webman-tech/testing 最佳实践

## 核心原则

1. **真实进程，HTTP 断言**：测试即「起真实 webman 进程 + 发 HTTP 请求」，完整覆盖路由/中间件/异常管线/自定义进程；不做进程内模拟请求
2. **共享一个 server**：整个测试进程只启一个 server——`$this->get()` 自动启动、进程结束自动停止；不手动启停、不并发跑同一应用的测试
3. **环境切换靠应用侧 env 化配置**：组件不配置 host/port，测试/业务环境切换（端口、数据库连接等）由应用侧 env 化配置 + `phpunit.xml` 注入驱动，env 变量名由应用自行定义——详见「测试环境切换」章节
4. **跨进程限制**：无法 actingAs/mock/swap（需同进程）；替代物是 `withToken` + 应用侧 reset 端点

## 写 HTTP 接口测试

pest 在 `tests/Pest.php` 绑定基类（laravel 骨架同款机制），闭包内直接 `$this->`：

```php
pest()->extend(WebmanTech\Testing\TestCase::class)->in('.');
```

```php
test('health', function () {
    $this->get('/health')->assertOk()->assertJson(['status' => 'ok']);
});
```

**token 流转**：登录签发 token 后，后续请求用 `withToken()` 携带，不手拼 Authorization header：

```php
test('登录后可获取用户', function () {
    $token = $this->postJson('/auth/login')->json('access_token');

    $this->withToken($token)->getJson('/auth/user')->assertOk()->assertJson(['name' => 'demo']);
});
```

**重定向默认不跟随**（可断言 302 + Location）；需跟随时显式 `followingRedirects()`：

```php
$this->get('/redirect')->assertStatus(302)->assertLocation('/health');
$this->followingRedirects()->post('/redirect-post')->assertOk(); // 303 自动转 GET
```

## 认证与状态隔离

- **认证**：`withToken()` / `actingViaToken()` 传 token；登录态以外的容器能力（mock/swap/withoutMiddleware）在真实进程下不可用
- **数据隔离**：应用侧提供 reset 端点（webman 常驻内存，测试进程无法直接清 server 内状态），测试前置调用保证干净：

```php
test('GET 列表可断言 count 与逐条数据（先 reset 保证干净）', function () {
    $this->post('/data/reset')->assertOk();
    $this->postJson('/data/users', ['name' => 'demo'])->assertCreated();

    $this->getJson('/data/users')->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'demo');
});
```

## 等待异步副作用

crontab/日志/队列等异步写入，用 `webmanWaitFor` 轮询（返回 false 继续等，超时抛可读异常）：

```php
$count = $this->webmanWaitFor(function () {
    return e2e_crontab_count($this->webmanRuntimePath()) ?: false;
}, 20, 0.3); // 超时 20s，轮询间隔 0.3s
```

- **crontab 按整分钟对齐**（new Crontab() 后等到下一个 xx:00 才首次触发）：等待超时至少 `60 - date('s') + 10`，否则跨分钟时必然等不到
- 副作用文件写在 server 的 runtime 下，路径用 `webmanRuntimePath()` 定位（测试进程与应用同目录，天然同源）
- 断言日志内容同样要轮询：日志落盘与副作用行写入存在竞态

## 数据库断言

测试进程**直连数据库**断言（PDO），与 server 进程共享同一数据源；需要迁移/数据隔离时用 `setUpDatabase()`（对应 laravel RefreshDatabase）一步就绪：

```php
// 应用侧测试基类 setUp() 中一行调用（对应 laravel 的 RefreshDatabase）：
// 1. 迁移：进程级一次性（默认 phinx，复用应用 phinx.php；可经 testing 配置 database.migrator 覆盖）
// 2. 连接：自动注入连接（未手动注入时；取被测应用 Db（support\Db）同源连接——组件默认下游已安装 webman/database）
// 3. 隔离：按 $isolation 模式隔离数据（默认 truncate）
$this->setUpDatabase(['sqlite' => 'testing.sqlite']); // $expect 可选安全校验（防误连业务库）

$this->assertDatabaseHas('users', ['name' => 'demo']);
$this->assertSoftDeleted('users', ['id' => 1]);
```

### 为什么默认不用事务回滚（rollback）

laravel 测试与**应用同进程**，测试内开启事务、结束后 `rollBack()` 即可还原所有数据；真实进程模式**测试进程与 server 进程是两个进程**：

- server 进程内的写入（HTTP 接口、CLI 命令触发的业务代码）发生在**它自己的连接**上，不在测试进程的事务里——测试结束回滚不掉
- 因此跨进程 Feature 测试只能用「**清空业务表**」（truncate）隔离：每测试前 `DELETE` 配置表（sqlite 顺带重置 `sqlite_sequence` 自增）

### 三种隔离模式（setUpDatabase 第二参数 $isolation）

| 模式 | 场景 | 行为 |
|---|---|---|
| `truncate`（默认） | Feature 测试（走真实 server 进程） | 迁移 + 注入连接 + 每测试清空 `database.truncate` 配置的业务表 |
| `transaction` | 单进程 unit 测试（不走 server 进程） | 迁移 + 注入连接 + 开启事务，**tearDown 自动回滚**（组件 TestCase 已内置，手动 `rollBackDatabase()` 亦可） |
| `memory` | 单进程 unit 测试（sqlite） | 每测试全新 `:memory:` 库 + 迁移（每测试重新迁移，绕过进程级一次性标记），天然隔离 |

```php
// Feature 基类（默认 truncate，跨进程安全）
$this->setUpDatabase(['sqlite' => 'testing.sqlite']);

// unit 测试基类：仅测试进程写库时，事务回滚/内存库都可用
$this->setUpDatabase(['sqlite' => 'testing.sqlite'], 'transaction');
// 或
$this->setUpDatabase(['sqlite' => 'testing.sqlite'], 'memory');
```

- `transaction`/`memory` 仅在**单进程数据库访问**（unit 测试）下可靠：断言连接与 Eloquent 经被测应用 `support\Db` **同源**（同一 PDO，组件默认下游已安装 webman/database），回滚/内存库才能覆盖应用写入
- `memory` 模式经 `support\Db::connection()->setPdo()` 切换 Eloquent 连接（含迁移目标），**每测试全新库**
- 应用侧 TestCase 覆写 `tearDown()` 时记得 `parent::tearDown()`，否则事务不会自动回滚、`memory` 模式切换的 Db 连接也不会恢复（`restoreDatabaseConnection()` 亦可手动调用）

**testing 配置**（应用侧 `config/testing.php` 的 `database` 段，测试进程与 webman 进程同一文件）：

```php
'database' => [
    // 迁移器默认 phinx（复用应用根目录 phinx.php，测试进程 cwd 即应用根）；
    // 自定义迁移器：'migrator' => fn() => ... / MyMigrator::class / 实例
    'phinx' => ['configFile' => 'phinx.php', 'environment' => 'development'],
    'truncate' => ['users'], // truncate 模式每测试数据隔离要清空的业务表
],
```

**数据库配置同样建议 env 化**（与端口同一模式，见「测试环境切换」章节）：应用侧 `config/database.php` 的连接/文件路径读应用自定义 env，测试时由 `phpunit.xml` 注入切换到 sqlite 文件库，测试进程 DSN 与 server 侧 `runtime_path()` 同源定位同一文件。

- **Feature 测试 sqlite 必须文件库**：`:memory:` 只存在于 server 进程内，测试进程连不上（`memory` 隔离模式只用于不走 server 进程的 unit 测试）
- **phinx 0.16 sqlite 坑**：adapter 会给 name 追加 `.sqlite3` 后缀，应用 `phinx.php` 应传 `connection`（PDO）保证迁移库与 `config/database.php` 连接的是同一文件
- 不依赖迁移时也可手动 `setDatabaseConnection(new PDO(...))` 直连断言
- 表名/列名白名单校验 + bindValue 绑定，传参无需转义

## CLI 命令测试

```php
$this->webmanCommand('e2e:hello', 'e2e')
    ->assertOk()
    ->expectsOutput('hello e2e');

$this->webmanCommand('e2e:fail')->assertFailed()->assertExitCode(1);
```

`webmanCommand` 不启动 HTTP server，只执行 `php <command> <args>`。

## 测试环境切换（应用侧 env 化）

测试/业务环境切换统一走「**应用侧配置读自定义 env + `phpunit.xml` 注入**」模式：

- **env 变量名由应用自行定义**——webman 官方骨架没有预置任何测试 env（`APP_PORT`、`DB_CONNECTION` 均为示例命名，可按需改为 `TEST_PORT`、`TEST_DB` 等）
- **链路**：phpunit.xml 设置 → 测试进程环境 → server 子进程继承 → 应用配置读取（组件与 webman 进程读同一份配置，不关心切换方式）
- **业务零影响**：业务运行时不设置 env，配置回退业务值（端口 8787、数据库 mysql 等）

**端口**（组件不配置 host/port，与应用同源读 `config('process.webman.listen')`）：

```php
// config/process.php
'listen' => 'http://0.0.0.0:' . (getenv('APP_PORT') ?: 8787),
```

```xml
<!-- phpunit.xml -->
<env name="APP_PORT" value="18787"/>
```

**数据库**（连接切到测试库，如 sqlite 文件库）：

```php
// config/database.php
return [
    'default' => getenv('DB_CONNECTION') ?: 'mysql',
    'connections' => [
        'mysql' => [/* 业务连接 */],
        // 测试用文件库：与 webmanRuntimePath() 同源定位（runtime 下）
        'sqlite' => ['driver' => 'sqlite', 'database' => runtime_path() . '/e2e.sqlite'],
    ],
];
```

```xml
<!-- phpunit.xml -->
<env name="DB_CONNECTION" value="sqlite"/>
```

**应用 get_env() 只读 `$_SERVER` 时**（如 webman-tech/common-utils 的 EnvAttr）：phpunit `<env>` 只写 `putenv` + `$_ENV`，不会进 `$_SERVER`。`<env>` 注入发生在 bootstrap 加载**之前**（Application::run 先 PhpHandler 后 loadBootstrapScript），在应用侧 `tests/bootstrap.php` 顶部回灌一份即可，**无需 `<server>` 双写**：

```php
// tests/bootstrap.php（在加载应用 vendor/autoload 与 support/bootstrap.php 之前）
foreach ($_ENV as $name => $value) {
    $_SERVER[$name] = $value;
}
```

server 子进程（php start.php start）经 putenv 继承同一份 env，测试进程与 server 进程读到一致的测试配置。

## 测试环境配置

应用侧 `config/testing.php` 维护组件配置（webman 进程内 `config('testing')` 同源）：

```php
return [
    'httpClient' => [
        'timeout' => 10,          // 请求超时（默认 10）
        'connect_timeout' => 2,   // 连接超时（默认 2）
    ],
];
```

- 默认自动发现 guzzle（`composer require guzzlehttp/guzzle --dev` 即用）；自定义 PSR-18 客户端用 `Server::setHttpClient()` 注入
- 4xx/5xx 由断言层处理（http_errors 恒 false），请求不需要 try/catch
- 自定义客户端必须保证 4xx/5xx 不抛异常，否则断言层拿不到错误响应

## 插件包 e2e 搭建（e2e-setup）

为插件包搭建真实 e2e 环境（`vendor/bin/e2e-setup`：init/install/sync）的完整用法已拆分为独立 skill：**[webman-tech-e2e-setup](../webman-tech-e2e-setup/SKILL.md)**（应用定义类写法、常见坑），此处不再赘述。

## 常见错误

| 错误 | 原因 | 解决 |
|------|------|------|
| server 启动失败 / already running | 残留进程占用端口 | 组件启动前会自动清理残留（pid 文件 + 孤儿进程），确认没有手动起的 webman 或并发测试 |
| 两个测试进程同时跑同一应用 | 另一进程把 server 视为残留清理掉 | 同一 appDir 的测试不要并行执行（IDE 与命令行避免同时跑） |
| 数据库断言查不到数据 | sqlite 用了 `:memory:` | 改用文件库，DSN 用 `webmanRuntimePath()` 定位 |
| 等不到 crontab 副作用 | 跨分钟边界 | 等待超时至少 `60 - date('s') + 10` |
| 请求 4xx/5xx 抛异常 | 自定义客户端未关 http_errors | 自定义 PSR-18 客户端保证 4xx/5xx 不抛异常 |
| 配置改了不生效 | 修改了错误的配置文件 | 组件配置只读应用侧 `config/testing.php`，显式传参优先级最高 |
