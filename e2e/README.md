# E2E 测试（真实 webman 环境验证）

与仓库单测（进程内构造假体）互补，e2e 在**真实的 webman 官方骨架**中验证 webman-tech/testing 组件的实际行为：

- **最新骨架完整性**：每次 `composer create-project workerman/webman` 最新版，不 fork 任何骨架文件（自有代码全部在 `webman-src/` 提交），骨架升级零成本
- **真实进程链路**：Server 进程编排（启动/就绪等待/停止/残留清理）、PSR-18 请求发送、重定向跟随逻辑（PSR-18 无自动重定向，为组件手动实现）
- **监听地址链路**：组件不配置 host/port——以与 webman 相同的方式读取 `config('process.webman.listen')`（helpers 由 composer autoload.files 自动加载，配置数据在读取前由 ensureConfigLoaded 自动加载应用 config 目录；e2e 的 process.php 含 Log::channel/app_path 等 webman 专属写法，验证真实骨架零改造可读）；业务端口保持骨架默认 8787（用户现有部署零影响），测试时由 phpunit.xml 注入**应用自定义 env** 切换（e2e 示例 `APP_PORT`，变量名由应用自定：测试进程环境 → server 子进程继承 → process.php 读取，默认 18787），两端读到同一地址
- **应用侧共享配置**：`config/testing.php` 由测试进程与应用进程同源读取（httpClient 等参数）
- **数据库 env 链路**：应用侧 `config/database.php` env 化（业务默认 mysql，phpunit.xml 注入 `DB_CONNECTION=sqlite` 切文件库，与 APP_PORT 同一模式）；应用进程经 webman/database（support\Db）操作 sqlite 文件库，测试进程 PDO 直连同一文件断言
- **跨进程能力**：数据库断言（sqlite 文件库，测试进程 PDO 直连 server 同源文件）、CLI 命令断言、副作用轮询等待

## 目录结构

```
e2e/
├── setup.php                 # 安装命令（create-project → patch → update → sync）
├── README.md
└── webman-src/               # 提交：自有代码（config 覆盖/app 演示/tests）
# 生成物（.gitignore 忽略，可抛弃）：e2e/webman
```

## 命令

```bash
# 完整安装（删除重建，create-project 最新版 webman 骨架）
composer e2e:install
# 等价：php e2e/setup.php webman

# 仅同步自有代码（dev 快速迭代：改了 webman-src 后执行）
composer e2e:sync
# 等价：php e2e/setup.php webman --sync

# 运行测试
composer e2e:test
# 等价：cd e2e/webman && vendor/bin/pest

# 完整 e2e（install + test）
composer e2e

# testing 组件经 GitHub VCS dev-main 安装（验证真实发布链路，需先推送 main）
composer e2e:vcs
# 等价：php e2e/setup.php webman --vcs
```

默认（不带 `--vcs`）testing 组件经 **path repository 引用当前仓库代码**（symlink），本地/CI 直接验证当前 checkout，改动即时生效。

## 安装流程（顺序关键）

1. `composer create-project workerman/webman`（最新版骨架）
2. patch composer.json：testing 组件 repository（path 指向本仓库 / `--vcs` 切 GitHub VCS dev-main）+ 测试依赖（pest / webman/console / guzzle / webman/database / workerman/crontab）
3. `composer update`（COMPOSER_ROOT_VERSION=dev-main 兜底 detached HEAD 的版本解析）
4. `composer reinstall webman/console webman/database`（触发 Install 落地 webman CLI 入口与 config/database.php 模板；批量 update 时包内 Install 不触发）
5. copy `webman-src/` 到应用目录（覆盖式，自有 config 覆盖在骨架默认配置之后生效）

## 覆盖点

| 测试文件 | 验证内容 |
|----------|----------|
| Feature/ServerBootTest | 进程启动 /health、404、重定向默认不跟随（302 + Location）、followingRedirects 跟随、303 POST 转 GET、监听地址链路（组件经 config('process.webman.listen') 读取 listen：phpunit.xml 注入应用自定义 env（e2e 示例 APP_PORT=18787）→ 组件与应用进程同址，业务模式保持 8787）、数据库 env 链路（phpunit.xml 注入 DB_CONNECTION=sqlite → 应用进程 config('database.default') 读到 sqlite，业务默认 mysql）、应用侧 httpClient 配置生效 |
| Feature/HttpDataTest | POST JSON 201 + assertJsonStructure、422 参数校验、GET 列表 count/逐条断言、reset 端点模式 |
| Feature/AuthTest | login 签发 token、withToken 建立登录态、无/无效 token 401 |
| Feature/CommandTest | webmanCommand 成功/失败命令：assertOk/expectsOutput/assertFailed/assertExitCode |
| Feature/DatabaseTest | 经 webman/database（support\Db）写库 + sqlite 文件库跨进程断言（HTTP/CLI 写入 → assertDatabaseHas/Count/SoftDeleted） |
| Feature/SideEffectTest | crontab 进程随 server 启动、副作用文件增长、webmanWaitFor 跨分钟等待 |

## dev 迭代

- 改本仓库 `src/`：path symlink 使改动即时生效，直接重跑 `composer e2e:test`
- 改 `e2e/webman-src/`：先 `composer e2e:sync` 再跑 `composer e2e:test`
- 验证发布链路（`--vcs`）：先推送 main 再完整重建（`composer e2e:vcs` + `composer e2e:test`）
