# 插件包 e2e 搭建（e2e-setup）

> Reference：本文件是 [webman-tech-testing-best-practices](../SKILL.md) 的按需查阅材料，仅在「被测对象是要分发给其他项目的插件/扩展包」时读取。大多数项目是完整的 webman 应用，直接在项目内写测试即可，**不需要** e2e-setup。

e2e-setup 是随 testing 组件分发的框架无关安装编排工具（`vendor/bin/e2e-setup`，实现见 `src/E2eSetup/`）：为被测包搭建「真实骨架 + 真实进程」的集成测试环境（create-project 最新骨架 → 注入被测包 → 安装依赖 → 同步自有代码），无需复制安装脚本。

## 命令速查

```bash
vendor/bin/e2e-setup init [--framework=webman|laravel] [--dir=目录]  # 生成 e2e/e2e-setup.php + e2e/app-src/ 脚手架
vendor/bin/e2e-setup install [app] [--vcs] [--config 定义文件]       # 完整安装（删除重建，基于最新官方骨架）
vendor/bin/e2e-setup sync [app] [--config 定义文件]                  # 仅同步自有代码（dev 快速迭代）
```

- `--vcs`：被测包改经 GitHub VCS dev-main 安装（url 由包名推断；**需先推送 main**）
- `--config`：自定义定义文件（默认 `{cwd}/e2e/e2e-setup.php`）；**相对路径基于定义文件所在目录解析**
- 多应用：定义文件声明多个应用时，`install`/`sync` 必须指定 `[app]` 分别操作
- 根包场景（仓库自身开发）：composer scripts 封装 `composer e2e:install` / `e2e:sync` / `e2e:vcs`（等价 `php bin/e2e-setup install` / `sync` / `install --vcs`）

## 应用定义（e2e/e2e-setup.php）

rector.php 风格类写法：`SetupConfig::configure()->app(应用名, AppConfig::configure()->...)`（旧数组写法兼容）：

```php
return SetupConfig::configure()
    ->app('webman', AppConfig::configure()
        ->skeleton('workerman/webman')        // 必填：骨架包名（或本地骨架目录路径）
        ->targetDir('webman')                 // 必填：应用生成目录
        ->srcDir('app-src')                   // 必填：自有代码同步源
        ->package('vendor/your-package')      // 被测包（可多次调用）：不传 path 默认项目根
        ->require([...])                      // 额外依赖（默认空、零内置）
        ->requireDev([...])
        ->requireOverride([...])              // 覆盖骨架既有依赖（版本冲突时用）
        ->reinstallPackages([...])            // 需 Install 落地的包（webman 插件场景）
    );
```

| 方法 | 说明 |
|----|------|
| `skeleton(包名, ?版本)` | 骨架包名（`workerman/webman`、`laravel/laravel`）或本地骨架目录路径（存在则复制代替 create-project）；第二参钉版本（laravel 场景推荐 `^12.0` 钉主版本） |
| `targetDir` / `srcDir` | 应用生成目录 / 自有代码同步源（相对路径基于定义文件所在目录） |
| `package(name, ?path, ?vcs, ?version)` | 被测包，可多次调用累积：`path` 显式本地目录（支持目录或 glob，多包同 path 自动合并为单条 repository）；`vcs` 远程仓库；都不传默认 path=项目根（path repository + symlink，改动即时生效）；version 默认 `dev-main` |
| `require` / `requireDev` | 额外依赖——**零内置**：pest、guzzle 等必须显式声明 |
| `requireOverride` | 覆盖骨架既有依赖（如 monolog 版本冲突） |
| `reinstallPackages` | 需 Install 落地的包（默认空）：批量 composer update 不触发包内 Install，webman 插件场景必须列出 |

## 典型流程

**webman 插件包（默认）**

1. `init` 生成脚手架（默认 webman 样例）
2. 编辑 `e2e/e2e-setup.php`：
   - `package()` 声明被测包（不传 path 即指向项目根）
   - `requireDev` 至少补 pest + guzzle
   - `reinstallPackages` 至少 `['webman/console']`（webman 插件需要 CLI 入口与 `config/plugin/<package>/` 模板的再加 `webman/database` 等）
3. `install` 完整安装
4. 在 `e2e/app-src/tests/` 写测试（继承 testing 组件 TestCase 即可用最佳实践 skill 的全部写法）
5. dev 迭代：改 `app-src/` 后只需 `sync`，不必重装

**laravel 包**

1. `init --framework=laravel`（app-src 骨架同款）
2. 定义补 `skeleton('laravel/laravel', '^12.0')` 钉主版本；被测包 ServiceProvider 注册在 laravel 11+ 的 `bootstrap/providers.php`（写在 app-src 里随 sync 落地）
3. `install`；测试执行用骨架自带 PHPUnit（Pest 风格需补 `pestphp/pest-plugin-laravel`）

**发布链路验证（--vcs）**

1. 推送被测包 `main`
2. `install --vcs`（path repository 切 GitHub VCS dev-main，验证「发布后安装」真实链路）
3. 回归本地开发：不带 `--vcs` 重装（path symlink，改动即时生效）

## 常见坑

| 问题 | 原因 | 解决 |
|------|------|------|
| 安装后 webman CLI 命令/配置模板缺失 | `reinstallPackages` 列表不全 | 补包名（webman/console、webman/database 等）重跑 `install` |
| `--vcs` 报 404 / 找不到 dev-main | 被测包 main 未推送 | 先推送 main 再 install |
| e2e 应用起不来 / 依赖版本异常 | pest ^3 与 laravel 骨架自带 phpunit ^12 冲突 | 按需取舍：骨架自带 PHPUnit 或补 pest-plugin-laravel 并显式声明依赖 |
| sync 后目标文件还在 | sync 是覆盖式复制，删除源文件不会同步删除 | 手动删除目标文件，或重跑 install |
| 本地对 `e2e/<target_dir>` 的手动改动丢失 | install 是删除重建 | 自有代码一律放 `app-src/`，不要直接改生成物 |
| 生成物误提交 | 未忽略生成目录 | `e2e/<target_dir>` 加入 `.gitignore`（可抛弃，install 基于最新骨架重建） |
| create-project 卡住/超时 | 网络问题 | 配置 composer 镜像或重试 |
| 多应用 install 报未知应用 | 未指定 `[app]` | install/sync 时显式传应用名 |

## 文档

- 入门引导（定位/快速开始/自举关系）：`src/E2eSetup/README.md`
- 开发维护（设计约束/目录结构/测试方式）：`src/E2eSetup/AGENTS.md`
