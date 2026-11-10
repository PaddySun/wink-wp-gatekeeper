#!/usr/bin/env bash
# wink-wp-gatekeeper 发布打包（O2 P1-4）：git archive 从 tag 导出，校验三处版本号一致性。
# 用法: bash tools/build-zip.sh v0.2.3   （仓库根目录运行，Git Bash / Linux 兼容）
# 导出自动走 .gitattributes export-ignore（tests/.github/tools/工具链配置不入 zip）。
set -euo pipefail
tag="${1:?用法: bash tools/build-zip.sh <tag>  (如 v0.2.3)}"
ver="${tag#v}"
root="$(pwd)"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
git archive "$tag" --prefix="wink-wp-gatekeeper/" | tar -x -C "$tmp"
php_file="$tmp/wink-wp-gatekeeper/wink-wp-gatekeeper.php"
# 版本号单源校验：tag ↔ 插件头 Version ↔ WINK_GATEKEEPER_VERSION ↔ readme.txt Stable tag
[ "$ver" = "$(sed -n 's/^\s*\*\s*Version:\s*\([0-9][^ ]*\).*/\1/p' "$php_file")" ] || { echo "ERROR: tag $tag ≠ 插件头 Version"; exit 1; }
[ "$ver" = "$(grep -oE "define\( 'WINK_GATEKEEPER_VERSION', '[^']+'" "$php_file" | grep -oE "[0-9][0-9.]*")" ] || { echo "ERROR: tag $tag ≠ WINK_GATEKEEPER_VERSION"; exit 1; }
[ "$ver" = "$(sed -n 's/^Stable tag: \(.*\)$/\1/p' "$tmp/wink-wp-gatekeeper/readme.txt" | tr -d ' \r')" ] || { echo "ERROR: tag $tag ≠ readme.txt Stable tag"; exit 1; }
mkdir -p "$root/dist"
zip_file="$root/dist/wink-wp-gatekeeper-$tag.zip"
if command -v zip >/dev/null 2>&1; then
    (cd "$tmp" && zip -qry "$zip_file" wink-wp-gatekeeper)
elif [ -x /c/Windows/System32/tar.exe ]; then
    /c/Windows/System32/tar.exe -caf "$zip_file" -C "$tmp" wink-wp-gatekeeper
else
    echo "ERROR: 未找到 zip 或 Windows bsdtar（/c/Windows/System32/tar.exe）"; exit 1
fi
# 排除清单验证：发行 zip 内不得出现 tests/.github/tools/vendor（tar/zip 列表逐行核对路径）
if [ -x /c/Windows/System32/tar.exe ]; then
    listing="$(/c/Windows/System32/tar.exe -tf "$zip_file")"
elif command -v unzip >/dev/null 2>&1; then
    listing="$(unzip -l "$zip_file" | awk 'NF>=4 {print $NF}')"
else
    listing=""; echo "WARN: 无 zip 列表工具，跳过排除清单验证"
fi
if [ -n "$listing" ] && echo "$listing" | grep -Eq '(^|/)(tests|\.github|tools|vendor)/'; then
    echo "ERROR: zip 内出现应排除的目录"
    echo "提示: export-ignore 只对该 tag 树内已提交的 .gitattributes 生效——B0 之前打的旧 tag（如 v0.2.2）无法用本脚本打包，请用 B0 之后的提交打 tag。"
    exit 1
fi
echo "OK: $zip_file"
