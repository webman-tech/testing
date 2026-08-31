# webman-tech/testing

webman 的真实进程测试组件：以「真实 webman 进程 + HTTP 请求」为底座，提供 laravel 风格的测试体验。

## 简介

webman 是常驻内存的进程模型，无法在测试进程内模拟请求。本组件启动**真实 webman 进程**，以 HTTP 请求覆盖完整链路（路由、中间件、异常管线、插件进程、crontab 调度）；CLI 命令与数据库/副作用断言同样是一等公民。断言基于 `PHPUnit\Framework\Assert`，pest / phpunit 双兼容。

## 安装

```bash
composer require webman-tech/testing --dev
```

推荐同时安装（组件自动发现，不强制依赖）：

```bash
composer require guzzlehttp/guzzle --dev  # PSR-18 HTTP 客户端
composer require pestphp/pest --dev       # 测试框架
```

## 快速开始

在 `tests/Pest.php` 绑定基类（phpunit 风格直接继承 `WebmanTech\Testing\TestCase`，方法相同）：

```php
use WebmanTech\Testing\TestCase;

pest()->extend(TestCase::class)->in('.');
```

```bash
vendor/bin/pest    # 或 vendor/bin/phpunit
```

首个测试自动触发 server 启动，整个测试进程共享复用，结束自动停止。测试写法与示例（认证、重定向、等待异步副作用、数据库断言、CLI 命令、测试环境切换）见 **[skills/webman-tech-testing-best-practices](skills/webman-tech-testing-best-practices/SKILL.md)**；组件配置（应用侧 `config/testing.php`）与 PSR-18 客户端注入见该文档「测试环境配置」章节。

## 为插件包搭建 e2e 测试（e2e-setup）

本包内置**框架无关的 e2e 安装编排工具**（`vendor/bin/e2e-setup`）：为 webman/laravel 插件包一键搭建「真实骨架 + 真实进程」的集成测试环境（create-project → patch composer.json → composer update → reinstall → sync 自有代码）。命令、应用定义类写法（SetupConfig/AppConfig）、典型流程与常见坑见 **[skills/webman-tech-testing-best-practices/references/e2e-setup.md](skills/webman-tech-testing-best-practices/references/e2e-setup.md)**；子域文档（定位/快速开始/自举关系）见 `src/E2eSetup/README.md`。

## 文档导航

- **使用最佳实践**：[skills/webman-tech-testing-best-practices](skills/webman-tech-testing-best-practices/SKILL.md) — 写 HTTP 测试、认证与状态隔离、等待异步副作用、数据库断言、CLI 命令测试、测试环境切换的推荐写法与常见坑
- **e2e 搭建**：[skills/webman-tech-testing-best-practices/references/e2e-setup.md](skills/webman-tech-testing-best-practices/references/e2e-setup.md) — 最佳实践 skill 的 reference：插件/扩展包的骨架 e2e 环境搭建（命令、应用定义方法表、典型流程与常见坑；大多数完整 webman 项目无需使用）
- **开发维护**：[AGENTS.md](AGENTS.md) — 面向 AI 的代码结构与开发规范（设计边界、时序陷阱、测试方式）
