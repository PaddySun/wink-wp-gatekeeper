#!/usr/bin/env bash
# 【入库脱敏版（B7 收线 2026-11-11）】占位符对照表：<TEST_HOST>=测试机 IP / <SSH_PORT>=SSH 真口 / <SSH_KEY_PATH>=本机私钥路径。
# 本机使用法：sed -i 's/<TEST_HOST>/真实IP/g; s/<SSH_PORT>/真实端口/g; s#<SSH_KEY_PATH>#真实密钥路径#g' target-setup.sh（或 cp 改名填值，勿提交）。详见 README-SANITIZED.md。
# target-setup.sh — 测试环境就绪/复位/状态（06 §5 工具箱；沉淀：U2 agent）
#
# 用法：
#   ./target-setup.sh status        # 插件状态/option/表/靶文/统计总览（只读）
#   ./target-setup.sh target        # 创建 draft 靶文（幂等：存在则复用，ID 记 .target-id）
#   ./target-setup.sh reset         # 状态复位：TRUNCATE events 表 + 删 stats/usage option（option 级，不动 DB 其它）
#   ./target-setup.sh install       # zip 构建→scp→host 解压→激活（本次 U2 已手工完成，留作复用入口）
#   ./target-setup.sh zip           # 仅构建本地 zip（排除 tests/.git*/dotfile/.phpunit.cache/docs）
#
# 靶文纪律：评论只造靶文（draft）下；reset 只清插件自身事件面。
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
IDFILE="$HERE/.target-id"
INNER_REPO="C:/Users/paddy/Desktop/dev/wink-wp-gatekeeper/wink-wp-gatekeeper"

sub="${1:-}"; shift || true
case "$sub" in
  status)
    "$HERE/wpsh.sh" b64eval '
$s = get_option("wink_gatekeeper_settings", array());
global $wpdb; $t = $wpdb->prefix . "wink_gatekeeper_events";
echo "plugin_active=", var_export(is_plugin_active("wink-wp-gatekeeper/wink-wp-gatekeeper.php"), true), PHP_EOL;
echo "schema_version=", ($s["schema_version"] ?? "MISSING"), PHP_EOL;
echo "gates.comment.enabled=", var_export((bool)($s["gates"]["comment"]["enabled"] ?? null), true), PHP_EOL;
echo "captcha.driver=", ($s["captcha"]["driver"] ?? "?"), PHP_EOL;
echo "review.local=", json_encode($s["review"]["local"] ?? null, JSON_UNESCAPED_UNICODE), PHP_EOL;
echo "use_wp_keywords=", var_export((bool)($s["review"]["local"]["use_wp_keywords"] ?? null), true), PHP_EOL;
echo "disallowed_keys=", json_encode((string) get_option("disallowed_keys", ""), JSON_UNESCAPED_UNICODE), PHP_EOL;
echo "events_table=", ($wpdb->get_var("SHOW TABLES LIKE \"{$t}\"") ? "EXISTS" : "MISSING"), PHP_EOL;
echo "events_count=", (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"), PHP_EOL;
echo "stats_option=", json_encode(get_option("wink_gatekeeper_stats", null) === null ? "absent" : "present"), PHP_EOL;
echo "target_post=", var_export((int) get_option("wink_u2_target_post", 0), true), PHP_EOL;'
    ;;
  target)
    "$HERE/wpsh.sh" b64eval '
// 注：WP 7.x 对 draft 文章的表单评论在 preprocess_comment 之前即静默 exit（comment_on_draft），
// HTTP 用例必须 publish（U2 实测 2026-11-09；受控偏差已记录报告）。
$pid = (int) get_option("wink_u2_target_post", 0);
$p = $pid ? get_post($pid) : null;
if (!$p || "WINK-U2 测试靶文" !== $p->post_title) {
  $pid = wp_insert_post(array(
    "post_title"   => "WINK-U2 测试靶文",
    "post_content" => "WINK U2 LocalRules 真机测试靶文（publish，评论造此文下；用例结束可整体删除）。",
    "post_status"  => "publish",
    "post_type"    => "post",
    "comment_status" => "open",
    "ping_status"  => "closed",
  ), true);
  if (is_wp_error($pid)) { fwrite(STDERR, "INSERT_FAIL: " . $pid->get_error_message() . "\n"); exit(1); }
  update_option("wink_u2_target_post", (int) $pid);
  echo "TARGET_CREATED=", $pid, PHP_EOL;
} else {
  if ("publish" !== $p->post_status) { wp_update_post(array("ID" => $pid, "post_status" => "publish")); }
  echo "TARGET_REUSED=", $pid, " status=", get_post_status($pid), " comment_status=", get_post($pid)->comment_status, PHP_EOL;
}'
    if [ ! -f "$IDFILE" ]; then echo "(ID 亦持久于 WP option wink_u2_target_post)"; fi
    ;;
  reset)
    echo "== reset 前证据 =="
    "$HERE/events.sh" count
    "$HERE/usage.sh" stats
    "$HERE/wpsh.sh" b64eval '
