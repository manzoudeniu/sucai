<?php
// 设置时区
header("Content-Type: application/json; charset=utf-8");
date_default_timezone_set("Asia/Shanghai");

// 获取原始数据
$rawData = file_get_contents("php://input");

// 写入日志
$logFile = __DIR__ . "/request.log";
file_put_contents($logFile, date("Y-m-d H:i:s") . " 接收到数据: " . $rawData . "\n", FILE_APPEND);

// 返回给客户端，表示数据已接收
header('Content-Type: application/json');

// 尝试解析 JSON
$data = json_decode($rawData, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(["status" => "error", "message" => "JSON 解析失败"],JSON_UNESCAPED_UNICODE);
    exit;
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
    foreach ($data['ACL'] as $acl) {
        $stmtAcl->execute([$requestId, $acl['Directory'], $acl['username'], $acl['FTPAccount']]);

        // 调用 bash 脚本建立 ftp 账号
        $script = "/usr/local/bin/create_ftp_user.sh";
        $cmd = escapeshellcmd($script . " " . escapeshellarg($acl['FTPAccount']) . " " . escapeshellarg($acl['Directory']));
        $output = shell_exec($cmd . " 2>&1");

        // 写 log
        file_put_contents($logFile, date("Y-m-d H:i:s") . " 执行脚本: $cmd 输出: $output\n", FILE_APPEND);
        if (stripos($output, 'success') !== false) {
        $stmtUpdateAclStatus->execute(['成功', $requestId, $acl['FTPAccount']]);
        }else{
        $stmtUpdateAclStatus->execute(['失败', $requestId, $acl['FTPAccount']]);
        }

    }

    // 更新申请状态为完成
    $stmt = $pdo->prepare("UPDATE requests SET status = '完成' WHERE id = ?");
    $stmt->execute([$requestId]);

    echo json_encode(["status" => "success", "message" => "执行成功", "reqNo" => $data['reqNo']],JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    file_put_contents($logFile, date("Y-m-d H:i:s") . " 数据库错误: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(["status" => "error", "message" => "数据库操作失败"],JSON_UNESCAPED_UNICODE);
}



客户端请求数据格式
: {"reqNo":"FTPR202509-06","projectCode":"5678","Directory":"\/MXIC\/project\/5678","ACL":[{"Directory":"\/MXIC\/project\/5678","username":"Betty Wu\/TAIWAN\/MXIC","FTPAccount":"bettywu01"},{"Directory":"\/MXIC\/project\/5678","username":"Bin Wu\/CHINA\/MXIC","FTPAccount":"binwu01"}]}
客户端返回数据格式
 {"errCode":0,"errormsg":"","reqNo":"FTPR202509-06","projectCode":"5678","Directory":{"Directory":"\/MXIC\/project\/5678","createflag":"Y","createtime":"2025\/09\/28 14:00:00"},"Account":[{"username":"Betty Wu\/TAIWAN\/MXIC","FTPAccount":"bettywu01","createflag":"Y","createtime":"2025\/09\/28 14:00:00","Password":"123qweASD"},{"username":"Bin Wu\/CHINA\/MXIC","FTPAccount":"binwu01","createflag":"Y","createtime":"2025\/09\/28 14:00:00","Password":"123qweASD"}],"ACL":[{"Directory":"\/MXIC\/project\/5678","username":"Betty Wu\/TAIWAN\/MXIC","FTPAccount":"bettywu01","createflag":"Y"},{"Directory":"\/MXIC\/project\/5678","username":"Bin Wu\/CHINA\/MXIC","FTPAccount":"binwu01","createflag":"Y"}]}

数据库格式
CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reqNo VARCHAR(50) NOT NULL UNIQUE,        
    projectCode VARCHAR(50) NOT NULL,         
    Directory VARCHAR(255) NOT NULL,          
    status ENUM('pending','完成','失败') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE request_acl (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,                  
    Directory VARCHAR(255) NOT NULL,        
    username VARCHAR(255) NOT NULL,           
    FTPAccount VARCHAR(100) NOT NULL,         
    status ENUM('pending','成功','失败') DEFAULT 'pending',  
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;





