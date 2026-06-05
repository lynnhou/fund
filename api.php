<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    echo json_encode(["status" => "error", "msg" => "配置文件缺失"]);
    exit;
}

$config = json_decode(file_get_contents($configFile), true);
$action = $_GET['action'] ?? '';

// ==================== 开放接口 (无需登录) ====================

// 1. 处理登录请求
if ($action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (($data['username'] ?? '') === $config['auth']['username'] && ($data['password'] ?? '') === $config['auth']['password']) {
        $_SESSION['admin_logged'] = true;
        echo json_encode(["status" => "success", "msg" => "登录成功"]);
    } else {
        echo json_encode(["status" => "error", "msg" => "用户名或密码错误"]);
    }
    exit;
}

// 2. 检查登录状态
if ($action === 'checkAuth') {
    echo json_encode(["logged" => isset($_SESSION['admin_logged'])]);
    exit;
}

// 3. 前台获取公开的基金实时行情
if ($action === 'getPublicData') {
    $funds = $config['funds'] ?? [];
    $result = [];
    foreach ($funds as $code) {
        $url = "http://fundgz.1234567.com.cn/js/{$code}.js?rt=" . time();
        $response = @file_get_contents($url);
        if ($response && preg_match('/jsonpgz\((.*)\);/', $response, $matches)) {
            $data = json_decode($matches[1], true);
            $result[] = [
                "code" => $data['fundcode'],
                "name" => $data['name'],
                "net_value" => $data['dwjz'],
                "estimate" => $data['gsz'],
                "rate" => $data['gszzl'],
                "time" => $data['gztime']
            ];
        } else {
            $result[] = ["code" => $code, "name" => "无效代码或获取失败", "rate" => "0.00", "time" => "-"];
        }
    }
    echo json_encode(["status" => "success", "data" => $result]);
    exit;
}

// ==================== 鉴权拦截器 (以下接口必须登录) ====================
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    http_response_code(401);
    echo json_encode(["status" => "error", "msg" => "未授权访问"]);
    exit;
}

// 4. 后台注销登录
if ($action === 'logout') {
    session_destroy();
    echo json_encode(["status" => "success"]);
    exit;
}

// 5. 后台获取完整配置 (屏蔽密码输出以防泄露)
if ($action === 'getConfig') {
    $safeConfig = $config;
    unset($safeConfig['auth']['password']); // 安全起见，前台不回显管理密码
    echo json_encode($safeConfig);
    exit;
}

// 6. 后台保存配置
if ($action === 'saveConfig') {
    $newData = json_decode(file_get_contents('php://input'), true);
    if ($newData) {
        // 保持原密码不变
        $newData['auth']['password'] = $config['auth']['password'];
        // 如果用户在后台修改了用户名
        if (!empty($newData['auth']['username'])) {
            $config['auth']['username'] = $newData['auth']['username'];
        }
        
        $config['funds'] = $newData['funds'];
        $config['notify'] = $newData['notify'];

        file_put_contents($configFile, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(["status" => "success", "msg" => "配置修改已生效"]);
    }
    exit;
}
?>