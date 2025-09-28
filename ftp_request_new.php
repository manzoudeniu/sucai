<?php
// 设置时区
header("Content-Type: application/json; charset=utf-8");
date_default_timezone_set("Asia/Shanghai");

// 获取原始数据
$rawData = file_get_contents("php://input");

// 写入日志
$logFile = __DIR__ . "/request.log";
file_put_contents($logFile, date("Y-m-d H:i:s") . " 接收到数据: " . $rawData . "\n", FILE_APPEND);

// 尝试解析 JSON
$data = json_decode($rawData, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        "errCode" => 1,
        "errormsg" => "JSON 解析失败"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 随机密码生成器
function generatePassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// MySQL 连接信息
$dsn = "mysql:host=localhost;dbname=ftp;charset=utf8mb4";
$user = "root";
$pass = "123456";

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 插入主申请记录
    $stmt = $pdo->prepare("INSERT INTO requests (reqNo, projectCode, Directory, status) VALUES (?, ?, ?, ?)");
    $stmt->execute([$data['reqNo'], $data['projectCode'], $data['Directory'], 'pending']);

    $requestId = $pdo->lastInsertId();

    // 插入 ACL 数据
    $stmtAcl = $pdo->prepare("INSERT INTO request_acl (request_id, Directory, username, FTPAccount) VALUES (?, ?, ?, ?)");
    $stmtUpdateAclStatus = $pdo->prepare("UPDATE request_acl SET status = ? WHERE request_id = ? AND FTPAccount = ?");

    // 返回数据骨架
    $response = [
        "errCode" => 0,
        "errormsg" => "",
        "reqNo" => $data['reqNo'],
        "projectCode" => $data['projectCode'],
        "Directory" => [
            "Directory" => $data['Directory'],
            "createflag" => "Y",
            "createtime" => date("Y/m/d H:i:s")
        ],
        "Account" => [],
        "ACL" => []
    ];

    foreach ($data['ACL'] as $acl) {
        $stmtAcl->execute([$requestId, $acl['Directory'], $acl['username'], $acl['FTPAccount']]);

        // 为该账号生成随机密码
        $password = generatePassword(10);

        // 调用 bash 脚本建立 ftp 账号 (增加密码参数)
        $script = "/usr/local/bin/create_ftp_user.sh";
        $cmd = escapeshellcmd(
            $script . " " .
            escapeshellarg($acl['FTPAccount']) . " " .
            escapeshellarg($acl['Directory']) . " " .
            escapeshellarg($password)
        );
        $output = shell_exec($cmd . " 2>&1");

        file_put_contents($logFile, date("Y-m-d H:i:s") . " 执行脚本: $cmd 输出: $output\n", FILE_APPEND);

        $createflag = (stripos($output, 'success') !== false) ? "Y" : "N";
        $status = ($createflag === "Y") ? "成功" : "失败";
        $stmtUpdateAclStatus->execute([$status, $requestId, $acl['FTPAccount']]);

        // Account 信息
        $response["Account"][] = [
            "username" => $acl['username'],
            "FTPAccount" => $acl['FTPAccount'],
            "createflag" => $createflag,
            "createtime" => date("Y/m/d H:i:s"),
            "Password" => $password
        ];

        // ACL 信息
        $response["ACL"][] = [
            "Directory" => $acl['Directory'],
            "username" => $acl['username'],
            "FTPAccount" => $acl['FTPAccount'],
            "createflag" => $createflag
        ];
    }

    // 更新申请状态为完成
    $stmt = $pdo->prepare("UPDATE requests SET status = '完成' WHERE id = ?");
    $stmt->execute([$requestId]);

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    file_put_contents($logFile, date("Y-m-d H:i:s") . " 数据库错误: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode([
        "errCode" => 2,
        "errormsg" => "数据库操作失败"
    ], JSON_UNESCAPED_UNICODE);
}
