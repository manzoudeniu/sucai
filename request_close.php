 {"reqNo":"DC140CD761BF6B4648258CF50027904E","closeType":"ACL","closeReason":"超过最晚审查时间未选择保持","ACL":[{"Directory":"XXXX\/1266","username":"A.L. Chen\/TAIWAN\/MXIC","FTPAccount":"alchen"}]}
成功返回
{"reqNo":"DC140CD761BF6B4648258CF50027904E","closeType":"ACL","closeReason":"超过最晚审查时间未选择保持","ACL":[{"Directory":"XXXX\/1266","username":"A.L. Chen\/TAIWAN\/MXIC","FTPAccount":"alchen"，"closeflag":"Y","closetime":"2025\/09\/23 23:00:00"}]}


<?php
header("Content-Type: application/json; charset=utf-8");
date_default_timezone_set("Asia/Shanghai");

// 获取原始数据
$rawData = file_get_contents("php://input");

// 写日志
$logFile = __DIR__ . "/ftp_close_request.log";
file_put_contents($logFile, date("Y-m-d H:i:s") . " 接收到关闭申请: " . $rawData . "\n", FILE_APPEND);

// 解析 JSON
$data = json_decode($rawData, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        "errCode" => 1,
        "errormsg" => "JSON 解析失败"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 当前操作时间
$closetime = date("Y/m/d H:i:s");

// 数据库配置
$dsn = "mysql:host=localhost;dbname=ftp;charset=utf8mb4";
$user = "root";
$pass = "123456";

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 插入关闭申请表
    $stmt = $pdo->prepare("INSERT INTO ftp_close_requests (reqNo, closeType, closeReason, status) VALUES (?, ?, ?, ?)");
    $stmt->execute([$data['reqNo'], $data['closeType'], $data['closeReason'], 'pending']);
    $requestId = $pdo->lastInsertId();

    // 插入 ACL 明细
    $stmtAcl = $pdo->prepare("INSERT INTO ftp_close_acl (request_id, Directory, username, FTPAccount, status, closetime) VALUES (?, ?, ?, ?, ?, ?)");
    
    $responseAcl = [];

    foreach ($data['ACL'] as $acl) {
        $stmtAcl->execute([$requestId, $acl['Directory'], $acl['username'], $acl['FTPAccount'], '成功', $closetime]);

        // 调用关闭脚本
        $script = "/usr/local/bin/close_ftp_user.sh";
        $cmd = escapeshellcmd($script . " " .
            escapeshellarg($acl['FTPAccount']) . " " .
            escapeshellarg($acl['Directory'])
        );
        $output = shell_exec($cmd . " 2>&1");

        file_put_contents($logFile, date("Y-m-d H:i:s") . " 执行脚本: $cmd 输出: $output\n", FILE_APPEND);

        $responseAcl[] = [
            "Directory" => $acl['Directory'],
            "username" => $acl['username'],
            "FTPAccount" => $acl['FTPAccount'],
            "closeflag" => "Y",
            "closetime" => $closetime
        ];
    }

    // 更新关闭申请状态为完成
    $stmt = $pdo->prepare("UPDATE ftp_close_requests SET status = '完成' WHERE id = ?");
    $stmt->execute([$requestId]);

    // 返回给客户端
    $response = [
        "errCode" => 0,
        "errormsg" => "",
        "reqNo" => $data['reqNo'],
        "closeType" => $data['closeType'],
        "closeReason" => $data['closeReason'],
        "ACL" => $responseAcl
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    file_put_contents($logFile, date("Y-m-d H:i:s") . " 数据库错误: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode([
        "errCode" => 2,
        "errormsg" => "数据库操作失败"
    ], JSON_UNESCAPED_UNICODE);
}

CREATE TABLE ftp_close_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reqNo VARCHAR(50) NOT NULL UNIQUE,       -- 申请单号
    closeType VARCHAR(20) NOT NULL,          -- 关闭类型（例如 ACL）
    closeReason VARCHAR(255) NOT NULL,       -- 关闭原因
    status ENUM('pending','完成','失败') DEFAULT 'pending',  -- 申请状态
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ftp_close_acl (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,                  -- 关联 ftp_close_requests 表
    Directory VARCHAR(255) NOT NULL,         -- 目录
    username VARCHAR(255) NOT NULL,          -- 用户中文名
    FTPAccount VARCHAR(100) NOT NULL,        -- FTP 账号
    status ENUM('pending','成功','失败') DEFAULT 'pending', -- 执行状态
    closetime DATETIME NOT NULL,             -- 本次关闭操作时间
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES ftp_close_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



