## E2eSetup 子域说明

框架无关的 e2e 应用安装编排（e2e-setup 工具）：为被测包搭建「真实骨架 + 真实进程」的集成测试环境（create-project → patch composer.json → update → reinstall → sync 自有代码）。
暂驻 testing 组件 `src/E2eSetup/`，按可独立拆出设计；本文件与 `README.md` 为该子域的独立文档（根目录文档只做引导）。

## 设计约束（改动前必读）

- **框架无关**：不 import 组件其他域（Server/TestCase 等）、不引入 webman/laravel 依赖，仅依赖 php + symfony/process + symfony/console；webman/laravel 内容仅以 stubs 样例出现
- **命令用 symfony/console 标准组件**（勿手写参数解析/usage）：命令装配在 `Console::run`（Application + ArgvInput + addCommands 批量注册，勿用已弃用的 add()），命令类继承 `Symfony\Component\Console\Command\Command`，参数经 addArgument/addOption 声明；测试注入 runner 经命令类构造参数透传 Installer
- **单一校验源**：`Definition::normalize` 是定义数组的唯一校验/规范化入口；SetupConfig/AppConfig 只做「类写法 → 定义数组」转换（toArray），不产生第二套校验
- **定义数据流**：定义文件（e2e/e2e-setup.php）返回 SetupConfig 实例或数组 → `Installer::fromConfigFile` 统一转数组 → `Definition::normalize` → AppDefinition/PackageDefinition DTO（下游一律命名属性访问，不用数组 key 魔法字符串）

## 目录结构（命名空间与目录一一对应）

- `Console.php`（根入口，`WebmanTech\Testing\E2eSetup\Console`）：symfony/console 装配（命令注册、setAutoExit(false)、ArgvInput）；E2eSetup 根目录仅保留本入口与 SetupConfig
- `SetupConfig.php`（根入口）：定义文件类写法入口（rector.php 风格），`app()` 聚合 AppConfig；`toArray()` 转定义数组
- `Command/`：InstallCommand / SyncCommand / InitCommand（命令名 install / sync / init）
- `Installer/Installer.php`：安装编排（create-project/本地骨架复制 → patch composer.json → update → reinstall_packages → sync），定义经 AppDefinition DTO 命名属性访问
- `Definition/`：Definition（校验与规范化）+ AppDefinition/PackageDefinition（readonly DTO）+ AppConfig（类写法单应用配置）
- `stubs/`：init 脚手架模板（e2e-setup.php 定义模板 + webman/laravel 样例；phpstan excludePaths 排除——框架类在被测应用里才存在，移动目录需同步 phpstan.neon）

## 命令与入口

- bin：`bin/e2e-setup`（composer bin 声明；根包自身不生成 vendor/bin 代理，scripts 用 `php bin/e2e-setup`，依赖场景为 `vendor/bin/e2e-setup`）
- 命令：
  - `install [app] [--vcs] [--config 文件]`：完整安装
  - `sync [app] [--config 文件]`：仅同步自有代码（dev 快速迭代）
  - `init [--framework=webman|laravel] [--dir=目录]`：生成脚手架
- 定义文件默认 `{cwd}/e2e/e2e-setup.php`；相对路径基于定义文件所在目录解析；`--vcs` 时被测包经 GitHub VCS dev-main 安装

## 测试方式

- 单测（`tests/Unit/E2eSetup/`）：ConsoleTest（命令装配/init 产物/定义文件加载/错误路径）、SetupConfigTest（类写法 → 定义数组转换与默认值语义）、DefinitionTest（校验/默认值补齐/路径解析/本地骨架判定）、InstallerTest（stub runner 注入验证命令序列与 composer.json patch，不执行真实命令）
- 自举验证：本仓库 `e2e/` 应用即本工具的消费方（`composer e2e:install` 重建 + `composer e2e:test` 跑真实 webman 链路）；行为改动后必须跑
- 命令冒烟：`php bin/e2e-setup list`（命令分组渲染）、`-V`（工具名）、`init` 产物可被 `Installer::fromConfigFile` 直接消费

## 注意事项

1. 命令名/入口名一致性：命令名（install/sync/init）、bin 名（e2e-setup）、`Console::NAME` 需同步；改动时全仓 grep 更新（stubs 注释、Installer 错误文案、根 README/AGENTS、skill）
2. 本子域文档自含（本文件 + README.md），根目录文档只做引导；新增能力时保持此边界
3. 错误文案与模板注释中的命令示例须与实际命令一致（如 `vendor/bin/e2e-setup install`）
