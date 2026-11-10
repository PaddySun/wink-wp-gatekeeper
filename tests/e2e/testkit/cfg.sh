#!/usr/bin/env bash
# cfg.sh — wink_gatekeeper_settings 配置读写/快照/恢复 + WP 核心 option 读写（沉淀：U2 agent）
#
# 设计：变量一律 base64 载荷注入 PHP（只插值 base64 字符集，零引号风险）。
# 输出永不出现密钥明文（get 自动打码 secret/key/token/password/app_id；set 只回显路径）。
#
# 用法：
#   ./cfg.sh get [dot.path]          # 读整树或子树（JSON）
#   ./cfg.sh set <dot.path> <value>  # 写子树（value 先按 JSON 解析，失败按字符串）；带回读
#   ./cfg.sh snap <本地文件>         # 整树快照到本地 JSON
#   ./cfg.sh restore <本地文件>      # 从快照恢复整树
#   ./cfg.sh wp-get <option>         # 读 WP 核心 option（JSON）
#   ./cfg.sh wp-set <option> <value> # 写 WP 核心 option（value 先按 JSON 解析，失败按字符串）
#   ./cfg.sh env-sync tencent [env文件] # .env 天御四字段 → option captcha.tencent（密钥走 base64
#                                      # 通道注入远端，回读只报 SET(len=N)/EMPTY，永不打印明文）
#   ./cfg.sh env-sync tms [env文件]     # C4：.env TMS 三键 → review.cloud.tencent_tms 并启用云端
#   ./cfg.sh env-sync aliyun [env文件]  # B3：.env 阿里键 → captcha.aliyun 并切 driver=aliyun（密钥 base64 通道）
#                                      # 审核 + 入链（enabled=true + chain=['tencent_tms']）
#   ./cfg.sh reset-defaults          # 整树重置为 SettingsSchema::defaults()（实测后用，危险）
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PHP_BIN="${PHP_BIN:-C:\\Users\\paddy\\AppData\\Local\\Microsoft\\WinGet\\Packages\\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\\php.exe}"

b64() { printf '%s' "$1" | base64 -w0; }

mask() { # stdin JSON -> 打码后 stdout（数组深打码；标量原样透传——标量敏感路径已在 get 上游打码）
  "$PHP_BIN" -r '
    $in = stream_get_contents(STDIN);
    $d = json_decode($in, true);
    if (!is_array($d)) { echo $in; exit; }
    $walk = function (&$v, $k) use (&$walk) {
      if (is_array($v)) { array_walk($v, $walk); return; }
      if (is_string($k) && preg_match("/secret|key|token|password|app_id$/i", $k) && is_string($v) && "" !== $v) {
        $v = "***MASKED(len=" . strlen($v) . ")***";
      }
    };
    array_walk($d, $walk);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
  '
}

sub="${1:-}"; shift || true
case "$sub" in
  get)
    B_DOTS="$(b64 "${1:-}")"
    "$HERE/wpsh.sh" b64eval '
$path = base64_decode("'$B_DOTS'");
$o = get_option("wink_gatekeeper_settings", array());
$v = $o;
if ("" !== $path) {
  foreach (explode(".", $path) as $seg) {
    if (!is_array($v) || !array_key_exists($seg, $v)) { fwrite(STDERR, "PATH_NOT_FOUND " . $path . "\n"); exit(1); }
    $v = $v[$seg];
  }
}
// 标量：路径命中敏感模式则打码（数组交给下游 mask）
if (is_scalar($v) && "" !== $path && 1 === preg_match("#secret|key|token|password|app_id$#i", $path) && "" !== (string) $v) {
  echo json_encode("***MASKED(len=" . strlen((string) $v) . ")***"), PHP_EOL;
} else {
  echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
}' | mask
    ;;
  set)
    B_DOTS="$(b64 "$1")"; B_VAL="$(b64 "$2")"
    "$HERE/wpsh.sh" b64eval '
