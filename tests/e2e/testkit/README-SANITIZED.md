# README-SANITIZED — tests/e2e 脱敏入库说明（B7 收线 2026-11-11）

本目录（`tests/e2e/`）为**真机测试工具箱（testkit）脱敏入库版**：真实环境值已替换为占位符，真实值永不入 git。

## 占位符对照表

| 占位符 | 含义 | 填回来源 |
|---|---|---|
| `<TEST_HOST>` | 厦门测试机内网 IP | `docs/HANDOVER-统筹交接-2026-11-11.md` §3（本地文档，不入 git） |
| `<SSH_PORT>` | SSH 真口（陷阱口 48490 绝对不碰——占位版已隐去真口值） | 同上 §3 |
| `<SSH_KEY_PATH>` | 本机 SSH 私钥绝对路径 | 同上 §3（本机路径，各机不同） |
| `<WP_ADMIN_USER>` | 测试站 wp-admin 用户名 | `docs/reports/SITE-REBUILD-2026-11-10.md`（本地，不入 git） |

## 本机使用法（二选一）

1. **环境变量/直接填回**：cp 需要的脚本到工作副本，`sed -i 's/<TEST_HOST>/真实IP/g; s/<SSH_PORT>/真实端口/g; s#<SSH_KEY_PATH>#真实密钥路径#g' <文件>` 后使用；工作副本勿提交（.gitignore 对填值副本不作保护，靠纪律）。
2. **仓库内直接替换后用完还原**：不推荐——容易误提交。

注：`<WP_ADMIN_USER>` 仅在人工卡/文档口径出现（当前入库脚本未使用该占位符，预留对照表完整性）。

## 不入库范围（.gitignore 排除，见仓库根 `.gitignore`）

- `tests/e2e/evidence/` —— 真机证据快照 113 文件（HTML/log/json/hdr/txt，含后台用户名/内网拓扑等现场信息），仅本地留存。
- `tests/e2e/testkit/*snap*.json` —— `cfg.sh snap` 配置快照（b3-baseline-snap.json / snap-b5.json / snap-b6.json）**含未打码云凭据**（B7 复扫实锤），沿 B3 先例（`docs/reports/b3-pretest-snap.json`）移出 git 分发面，仅本地留存。

## 已脱敏文件（7 件，文件头均有占位符注记）

`wpsh.sh` / `target-setup.sh` / `login.php` / `comment.php` / `xmlrpc.php` / `precheck.php` / `TOOLS-REGISTRY.md`

## 复扫口径

提交前复扫（B7 收线已执行，**零命中**于将提交文件）：对 `git ls-files --others --exclude-standard tests/e2e` 枚举的将提交文件 grep 六类敏感 token（正则原文留档收线报告 `docs/reports/IMPL-B7-closeout-2026-11-11.md`，不在本文复写以免自命中）：①测试机内网 IP ②SSH 真口号 ③密钥文件名 ④后台用户名 ⑤口令片段 ⑥云凭据值/前缀。

```bash
git ls-files --others --exclude-standard tests/e2e | xargs grep -l -E "<敏感token正则——见收线报告>"
```
