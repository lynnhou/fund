<?php
// 本脚本仅允许通过 Linux CLI (定时任务) 运行，禁止外部网页恶意刷流量
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("仅允许系统定时任务运行。");
}

$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) die("配置文件缺失\n");

$config = json_decode(file_get_contents($configFile), true);
$notify = $config['notify'];

$threshold_up = (float)$notify['threshold_up'];
$threshold_down = (float)$notify['threshold_down'];

$alertMsg = "";

// 循环检测自选基金
foreach ($config['funds'] as $code) {
    $url = "http://fundgz.1234567.com.cn/js/{$code}.js?rt=" . time();
    $response = @file_get_contents($url);
    if ($response && preg_match('/jsonpgz\((.*)\);/', $response, $matches)) {
        $data = json_decode($matches[1], true);
        $rate = (float)$data['gszzl'];
        $name = $data['name'];

        if ($rate >= $threshold_up) {
            $alertMsg .= "🚀【大涨预警】{$name}({$code}) 当前涨幅: {$rate}%\n";
        } elseif ($rate <= $threshold_down) {
            $alertMsg .= "📉【大跌预警】{$name}({$code}) 当前跌幅: {$rate}%\n";
        }
    }
}

// 发现满足条件的暴涨或暴跌基金，开始推送
if (!empty($alertMsg)) {
    $title = "🚨 基金行情异动预警通知 (" . date("H:i") . ")";
    $fullContent = $title . "\n\n" . $alertMsg;

    // 通道1: 微信推送 (Server酱)
    if (($notify['wechat_enabled'] ?? false) && !empty($notify['wechat_key'])) {
        $scUrl = "https://sctapi.ftqq.com/{$notify['wechat_key']}.send";
        @file_get_contents($scUrl, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query(['title' => $title, 'desp' => $alertMsg])
            ]
        ]));
    }

    // 通道2: Telegram 推送
    if (($notify['tg_enabled'] ?? false) && !empty($notify['tg_token']) && !empty($notify['tg_chatid'])) {
        $tgUrl = "https://api.telegram.org/bot{$notify['tg_token']}/sendMessage";
        @file_get_contents($tgUrl, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query(['chat_id' => $notify['tg_chatid'], 'text' => $fullContent])
            ]
        ]));
    }

    // 通道3: SMTP 邮件发送 (原生轻量实现)
    if (($notify['email_enabled'] ?? false) && !empty($notify['email_smtp'])) {
        list($smtp_host, $smtp_port) = explode(':', $notify['email_smtp']);
        $smtp_port = $smtp_port ?: 465;
        
        // 此处为兼容纯原生无依赖环境，建议使用系统的 mail 命令或者标准的 PHP mail。
        // 为确保100%成功投递不被拦截，建议大面积通知使用专用的 PHPMailer 库。
        // 这里提供基础的投递尝试标志：
        $to = $notify['email_to'];
        $headers = "From: " . $notify['email_user'] . "\r\n" . "Reply-To: " . $notify['email_user'] . "\r\n" . 'X-Mailer: PHP/' . phpversion();
        @mail($to, $title, $alertMsg, $headers);
    }
    
    echo "🔔 发现异动基金，已触发并发起多渠道推送请求。\n";
} else {
    echo "✅ 检查完毕，当前所有自选基金均在平稳波动区间内。\n";
}
?>