$d = base64_decode("'$B_DOTS'"); $raw = base64_decode("'$B_VAL'");
$decoded = json_decode($raw, true);
$val = (JSON_ERROR_NONE === json_last_error()) ? $decoded : $raw;
$o = get_option("wink_gatekeeper_settings", array());
$ref = &$o; $segs = explode(".", $d); $leaf = array_pop($segs);
foreach ($segs as $s) {
  if (!isset($ref[$s]) || !is_array($ref[$s])) { $ref[$s] = array(); }
  $ref = &$ref[$s];
}
$ref[$leaf] = $val; unset($ref);
$r = update_option("wink_gatekeeper_settings", $o);
$back = get_option("wink_gatekeeper_settings", array());
$cur = $back; foreach (array_merge($segs, array($leaf)) as $s) { $cur = is_array($cur) ? ($cur[$s] ?? null) : null; }
echo ($r ? "WRITE_OK" : "WRITE_FALSE(unchanged?)"), " path=", $d, " readback=", json_encode($cur, JSON_UNESCAPED_UNICODE), PHP_EOL;'
    ;;
  snap)
    OUT="$1"
    "$HERE/wpsh.sh" b64eval 'echo json_encode(get_option("wink_gatekeeper_settings", array()), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), PHP_EOL;' > "$OUT"
    echo "SNAPSHOT saved: $OUT ($(wc -c < "$OUT") bytes)"
    ;;
  restore)
    # 大 JSON 走文件通道（scp+docker cp）——远程命令串 >4.5KB 会静默截断（U2 实测）
    "$HERE/wpsh.sh" push "$1" /tmp/wk-restore.json >/dev/null || { echo "PUSH_FAIL" >&2; exit 1; }
    "$HERE/wpsh.sh" b64eval '
$j = file_get_contents("/tmp/wk-restore.json");
$v = json_decode((string) $j, true);
if (!is_array($v)) { fwrite(STDERR, "BAD_JSON\n"); exit(1); }
$r = update_option("wink_gatekeeper_settings", $v);
$b = get_option("wink_gatekeeper_settings", array());
echo ($r ? "RESTORE_OK" : "RESTORE_FALSE(unchanged?)"),
  " schema=", ($b["schema_version"] ?? "?"),
  " min=", var_export($b["review"]["local"]["min_length"] ?? "?", true),
  " max=", var_export($b["review"]["local"]["max_length"] ?? "?", true),
  " require_chinese=", var_export($b["review"]["local"]["require_chinese"] ?? "?", true),
  " banned_words_len=", strlen((string)($b["review"]["local"]["custom_banned_words"] ?? "")),
  " copy_msg_too_short_default=", ($b["copy"]["messages"]["too_short"] === "这条评论看起来像还没写完。也可能是手滑提前按了提交——写到 {min_length} 字（{max_length} 字以内）再试试？" ? "yes" : "NO"),
  PHP_EOL;'
    ;;
  wp-get)
    B_OPT="$(b64 "$1")"
    "$HERE/wpsh.sh" b64eval '
$opt = base64_decode("'$B_OPT'");
echo json_encode(get_option($opt, null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;' | mask
    ;;
  wp-set)
    B_OPT="$(b64 "$1")"; B_VAL="$(b64 "$2")"
    "$HERE/wpsh.sh" b64eval '
$opt = base64_decode("'$B_OPT'"); $raw = base64_decode("'$B_VAL'");
$decoded = json_decode($raw, true);
$val = (JSON_ERROR_NONE === json_last_error()) ? $decoded : $raw;
$r = update_option($opt, $val);
$back = get_option($opt, "§WK_MISSING§");
echo ($r ? "WP_WRITE_OK" : "WP_WRITE_FALSE(unchanged?)"), " opt=", $opt, " readback=", json_encode($back, JSON_UNESCAPED_UNICODE), PHP_EOL;'
    ;;
  env-sync)
    # .env 凭据同步到 option（C2 天御四字段 / C4 TMS 三键 + 启用）。
    # 密钥纪律：本地 grep 提取后立即 base64，远程命令串只含 base64 字符集；回读只报空/非空+长度。
    PROFILE="${1:-tencent}"
    ENV_FILE="${2:-C:/Users/paddy/Desktop/dev/wink-wp-gatekeeper/.env}"
    [ -f "$ENV_FILE" ] || { echo "ENV_FILE_MISSING: $ENV_FILE" >&2; exit 1; }
    env_val() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '\r\n'; }
    case "$PROFILE" in
      tencent)
        V_APP="$(env_val WINK_TENCENT_CAPTCHA_APP_ID)"
        V_SID="$(env_val WINK_TENCENT_SECRET_ID)"
        V_SKEY="$(env_val WINK_TENCENT_SECRET_KEY)"
        V_AKEY="$(env_val WINK_TENCENT_CAPTCHA_APP_SECRET_KEY)"
        for pair in "APP_ID:$V_APP" "SECRET_ID:$V_SID" "SECRET_KEY:$V_SKEY" "APP_SECRET_KEY:$V_AKEY"; do
          [ -n "${pair#*:}" ] || { echo "ENV_VALUE_EMPTY: ${pair%%:*} （$ENV_FILE）" >&2; exit 1; }
        done
        B_APP="$(b64 "$V_APP")"; B_SID="$(b64 "$V_SID")"; B_SKEY="$(b64 "$V_SKEY")"; B_AKEY="$(b64 "$V_AKEY")"
        "$HERE/wpsh.sh" b64eval '
