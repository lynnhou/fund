#!/bin/bash

# 确保脚本以 root 权限运行
if [ "$EUID" -ne 0 ]; then
  echo "请使用 root 权限运行此脚本 (sudo bash install.sh)"
  exit 1
fi

echo "=================================================="
echo "          欢迎使用 基金实时监控通知系统 安装脚本     "
echo "=================================================="

# 1. 检测操作系统
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    echo "无法识别的系统架构，请使用 Ubuntu/Debian/CentOS"
    exit 1
fi

echo "[*] 检测到系统版本: $OS"

# 2. 安装基础依赖环境
echo "[*] 正在安装/检查依赖环境..."
if [ "$OS" = "ubuntu" ] || [ "$OS" = "debian" ]; then
    apt-get update -y
    apt-get install -y curl nginx php-fpm php-curl php-json cron git
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    PHP_SERVICE="php$PHP_VER-fpm"
elif [ "$OS" = "centos" ] || [ "$OS" = "rhel" ]; then
    yum install -y epel-release
    yum install -y curl nginx php php-fpm php-curl php-json cronie git
    PHP_SERVICE="php-fpm"
fi

# 3. 配置管理后台凭证
echo "--------------------------------------------------"
RANDOM_USER="admin_$(head /dev/urandom | tr -dc 'a-z0-9' | head -c 4)"
RANDOM_PASS=$(head /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 12)

read -p "请输入后台管理用户名 [默认: $RANDOM_USER]: " ADMIN_USER
ADMIN_USER=${ADMIN_USER:-$RANDOM_USER}

read -p "请输入后台管理密码 [默认: $RANDOM_PASS]: " ADMIN_PASS
ADMIN_PASS=${ADMIN_PASS:-$RANDOM_PASS}
echo "--------------------------------------------------"

# 4. 部署网站文件
WEB_ROOT="/var/www/fund_monitor"
echo "[*] 创建网站目录: $WEB_ROOT"
mkdir -p $WEB_ROOT

# 将当前目录下的网页文件复制到网站根目录（如果是通过GitHub拉取的）
cp index.html admin.html api.php cron.php $WEB_ROOT/ 2>/dev/null

# 5. 生成初始配置文件
cat <<EOF > $WEB_ROOT/config.json
{
    "auth": {
        "username": "$ADMIN_USER",
        "password": "$ADMIN_PASS"
    },
    "funds": ["161725", "005827"],
    "notify": {
        "threshold_up": 2.0,
        "threshold_down": -2.0,
        "wechat_enabled": false,
        "wechat_key": "",
        "tg_enabled": false,
        "tg_token": "",
        "tg_chatid": "",
        "email_enabled": false,
        "email_smtp": "",
        "email_user": "",
        "email_pass": "",
        "email_to": ""
    }
}
EOF

# 设置权限，允许 PHP 读写配置，同时防止外界直接下载 config.json
chown -R www-data:www-data $WEB_ROOT 2>/dev/null || chown -R nginx:nginx $WEB_ROOT
chmod 600 $WEB_ROOT/config.json

# 6. 自动化配置 Nginx
NGINX_CONF="/etc/nginx/conf.d/fund_monitor.conf"
echo "[*] 配置 Nginx 虚拟主机..."
cat <<EOF > $NGINX_CONF
server {
    listen 80;
    server_name _; # 允许通过IP直接访问
    root $WEB_ROOT;
    index index.html;

    location / {
        try_files \$uri \$uri/ =404;
    }

    # 禁止外部直接下载配置文件
    location ~ config\.json {
        deny all;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        # 兼容不同系统的 PHP-FPM socket/port
        if (-e /run/php/php$PHP_VER-fpm.sock) {
            fastcgi_pass unix:/run/php/php$PHP_VER-fpm.sock;
        }
        if (!-e /run/php/php$PHP_VER-fpm.sock) {
            fastcgi_pass 127.0.0.1:9000;
        }
    }
}
EOF

# 7. 启动并激活服务
echo "[*] 重启服务中..."
systemctl daemon-reload
systemctl restart nginx
systemctl enable nginx
systemctl restart $PHP_SERVICE
systemctl enable $PHP_SERVICE
systemctl restart cron 2>/dev/null || systemctl restart cronie

# 8. 设置自动化定时任务 (每10分钟执行一次通知检查)
CRON_JOB="*/10 * * * * /usr/bin/php $WEB_ROOT/cron.php > /dev/null 2>&1"
(crontab -l 2>/dev/null | grep -v "cron.php" ; echo "$CRON_JOB") | crontab -

echo "=================================================="
echo "🎉 安装完成！您的基金监控面板已成功运行！"
echo "👉 前台展示页面: http://你的服务器IP/index.html"
echo "👉 后台管理页面: http://你的服务器IP/admin.html"
echo "--------------------------------------------------"
echo "🔑 后台登录用户名: $ADMIN_USER"
echo "🔑 后台登录密码: $ADMIN_PASS"
echo "⚠️ 请务必妥善保存以上凭证！"
echo "=================================================="