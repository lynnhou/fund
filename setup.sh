#!/bin/bash

# Fund 一键安装脚本
# 使用方法: curl -sSL https://raw.githubusercontent.com/lynnhou/fund/main/setup.sh | bash

set -e

echo "=================================================="
echo "          Fund - 基金显示面板 一键安装脚本         "
echo "=================================================="
echo ""

# 检查权限
if [ "$EUID" -ne 0 ]; then
  echo "❌ 错误: 此脚本需要 root 权限"
  echo "请使用: sudo bash setup.sh"
  exit 1
fi

# 克隆仓库
INSTALL_DIR="$HOME/fund"
echo "[*] 克隆项目到 $INSTALL_DIR ..."

if [ -d "$INSTALL_DIR" ]; then
  echo "[!] 目录已存在，正在更新..."
  cd "$INSTALL_DIR"
  git pull origin main
else
  git clone https://github.com/lynnhou/fund.git "$INSTALL_DIR"
  cd "$INSTALL_DIR"
fi

echo "[✓] 项目已准备就绪"
echo "[*] 开始运行安装脚本..."
echo ""

# 运行安装脚本
bash "$INSTALL_DIR/install.sh"

echo ""
echo "=================================================="
echo "          安装完成！"
echo "=================================================="
