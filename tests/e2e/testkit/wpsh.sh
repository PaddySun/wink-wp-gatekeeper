#!/usr/bin/env bash
# 【入库脱敏版（B7 收线 2026-11-11）】真实环境值不入 git，以占位符代替。占位符对照表：
#   <TEST_HOST>=厦门测试机 IP（内网）  <SSH_PORT>=SSH 真口  <SSH_KEY_PATH>=本机私钥绝对路径  <WP_ADMIN_USER>=后台用户名（本文件未用）
# 本机使用法：填回真值（或 cp 一份改名填值后使用，勿提交）——
#   sed -i 's/<TEST_HOST>/真实IP/g; s/<SSH_PORT>/真实端口/g; s#<SSH_KEY_PATH>#真实密钥路径#g' wpsh.sh
# 详见同目录 README-SANITIZED.md。
# wpsh.sh — 厦门测试站远程 wp-cli / shell 包装（06-真机测试计划 §5；沉淀：U2 agent）
#
# SSH 纪律（运维手册坑 4/5）：
#   - 每次调用前从 /tmp/agent.env 恢复 SSH_AUTH_SOCK（agent 套接字失效会静默失败）
#   - 单命令短小 + 本地 timeout 包裹（Windows ssh.exe 僵尸残留，失败后手动清场）
#   - 只连厦门 <SSH_PORT> 真口；48490 陷阱口绝对不碰
#
# 站点切换（2026-11-10）：
#   - 目标站容器 wptest-wp → wpclean-wp（干净最小站 /opt/wpclean；原克隆站 /opt/wptest 已 down 封存）
#   - wp-cli.phar 自愈源仍为 /opt/wptest/incoming/（原站资产原地只读保留）
#
# wp-cli 事实（U2 实测 2026-11-09）：
#   - 生产克隆插件（wordfence/sync-qcloud-cos 等）在全量加载时 CLI 阻塞 >120s，
#     一律 --skip-plugins --skip-themes（web 请求不受影响，0.9s 正常）
#   - 插件 slug = wink-wp-gatekeeper（目录名），非 wink-gatekeeper
#
# 用法：
#   ./wpsh.sh wp <wp-cli 参数...>        # 容器内 wp-cli（自愈 wp-cli.phar）
#   ./wpsh.sh raw '<host 命令>'          # 宿主机任意短命令
#   ./wpsh.sh b64eval '<php 代码>'       # WP 上下文执行 PHP（base64 免转义，经 eval-file）
#   ./wpsh.sh wpb64 '<b64>...'           # 同上，直接给 base64（内部用）
set -uo pipefail

KEY="<SSH_KEY_PATH>"
HOST="root@<TEST_HOST>"
PORT="<SSH_PORT>"
WPBASE="docker exec wpclean-wp php /tmp/wp-cli.phar --allow-root --skip-plugins --skip-themes --path=/var/www/html"

restore_agent() {
  if [ -f /tmp/agent.env ]; then
    export SSH_AUTH_SOCK="$(grep -o 'SSH_AUTH_SOCK=[^ ]*' /tmp/agent.env | cut -d= -f2-)"
  fi
}

ssh_run() { # $1 = 完整远程命令
  restore_agent
  timeout 55 ssh -o ConnectTimeout=12 -o BatchMode=yes -o IdentitiesOnly=yes \
    -i "$KEY" -p "$PORT" "$HOST" "$1"
}

# 把带空格/中文的参数重新安全加引号（remote 命令是单字符串）
req() {
  local out="" a
  for a in "$@"; do
    a="${a//\'/\'\\\'\'}"
    out="$out '$a'"
  done
  printf '%s' "$out"
}

cmd="${1:-}"; shift || true
case "$cmd" in
  wp)
    ssh_run "docker exec wpclean-wp test -f /tmp/wp-cli.phar || docker cp /opt/wptest/incoming/wp-cli.phar wpclean-wp:/tmp/; $WPBASE$(req "$@")"
    ;;
  raw)
    ssh_run "$1"
    ;;
  b64eval)
    B64="$(printf '<?php\n%s' "$1" | base64 -w0)"
    ssh_run "echo '$B64' | base64 -d > /tmp/wk-eval.php && docker cp /tmp/wk-eval.php wpclean-wp:/tmp/ >/dev/null && $WPBASE eval-file /tmp/wk-eval.php"
    ;;
  wpb64)
    ssh_run "echo '$1' | base64 -d > /tmp/wk-eval.php && docker cp /tmp/wk-eval.php wpclean-wp:/tmp/ >/dev/null && $WPBASE eval-file /tmp/wk-eval.php"
    ;;
  push)
    # wpsh.sh push <本地文件> [<容器内路径>] —— scp 到宿主机 /tmp 再 docker cp 进容器（大文件/JSON 恢复用；
    # 绕过 ~4.5KB 远程命令串长度限制，U2 实测）。默认容器路径 /tmp/<basename>。输出容器内路径。
    LOCAL="$1"; DEST="${2:-/tmp/$(basename "$LOCAL")}"
    restore_agent
    timeout 55 scp -o ConnectTimeout=12 -o BatchMode=yes -o IdentitiesOnly=yes \
      -i "$KEY" -P "$PORT" "$LOCAL" "$HOST:/tmp/wk-up.tmp" || exit 1
    ssh_run "mv /tmp/wk-up.tmp '$DEST' && docker cp '$DEST' wpclean-wp:$(dirname "$DEST")/ >/dev/null && echo PUSHED=$DEST"
    ;;
  *)
    echo "usage: wpsh.sh wp <args> | raw '<cmd>' | b64eval '<php>'" >&2
    exit 2
    ;;
esac