$app  = (int) base64_decode("'$B_APP'");
$sid  = base64_decode("'$B_SID'");
$skey = base64_decode("'$B_SKEY'");
$akey = base64_decode("'$B_AKEY'");
$o = get_option("wink_gatekeeper_settings", array());
$o["captcha"]["driver"] = "tencent";
$o["captcha"]["tencent"]["app_id"] = $app;
$o["captcha"]["tencent"]["secret_id"] = $sid;
$o["captcha"]["tencent"]["secret_key"] = $skey;
$o["captcha"]["tencent"]["app_secret_key"] = $akey;
$r = update_option("wink_gatekeeper_settings", $o);
$b = get_option("wink_gatekeeper_settings", array());
$t = is_array($b["captcha"]["tencent"] ?? null) ? $b["captcha"]["tencent"] : array();
echo ($r ? "SYNC_OK" : "SYNC_FALSE(unchanged?)"), " driver=", (string) ($b["captcha"]["driver"] ?? "?"), PHP_EOL;
foreach (array("app_id", "secret_id", "secret_key", "app_secret_key") as $k) {
  $v = (string) ($t[$k] ?? "");
  echo "  ", $k, "=", ("" === $v ? "EMPTY" : "SET(len=" . strlen($v) . ")"), PHP_EOL;
}'
        ;;
      tms)
        # C4：TMS 三键（SID/SKEY/bizType）→ review.cloud.tencent_tms，并启用云端审核 +
        # 入链（任务口径「启用 TMS = env-sync tms」：enabled=true + chain=['tencent_tms']，
        # 顺序 LocalRules→TMS 固定）。密钥走 base64 通道；bizType 为策略编号非密钥，
        # 同通道注入、回读只报长度（一致纪律）。
        V_SID="$(env_val WINK_TENCENT_TMS_SECRET_ID)"
        V_SKEY="$(env_val WINK_TENCENT_TMS_SECRET_KEY)"
        V_BIZ="$(env_val WINK_TENCENT_TMS_bizType)"
        for pair in "SECRET_ID:$V_SID" "SECRET_KEY:$V_SKEY" "bizType:$V_BIZ"; do
          [ -n "${pair#*:}" ] || { echo "ENV_VALUE_EMPTY: ${pair%%:*} （$ENV_FILE）" >&2; exit 1; }
        done
        B_SID="$(b64 "$V_SID")"; B_SKEY="$(b64 "$V_SKEY")"; B_BIZ="$(b64 "$V_BIZ")"
        "$HERE/wpsh.sh" b64eval '
$sid  = trim(base64_decode("'$B_SID'"));
$skey = trim(base64_decode("'$B_SKEY'"));
$biz  = trim(base64_decode("'$B_BIZ'"));
$o = get_option("wink_gatekeeper_settings", array());
$o["review"]["cloud"]["enabled"] = true;
$o["review"]["cloud"]["chain"] = array("tencent_tms");
$o["review"]["cloud"]["tencent_tms"]["secret_id"] = $sid;
$o["review"]["cloud"]["tencent_tms"]["secret_key"] = $skey;
$o["review"]["cloud"]["tencent_tms"]["biz_type"] = $biz;
$r = update_option("wink_gatekeeper_settings", $o);
$b = get_option("wink_gatekeeper_settings", array());
$t = is_array($b["review"]["cloud"]["tencent_tms"] ?? null) ? $b["review"]["cloud"]["tencent_tms"] : array();
echo ($r ? "SYNC_OK" : "SYNC_FALSE(unchanged?)"),
  " cloud.enabled=", var_export((bool)($b["review"]["cloud"]["enabled"] ?? null), true),
  " chain=", json_encode($b["review"]["cloud"]["chain"] ?? null), PHP_EOL;
