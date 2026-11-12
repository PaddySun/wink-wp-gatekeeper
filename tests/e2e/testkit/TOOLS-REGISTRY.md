# TOOLS-REGISTRY — 共用测试工具箱登记表（唯一来源）

> 【入库脱敏版（B7 收线 2026-11-11）】本文与脚本以占位符入库：`<TEST_HOST>`=测试机 IP / `<SSH_PORT>`=SSH 真口 / `<SSH_KEY_PATH>`=本机私钥路径 / `<WP_ADMIN_USER>`=后台用户名（对照表+本机填回用法见同目录 `README-SANITIZED.md`）。`evidence/`（真机证据快照 113 文件）与 `*snap*.json`（含凭据）**不入库**（.gitignore 排除）。

位置：内层仓库 `tests/e2e/testkit/`（本目录）。规则（06 §3）：先复用后造轮；新工具必登记；工具只读厦门机；输出永不打印密钥值。

## 站点切换注记（2026-11-10，全工具生效）

- **目标站不变**：`https://test.paddysun.top`（--resolve <TEST_HOST>，.env 域名未动）。
- **容器前缀切换**：`wptest-*` → `wpclean-*`（wp/db/redis）。环境已从「全量生产克隆站」重建为「干净最小站」：**全新 WP 7.1.3 安装**（非克隆，无生产内容/插件）+ 生产主题 paddysun-newsprint + redis-cache 3.0.0（object-cache drop-in，Connected）。目录 `/opt/wpclean`（数据卷 `wpclean_db-data`）；原站 `/opt/wptest` 已 `compose down` 封存（卷/资产保留，只读，wp-cli.phar 自愈源仍在 `/opt/wptest/incoming/`）。
- **靶文 ID 变更**：克隆站靶文作废；新靶文 **ID=4**，URL `https://test.paddysun.top/archives/4`（publish、comment_status=open），已持久化于 option `wink_u2_target_post`（工具继续读 option，勿硬编码）。
- 干净站无生产插件（wordfence/sync-qcloud-cos 等已不存在）——`--skip-plugins --skip-themes` 不再是硬需求，但 wpsh.sh 保留该参数（无害，防未来插件引入外联阻塞）。
- 新站后台为**独立账密**（非生产克隆）：wp-admin 仅测试用，仍走 wp-cli 优先；凭据见 `docs/reports/SITE-REBUILD-2026-11-10.md`（本地，不入 git）。

## 基础设施事实（U2 实测 2026-11-09，后发 agent 必读）

- **wp-cli 一律 `--skip-plugins --skip-themes`**：生产克隆插件全量加载时 CLI bootstrap 阻塞 >120s（外联超时），web 请求不受影响（首页 0.9s）。
- 插件 slug = `wink-wp-gatekeeper`（zip 顶层目录名），wp-cli slug 解析假定「目录名.php」形态，本插件目录名=文件名前缀，直接可用。
- wp-cli 容器内路径：`docker exec wpclean-wp php /tmp/wp-cli.phar --allow-root --skip-plugins --skip-themes --path=/var/www/html ...`；容器重建自愈 `docker cp /opt/wptest/incoming/wp-cli.phar wpclean-wp:/tmp/`（源文件在封存的原站资产中，原地只读）。
- 靶文 ID 持久于 WP option `wink_u2_target_post`（**publish** + comment_status=open——WP 7.x 对 draft 文章表单评论在 preprocess_comment 之前静默 exit，HTTP 用例必须 publish；U2 实测）。2026-11-10 站点切换后：新靶文 ID=4（`/archives/4`），option 已同步。
- redis-cache dropin 激活（object-cache.php）；跨进程 option 缓存一致性无碍（update_option 同步清缓存）。
- 上游 baby-wp-comment-filter（preprocess_comment @ 10，我方 @ -PHP_INT_MAX 恒先跑）测试期停用防污染（rate_limit 10s + 百度云审核），结束恢复。

