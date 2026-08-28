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

测试进程**直连数据库**断言（PDO），与 server 进程共享同一数据源：

```php
$this->setDatabaseConnection(new PDO('sqlite:' . $this->webmanRuntimePath('e2e.sqlite')));
$this->assertDatabaseHas('users', ['name' => 'demo']);
$this->assertSoftDeleted('users', ['id' => 1]);
```

**数据库配置同样建议 env 化**（与端口同一模式，见「测试环境切换」章节）：应用侧 `config/database.php` 的连接/文件路径读应用自定义 env，测试时由 `phpunit.xml` 注入切换到 sqlite 文件库，测试进程 DSN 与 server 侧 `runtime_path()` 同源定位同一文件。

- **sqlite 必须文件库**：`:memory:` 只存在于 server 进程内，测试进程连不上
- DSN 用 `webmanRuntimePath()` 拼接，保证两进程指向同一文件（与 server 侧 `runtime_path()` 同源）
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