foreach (array("secret_id", "secret_key", "biz_type") as $k) {
  $v = (string) ($t[$k] ?? "");
  echo "  ", $k, "=", ("" === $v ? "EMPTY" : "SET(len=" . strlen($v) . ")"), PHP_EOL;
}
echo "  driver=", (string) ($b["captcha"]["driver"] ?? "?"), "（captcha 不动）", PHP_EOL;'
        ;;
      aliyun)
        # B3：阿里验证码 2.0（AK 对 + prefix；SceneId 键若在 env 中一并同步）→ captcha.aliyun，
        # 并切 driver=aliyun。密钥走 base64 通道；回读只报 SET(len=N)/EMPTY。
        # C1 教训（REGISTRY）：env 值尾随  必 trim（ACS3 HMAC 密钥含不可见字符 → SignatureDoesNotMatch）。
        V_AKID="$(env_val WINK_ALIYUN_CAPTCHA_ACCESS_KEY_ID)"
        V_AKSEC="$(env_val WINK_ALIYUN_CAPTCHA_ACCESS_KEY_SECRET)"
        V_PREFIX="$(env_val WINK_ALIYUN_CAPTCHA_prefix)"
        V_SCENE="$(env_val WINK_ALIYUN_CAPTCHA_SCENE_ID)"
        for pair in "ACCESS_KEY_ID:$V_AKID" "ACCESS_KEY_SECRET:$V_AKSEC" "prefix:$V_PREFIX"; do
          [ -n "${pair#*:}" ] || { echo "ENV_VALUE_EMPTY: ${pair%%:*} （$ENV_FILE）" >&2; exit 1; }
        done
        [ -n "$V_SCENE" ] || echo "ENV_SCENE_ID_EMPTY: WINK_ALIYUN_CAPTCHA_SCENE_ID 未配置（05 §1.2 v1.5 必填——真机 green/pass 态留人工卡）" >&2
        B_AKID="$(b64 "$V_AKID")"; B_AKSEC="$(b64 "$V_AKSEC")"; B_PREFIX="$(b64 "$V_PREFIX")"; B_SCENE="$(b64 "$V_SCENE")"
        "$HERE/wpsh.sh" b64eval '
$akid  = trim(base64_decode("'$B_AKID'"));
$aksec = trim(base64_decode("'$B_AKSEC'"));
$pre   = trim(base64_decode("'$B_PREFIX'"));
$scene = trim(base64_decode("'$B_SCENE'"));
$o = get_option("wink_gatekeeper_settings", array());
$o["captcha"]["driver"] = "aliyun";
$o["captcha"]["aliyun"]["access_key_id"] = $akid;
$o["captcha"]["aliyun"]["access_key_secret"] = $aksec;
$o["captcha"]["aliyun"]["prefix"] = $pre;
if ("" !== $scene) { $o["captcha"]["aliyun"]["scene_id"] = $scene; }
$r = update_option("wink_gatekeeper_settings", $o);
$b = get_option("wink_gatekeeper_settings", array());
$t = is_array($b["captcha"]["aliyun"] ?? null) ? $b["captcha"]["aliyun"] : array();
echo ($r ? "SYNC_OK" : "SYNC_FALSE(unchanged?)"), " driver=", (string) ($b["captcha"]["driver"] ?? "?"), PHP_EOL;
foreach (array("access_key_id", "access_key_secret", "scene_id", "prefix", "mode") as $k) {
  $v = (string) ($t[$k] ?? "");
  echo "  ", $k, "=", ("" === $v ? "EMPTY" : "SET(len=" . strlen($v) . ")"), PHP_EOL;
}'
        ;;
      *)
        echo "env-sync: 仅支持 tencent | tms（收到 $PROFILE）" >&2; exit 2
        ;;
    esac
    ;;
  reset-defaults)
    "$HERE/wpsh.sh" b64eval '
$base = ABSPATH . "wp-content/plugins/wink-wp-gatekeeper/includes/";
require_once $base . "intercept/Copywork.php";
require_once $base . "settings/SettingsSchema.php";
$d = WinkGatekeeper\Settings\SettingsSchema::defaults();
update_option("wink_gatekeeper_settings", $d);
$b = get_option("wink_gatekeeper_settings");
echo "RESET_OK schema=", $b["schema_version"],
  " min=", $b["review"]["local"]["min_length"],
  " max=", $b["review"]["local"]["max_length"],
  " require_chinese=", $b["review"]["local"]["require_chinese"],
  " use_wp_keywords=", $b["review"]["local"]["use_wp_keywords"],
  " copy_defaults=", ($b["copy"]["messages"]["too_short"] === WinkGatekeeper\Intercept\Copywork::defaults()["messages"]["too_short"] ? "yes" : "NO"),
  PHP_EOL;'
    ;;
  *)
    echo "usage: cfg.sh get [path] | set <path> <val> | snap <file> | restore <file> | wp-get <opt> | wp-set <opt> <val> | reset-defaults" >&2
    exit 2
    ;;
esac
