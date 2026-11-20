#!/usr/bin/env bash
# build-min.sh —— 前台资产 .min 构建（【P1-8·A2-F2 残余半件】审计修复批2 性能批）
#
# esbuild 固定版本（npx 免安装、无项目依赖——G5 零 composer/npm 依赖发行纪律不破）：
# 产物（.min）入仓提交，非构建期生成——运行时零构建依赖；SCRIPT_DEBUG=false 的站点
# 由 Bootstrap 入队 .min（SCRIPT_DEBUG=true 开发态走源文件）。
# 用法：bash tools/build-min.sh（在仓库任意位置——脚本自定位仓库根）。
set -euo pipefail
cd "$(dirname "$0")/.."

ESBUILD_VER=0.25.0

npx -y "esbuild@${ESBUILD_VER}" assets/js/gatekeeper.js        --minify --outfile=assets/js/gatekeeper.min.js
npx -y "esbuild@${ESBUILD_VER}" assets/js/drivers/tencent.js   --minify --outfile=assets/js/drivers/tencent.min.js
npx -y "esbuild@${ESBUILD_VER}" assets/js/drivers/aliyun.js    --minify --outfile=assets/js/drivers/aliyun.min.js
npx -y "esbuild@${ESBUILD_VER}" assets/css/front-gate.css      --minify --outfile=assets/css/front-gate.min.css

# 语法自检（node 在场时；esbuild 输出本身保证有效——自检为双保险）。
if command -v node >/dev/null 2>&1; then
    node --check assets/js/gatekeeper.min.js
    node --check assets/js/drivers/tencent.min.js
    node --check assets/js/drivers/aliyun.min.js
fi

echo "build-min OK（4 产物 + 语法自检过）"
