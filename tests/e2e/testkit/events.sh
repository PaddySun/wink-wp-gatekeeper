#!/usr/bin/env bash
# events.sh — 事件表 wp_wink_gatekeeper_events 查询（沉淀：U2 agent）
#
# 用法：
#   ./events.sh latest [N]            # 最近 N 条（默认 10；meta JSON 展开）
#   ./events.sh by-no <WK-编号>       # 按事件编号查单条
#   ./events.sh by-stage <stage> [N]  # 按阶段（review/captcha/...）
#   ./events.sh count                 # 总行数
# 输出：每条一行 JSON（id/event_no/created_at/gate/stage/driver/verdict/rule_hit/reason/meta 解码）。
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
b64() { printf '%s' "$1" | base64 -w0; }

sub="${1:-}"; shift || true
case "$sub" in
  latest)
    N="${1:-10}"
    "$HERE/wpsh.sh" b64eval '
$n = max(1, min(200, (int) "'"$N"'"));
global $wpdb; $t = $wpdb->prefix . "wink_gatekeeper_events";
$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", $n), ARRAY_A);
foreach ($rows as $r) { $r["meta"] = json_decode((string) $r["meta"], true); echo json_encode($r, JSON_UNESCAPED_UNICODE), PHP_EOL; }'
    ;;
  by-no)
    B_NO="$(b64 "$1")"
    "$HERE/wpsh.sh" b64eval '
$no = base64_decode("'$B_NO'");
global $wpdb; $t = $wpdb->prefix . "wink_gatekeeper_events";
$r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE event_no = %s", $no), ARRAY_A);
if ($r) { $r["meta"] = json_decode((string) $r["meta"], true); echo json_encode($r, JSON_UNESCAPED_UNICODE), PHP_EOL; }
else { fwrite(STDERR, "NOT_FOUND $no\n"); exit(1); }'
    ;;
  by-stage)
    B_ST="$(b64 "$1")"; N="${2:-10}"
    "$HERE/wpsh.sh" b64eval '
$st = base64_decode("'$B_ST'"); $n = max(1, min(200, (int) "'"$N"'"));
global $wpdb; $t = $wpdb->prefix . "wink_gatekeeper_events";
$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE stage = %s ORDER BY id DESC LIMIT %d", $st, $n), ARRAY_A);
foreach ($rows as $r) { $r["meta"] = json_decode((string) $r["meta"], true); echo json_encode($r, JSON_UNESCAPED_UNICODE), PHP_EOL; }'
    ;;
  count)
    "$HERE/wpsh.sh" b64eval '
global $wpdb; $t = $wpdb->prefix . "wink_gatekeeper_events";
echo "COUNT=", (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"), PHP_EOL;'
    ;;
  *)
    echo "usage: events.sh latest [N] | by-no <WK-..> | by-stage <stage> [N] | count" >&2
    exit 2
    ;;
esac