global $wpdb; $t = $wpdb->prefix . "wink_gatekeeper_events";
$n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
$wpdb->query("TRUNCATE TABLE {$t}");
delete_option("wink_gatekeeper_stats");
delete_option("wink_gatekeeper_usage");
// M2-FIX2 D4（2026-11-10）+ M2 反馈2 T3（2026-11-10）+ M2 增补轮批次 2（2026-11-10）：
// 登录计数/渲染标志/容灾限速/验证短缓存 + 预检限流桶（wk_pk_）/信用缓存（wk_tc_）
// transient 清理——带过期 transient 落 redis object cache 不落 options 表，双通道都清（只删键不读值）。
$opt_deleted = (int) $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"_transient_wk_lf%\" OR option_name LIKE \"_transient_timeout_wk_lf%\" OR option_name LIKE \"_transient_wk_dsr%\" OR option_name LIKE \"_transient_timeout_wk_dsr%\" OR option_name LIKE \"_transient_wk_cv%\" OR option_name LIKE \"_transient_timeout_wk_cv%\" OR option_name LIKE \"_transient_wk_pk%\" OR option_name LIKE \"_transient_timeout_wk_pk%\" OR option_name LIKE \"_transient_wk_tc%\" OR option_name LIKE \"_transient_timeout_wk_tc%\"");
echo "RESET_DONE truncated={$n} stats_option=deleted usage_option=deleted options_transient_rows={$opt_deleted}", PHP_EOL;'
    # redis 通道（object cache drop-in 下带过期 transient 的实际存放地）。
    "$HERE/wpsh.sh" raw "docker exec wpclean-redis sh -c 'redis-cli --scan --pattern \"*wk_lf*\" | xargs -r redis-cli del >/dev/null; redis-cli --scan --pattern \"*wk_dsr*\" | xargs -r redis-cli del >/dev/null; redis-cli --scan --pattern \"*wk_cv*\" | xargs -r redis-cli del >/dev/null; redis-cli --scan --pattern \"*wk_pk*\" | xargs -r redis-cli del >/dev/null; redis-cli --scan --pattern \"*wk_tc*\" | xargs -r redis-cli del >/dev/null; echo REDIS_LF_DSR_PURGED'" || echo "REDIS_PURGE_SKIPPED (无 redis 容器?)"
    echo "== reset 后证据 =="
    "$HERE/events.sh" count
    "$HERE/usage.sh" stats
    ;;
  zip)
    STAGE="/tmp/wk-stage"
    rm -rf "$STAGE" /tmp/wink-wp-gatekeeper.zip && mkdir -p "$STAGE"
    cp -r "$INNER_REPO" "$STAGE/wink-wp-gatekeeper"
    ( cd "$STAGE/wink-wp-gatekeeper" && rm -rf tests .git .gitignore .env.example .phpunit.cache docs )
    ( cd "$STAGE" && /c/Windows/System32/tar.exe --format=zip -cf ../wink-wp-gatekeeper.zip wink-wp-gatekeeper )
    echo "ZIP_BUILT /tmp/wink-wp-gatekeeper.zip ($(wc -c < /tmp/wink-wp-gatekeeper.zip) bytes)"
    ;;
  install)
    "$0" zip
    export SSH_AUTH_SOCK="$(grep -o 'SSH_AUTH_SOCK=[^ ]*' /tmp/agent.env | cut -d= -f2-)"
    timeout 90 scp -o ConnectTimeout=12 -o BatchMode=yes -o IdentitiesOnly=yes \
      -i "<SSH_KEY_PATH>" -P <SSH_PORT> \
      /tmp/wink-wp-gatekeeper.zip root@<TEST_HOST>:/tmp/ || { echo "SCP_FAIL"; exit 1; }
    "$HERE/wpsh.sh" raw "cd /opt/wpclean/html/wp-content/plugins/ && unzip -oq /tmp/wink-wp-gatekeeper.zip && chmod -R u=rwX,go=rX wink-wp-gatekeeper && echo EXTRACT_OK"
    "$HERE/wpsh.sh" wp plugin activate wink-wp-gatekeeper
    "$0" status
    ;;
  *)
    echo "usage: target-setup.sh status | target | reset | install | zip" >&2
    exit 2
    ;;
esac