## 工具清单

| 工具 | 用途 | 一行示例 | 沉淀 |
|---|---|---|---|
| `wpsh.sh` | SSH/wp-cli 包装：agent 环境恢复、<SSH_PORT> 钥匙参数固化、wp-cli 自愈、base64 eval-file 免转义、push 文件直传 | `./wpsh.sh wp option get siteurl` / `./wpsh.sh b64eval 'echo get_option("admin_email");'` / `./wpsh.sh raw 'uptime'` / `./wpsh.sh push snap.json /tmp/snap.json` | U2 |
| `cfg.sh` | 插件 option 读写/快照/恢复 + WP 核心 option 读写（密钥自动打码；set 带 readback） | `./cfg.sh set review.local.min_length 10` / `./cfg.sh snap snap.json` / `./cfg.sh restore snap.json` / `./cfg.sh wp-set disallowed_keys "词1\n词2"` | U2 |
| `cfg.sh env-sync` | `.env` 天御四字段 → option captcha.tencent（C2 增；密钥本地 grep 后立即 base64 注入远程，回读只报 SET(len=N)/EMPTY，明文不出本机、不进输出） | `./cfg.sh env-sync tencent [env文件路径]`（默认外层 `.env`） | C2 |
| `cfg.sh env-sync aliyun` | `.env` 阿里验证码键（ACCESS_KEY_ID/ACCESS_KEY_SECRET/prefix；SCENE_ID 若有则一并）→ option captcha.aliyun 并切 driver=aliyun（B3 增，同 base64 通道纪律；**尾随  必 trim——C1 先例**；SCENE_ID 缺失打 stderr 提示不阻断） | `./cfg.sh env-sync aliyun [env文件路径]` | B3 |
| `cfg.sh env-sync tms` | `.env` TMS 三键（SECRET_ID/SECRET_KEY/bizType）→ option review.cloud.tencent_tms，并启用云端审核 + 入链（enabled=true + chain=['tencent_tms']；C4 增，同 base64 通道纪律） | `./cfg.sh env-sync tms [env文件路径]` | C4 |
| `comment.php` | 评论提交器（https 443 --resolve / http 18080 Host 头；读返回 HTML 判 intercepted/allowed/error，解析 WK- 编号/规则/标题/wk-ev/back；B2 增 `--stub/--trust/--ticket/--randstr`（或 `*-file` 文件通道——规避 shell 编码/转义）透传闸级/票据字段；B3 增 `--verifyparam/--verifyparam-file`（wk_captcha_verify_param——阿里单票据）） | `php comment.php --post=123 --text="测试" --out=body.html` / `php comment.php --post=4 --text=… --stub-file=stub.txt --trust-file=trust.txt`（B3 真机注：Windows 本机 PHP 无 curl 扩展时用 curl.exe `--data-binary @body.txt`（body 由 python urlencode 落文件——命令行内联中文会被 GBK 控制台搅码→LocalRules no_chinese 误拦假象）） | U2/B2/B3 |
| `login.php` | wp-login 登录尝试器（--page 页面核验 GATE_CONTAINER/STUB_FIELD/LOGIN_ASSET；--try 表单 POST 无票直提，解析 #login_error 错误框文本） | `php login.php --page --out=login.html` / `php login.php --try --user=admin --out=try3.html` | C3 |
| `precheck.php` | 信用免验预检端点测试器（B2 增：GET 靶文页采集 wk_stub/wkGatePrecheck 内联配置/data-wk-trust 标记 → POST `wk_gate_precheck` → 解析三分支 trust_pass/four_gate_fail/captcha_needed + 时延；`--repeat=N` 连打限流验证；`--out` 落 JSON 并吐 TRUST_VALUE 供全链提交） | `php precheck.php --post=4 --author=测试 --email=a@b.c --text=草稿 --repeat=31 --out=ev.json` | B2 |
| `xmlrpc.php` | XML-RPC 调用器（--method 单方法含 system.listMethods 探活；--multicall=N 同凭据 N 组内嵌认证；解析 faultCode/faultString——wk_captcha_unavailable 文案 = 超阈闸断特征） | `php xmlrpc.php --method=wp.getUsersBlogs --user=admin` / `php xmlrpc.php --multicall=3 --user=admin` / `php xmlrpc.php --method=system.listMethods` | C3 |
| `events.sh` | 事件表查询（latest/by-no/by-stage/count，meta JSON 展开） | `./events.sh by-no WK-20261109-0001` / `./events.sh latest 5` | U2 |
| `usage.sh` | 统计/用量 option 读取 | `./usage.sh stats` / `./usage.sh usage` | U2 |
| `assert.php` | 断言：wk-no 正则、eq、contains/not-contains（文件或串）、regex、int-gte、seq（WK 编号连续递增） | `php assert.php wk-no "WK-20261109-0001"` / `php assert.php contains body.html "wk-ev"` | U2 |
| `target-setup.sh` | 环境就绪：status 只读总览 / target 靶文创建（幂等）/ reset 清事件+统计（B2 增：wk_pk_/wk_tc_ 前缀一并双通道清）/ install 全链部署 / zip 构建 | `./target-setup.sh reset` / `./target-setup.sh status` | U2/B2 |
| `TOOLS-REGISTRY.md` | 本登记表 | — | U2 |

