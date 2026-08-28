# webman-tech/testing

webman 的真实进程测试组件：以「真实 webman 进程 + HTTP 请求」为底座，提供 laravel 风格的测试体验。

## 简介

webman 是常驻内存的进程模型，无法像 laravel 那样在测试进程内模拟请求。本组件启动**真实 webman 进程**，以 HTTP 请求覆盖完整链路（路由、中间件、异常管线、插件进程、crontab 调度），CLI 命令与副作用断言同样是一等公民。断言基于 `PHPUnit\Framework\Assert`，pest / phpunit 双兼容。

## 功能特性

- **Server 进程编排**：自动启动、就绪等待、停止与残留清理，整个测试进程共享复用；监听地址与 webman 应用天然一致（组件与 webman 同一读取方式——config('process.webman.listen')，不配置 host/port）
- **laravel 同款 API**：`TestCase` 按 Concerns 组合（MakesHttpRequests / InteractsWithAuthentication / InteractsWithConsole / InteractsWithDatabase / InteractsWithServer），方法签名对齐 laravel
- **laravel 风格 TestResponse**：状态码/header/redirect/cookie/文本/JSON 全套链式断言，`json()` 支持 dot 路径取值
- **非 HTTP 测试一等公民**：CLI 命令断言、副作用轮询、数据库直连断言
- **配置即用**：应用侧 `config/testing.php` 共享配置；PSR-18 客户端自动发现（安装 guzzle 即用，可注入自定义）

## 安装

```bash
composer require webman-tech/testing --dev

composer require guzzlehttp/guzzle --dev  # PSR-18 HTTP 客户端（推荐）
composer require pestphp/pest --dev  # pest 测试框架（推荐）
```

如需定制配置，在被测应用的 `config/testing.php` 中维护（见下文「配置」）。

HTTP 请求仅依赖 PSR 标准接口（psr/http-client + psr/http-message），不绑定任何实现：安装 guzzle 后自动发现并使用；未安装时请求会抛可读异常提示。也可以调用 `Server::setHttpClient()` 注入自定义 PSR-18 客户端（见下文）。

## 快速开始

### 1. 端口分离（业务端口与测试端口互不影响）

组件**不关心也不配置** host/port：以与 webman 相同的方式读取被测应用 `config/process.php` 中 webman 进程的 listen 配置（`config('process.webman.listen')`——测试进程加载应用 vendor/autoload.php 时，webman-framework 的 helpers 已自动加载），天然与应用进程一致。

「测试用独立端口」是应用侧的处理模式（可选）：给 `config/process.php` 中 listen 的 port 进行 env 化，业务运行时不设置环境变量保持原端口（默认 8787），测试时由 `phpunit.xml` 注入切换：

```php
// config/process.php
'listen' => 'http://0.0.0.0:' . (getenv('APP_PORT') ?: 8787),
```

```xml
<!-- phpunit.xml -->
<php>
    <env name="APP_PORT" value="18787"/>
</php>
```

链路：phpunit.xml 设置 → 测试进程环境 → server 子进程（继承环境）→ process.php 读取；组件与 webman 进程内读取同一份配置（config('process.webman.listen')），两端读到同一地址。不 env 化也可以：组件读到的就是业务端口，测试直接使用该端口。

### 2. 写测试（pest 风格）

在 `tests/Pest.php` 中绑定基类（laravel 骨架同款机制）：

```php
use WebmanTech\Testing\TestCase;

pest()->extend(TestCase::class)->in('.');
```

之后测试闭包内直接使用 `$this->` 语法：

```php
test('health', function () {
    $this->get('/health')->assertOk()->assertJson(['status' => 'ok']);
});

test('登录后可获取用户', function () {
    $token = $this->postJson('/auth/login')->json('access_token');

    $this->withToken($token)
        ->getJson('/auth/user')
        ->assertOk()
        ->assertJson(['name' => 'demo']);
});
```

phpunit 风格则继承 `WebmanTech\Testing\TestCase`，方法完全相同（`$this->getJson()` / `$this->postJson()` / `$this->withToken()` ...）。

### 3. 运行

```bash
vendor/bin/pest    # 或 vendor/bin/phpunit
```

首个测试自动触发 server 启动，整个测试进程共享复用，结束自动停止。

## AI 辅助

- **开发维护**：[AGENTS.md](AGENTS.md) — 面向 AI 的代码结构和开发规范说明
- **使用最佳实践**：[skills/webman-tech-testing-best-practices](skills/webman-tech-testing-best-practices/SKILL.md) — 面向 AI 辅助用户正确使用组件（推荐写法、常见坑）
