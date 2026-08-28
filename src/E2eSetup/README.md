# e2e-setup：真实骨架 e2e 环境搭建工具

为被测包搭建「真实骨架 + 真实进程」的集成测试环境：`composer create-project` 最新骨架（webman/laravel）→ 注入被测包（path repository 即时生效，或 GitHub VCS）→ 安装依赖 → 同步自有代码（config/tests/phpunit.xml）。

- **框架无关**：webman、laravel 及任意 composer 骨架通用
- **仅依赖 php + symfony/process + symfony/console**：随 testing 组件分发，按可独立拆出设计
- **多应用**：一个定义文件可声明多个骨架应用，install/sync 指定应用名分别操作

## 安装

依赖场景（`composer require webman-tech/testing` 后）：

```bash
vendor/bin/e2e-setup --help
```

根包场景（仓库自身开发）：composer scripts 已封装（`composer e2e:install` / `e2e:sync` / `e2e:vcs`，等价 `php bin/e2e-setup install` 等）。

## 快速开始

```bash
# 1. 生成脚手架（默认 webman 样例；laravel 用 --framework=laravel）
vendor/bin/e2e-setup init

# 2. 编辑 e2e/e2e-setup.php：声明应用、被测包与依赖
# 3. 完整安装（删除重建，基于最新官方骨架）
vendor/bin/e2e-setup install

# 4. 写测试（e2e/app-src/tests/ 下，继承 testing 组件 TestCase）后运行
cd e2e/webman && vendor/bin/pest
```

`init` 生成：`e2e/e2e-setup.php`（应用定义）+ `e2e/app-src/`（自有代码骨架：config/tests/phpunit.xml，install 时覆盖式同步到应用；生成物 `e2e/<target_dir>` 应加入 `.gitignore`）。dev 迭代改 `app-src/` 后只需 `vendor/bin/e2e-setup sync`。

## 详细用法

命令参数（`--vcs` / `--config` / 多应用）、应用定义类写法（SetupConfig/AppConfig 方法表）、典型流程（webman 插件包 / laravel 包 / 发布链路验证）与常见坑见 **[skills/webman-tech-e2e-setup](../../skills/webman-tech-e2e-setup/SKILL.md)**；该子域的开发维护说明（设计约束/目录结构/测试方式）见 [AGENTS.md](AGENTS.md)。

## 与本仓库的关系

本仓库 `e2e/` 即本工具的自举消费方：`composer e2e:install`（`php bin/e2e-setup install`，定义见 `e2e/e2e-setup.php`）重建真实 webman 骨架应用，`composer e2e:test` 在其中跑组件全链路测试。