### 已知限界（实测）

- **b64eval 远程命令串 >4.5KB 静默截断**（约 4096 边界，Windows ssh 层）：大 JSON/长词表一律走 `wpsh.sh push` 文件通道 + `file_get_contents()`（cfg.sh restore 即此模式）。
- bash 单引号串中 `\\` 会被链路吃成 `\`：含反斜杠的值（如正则 `\d`）用无反斜杠等价形态（`[0-9]`）或走 push 通道。
- **b64eval 内嵌长 needle 字符串可能悄悄变形**（B3 实测 2026-11-10：38 字符含方括号串到达远端变 39 字符，`strpos` 假阴性）：标记检查优先「eval 内 `file_put_contents` 落盘 + 容器内 `grep`」双步，或先校验 `strlen($needle)`。
- `wp eval-file` 为 include 语义：载荷必须以 `<?php` 开头（wpsh.sh 已自动补）；wp-cli 在容器内执行，`file_put_contents("/tmp/…")` 落的是**容器**文件系统（宿主 grep 找不到，需 `docker exec wpclean-wp grep`）。
- **`unzip -o` 覆盖解压不删除已退役文件**（B3 实测 2026-11-10：0.2.2 删除的 tencent-login.js 残留真机直至手删）：升级部署后应 diff 服务器目录与 zip 清单（`find . -type f` 对照），残留退役文件手动 rm。
- `login.php` 的 `LOGIN_ASSET` 键检测的是**已退役**的 `tencent-login.js`（0.2.1 及以前口径）——0.2.2 起恒 `no` 属预期；登录页资产断言改用 `--out` 落盘 body 后 grep `gatekeeper.js` / `drivers/tencent.js`。
- 本机 PHP 无 curl 扩展：调用形如 `php.exe -d extension=curl -d extension_dir=<php目录>/ext comment.php ...`。

## 环境常量（工具内置）

- SSH：`root@<TEST_HOST>:<SSH_PORT>`，密钥 `<SSH_KEY_PATH>`（陷阱口 48490 绝对不碰）
- 本机 PHP：`PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`（`PHP_BIN` 环境变量可覆盖）
- 测试站：`https://test.paddysun.top`（--resolve <TEST_HOST>）/ `http://<TEST_HOST>:18080`（Host 头）

## 出境代理（mihomo，2026-11-13 落地——后续 agent 必读）

