#!/usr/bin/env bash
# usage.sh — 用量/统计 option 读取（沉淀：U2 agent）
#
# 用法：
#   ./usage.sh stats     # wink_gatekeeper_stats（Tab⑥ 统计卡片数据源）
#   ./usage.sh usage     # wink_gatekeeper_usage（传输脊计量；U2 恒零——LocalRules 不出网）
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"

sub="${1:-}"; shift || true
case "$sub" in
  stats)
    "$HERE/wpsh.sh" b64eval '
$s = get_option("wink_gatekeeper_stats", null);
echo json_encode(is_array($s) ? $s : array("__state" => "absent(seed)"), JSON_UNESCAPED_UNICODE), PHP_EOL;'
    ;;
  usage)
    "$HERE/wpsh.sh" b64eval '
$u = get_option("wink_gatekeeper_usage", null);
echo json_encode(is_array($u) ? $u : array("__state" => "absent(seed)"), JSON_UNESCAPED_UNICODE), PHP_EOL;'
    ;;
  *)
    echo "usage: usage.sh stats | usage" >&2
    exit 2
    ;;
esac