- **能力**：厦门机测试站具备境外出站（此前无国际出口，境外 API 真机只能验 fail-open 链）。mihomo v1.19.34 + 订阅节点（30 节点，vmess-ws，rule 模式 **CN 直连分流**——腾讯 TMS/阿里/百度等境内云审不受影响，仅境外走代理）。
- **落点**：`/root/mihomo/`（mihomo 二进制+config.yaml+sub.yaml+country.mmdb+geosite.dat；目录 700；**订阅凭据只在机器本地，不入任何文档/仓库**）；systemd 单元 `mihomo.service`（Restart=on-failure，开机自启）。
- **接入形态**：WP 原生代理——wp-config.php `WP_PROXY_HOST=172.18.0.1` + `WP_PROXY_PORT=7890`（备份 `wp-config.php.bak-20261113`）；一切 `wp_remote_*`（含 RemoteClient/诊断面板四源）自动走代理。mihomo 仅绑 `172.18.0.1:7890`（容器网关位；**host 侧 curl 测试也须 `-x http://172.18.0.1:7890`，127.0.0.1 已不监听**）。
- **元数据坑**：mihomo 首启卡 GeoIP 下载（GitHub 被墙）——meta 文件已补（country.mmdb/geosite.dat，jsDelivr 通道）；若规则改用 ASN 需另补 asn.mmdb。
- **订阅刷新**：订阅为静态快照（2026-11-13 拉取）——刷新=本机重新拉取（testkit-local/mihomo-test/ 内有现成流程与探针脚本）→ scp 覆盖 sub.yaml → `systemctl restart mihomo`。
- **实测证据（2026-11-13）**：gstatic 204 0.5s / AbuseIPDB 401（无凭据预期）/ 四源 200×4（SFS/ip.nc.gy/ip.sb/ipquery 经 wp_remote_get）/ verbose check 8.8.8.8 → 200 score=0 CDN US（I2 check 全链绿态首证）。
- **回滚**：`systemctl disable --now mihomo` + wp-config 还原备份 + `ufw delete allow in on br-49174db99c85 to any port 7890 proto tcp`（注意接口名必须全称，截断名静默失效——本批踩坑实录）。

## 出境补验证据（2026-11-13，VERIFY 批——mihomo 上线后 M3-IP pending 补齐实录）

- **绿态（I2 check/测试凭据）**：probe('8.8.8.8') 真外呼 950ms→pass score=0+`wk_ip_abuseipdb_eb47ace5bc52de48` 落位+usage delta=1；同参复探 1ms cached:true delta=0；Tab④「测试凭据」AJAX 真页 nonce 通道 **HTTP 200/1.42s state=green**（I4 灰态 11.1s 清账）；bad nonce 403 反证。
- **受理（report 429 面）**：report_ip('127.0.0.2') 官方矢量外呼→**HTTP 429 官方语义 body**（"You can only report the same IP address once in 15 minutes"/source.parameter=ip）→静默 false+事件 error meta.raw http=429+冷却/last_report_at/usage 按尝试落账三形态全证=丢报无损；3/3 次全 429（矢量 15min 去重窗被全球测试流量占用，含窗净首发）——200 受理面未现（如实 FAIL 登记，人工卡 M4 备注）；`check(127.0.0.2) totalReports=0`=矢量丢弃语义+零污染自证。
- **四源 live（I0 浏览器出数）**：`wk_gatekeeper_ip_diagnose` ip=8.8.8.8 → HTTP 200/5.32s **ok:true×4**（AbuseIPDB verbose/StopForumSpam/ip.nc.gy/ip.sb 全字段）+ Edge headless 四卡同框截图（fetch 劫持注入实抓 live JSON+程序化点击——**坑：headless `--screenshot` 场景 `scrollIntoView()` 产出确定性空白帧，用全页大窗替代**；transient 落位证据走 `redis-cli --scan`——wp_options 在 redis drop-in 下恒空）。
- 详录：`docs/reports/VERIFY-出境补验-2026-11-13.md`（外层 docs）；截图 `i5-credential-green.png`/`panel-live-four-sources.png`。。
