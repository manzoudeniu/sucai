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

        // 判断用户是否存在
        $userExists = (bool)shell_exec("id " . escapeshellarg($acl['FTPAccount']) . " 2>/dev/null");

        // 新用户生成随机密码，旧用户密码置空
        $password = $userExists ? "" : generatePassword(10);

        // 调用 bash 脚本建立/更新 ftp 账号（密码只在新用户传递）
        $script = "/usr/local/bin/create_ftp_user.sh";
        $cmd = escapeshellcmd($script . " " .
            escapeshellarg($acl['username']) . " " .
            escapeshellarg($acl['FTPAccount']) . " " .
            escapeshellarg($password) . " " .
            escapeshellarg($acl['Directory'])
        );
        $output = shell_exec($cmd . " 2>&1");

        // 写日志
        file_put_contents($logFile, date("Y-m-d H:i:s") . " 执行脚本: $cmd 输出: $output\n", FILE_APPEND);

        // Account 信息
        $response["Account"][] = [
            "username" => $acl['username'],
            "FTPAccount" => $acl['FTPAccount'],
            "createflag" => "Y",
            "createtime" => date("Y/m/d H:i:s"),
            "Password" => $password
        ];

        // ACL 信息
        $response["ACL"][] = [
            "Directory" => $acl['Directory'],
            "username" => $acl['username'],
            "FTPAccount" => $acl['FTPAccount'],
            "createflag" => "Y"
        ];

        // 更新 ACL 状态为成功
        $stmtUpdateAclStatus->execute(['成功', $requestId, $acl['FTPAccount']]);
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


#!/bin/bash
# create_ftp_user.sh
# 可重复执行，不覆盖已存在用户密码，ACL直接覆盖，不清空原有 ACL

USERNAME="$1"     # 中文用户名（仅日志）
FTPACCOUNT="$2"   # FTP 账号
PASSWORD="$3"     # 密码
DIRECTORY="$4"    # 用户目录

LOGFILE="/var/log/create_ftp_user.log"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Start create/update FTP user: $FTPACCOUNT, DIR=$DIRECTORY" >> $LOGFILE

# 1. 检查用户是否存在
id "$FTPACCOUNT" &>/dev/null
if [ $? -ne 0 ]; then
    # 用户不存在，创建用户（禁止 shell 登录）
    useradd -d "$DIRECTORY" -s /sbin/nologin "$FTPACCOUNT" >> $LOGFILE 2>&1
    if [ $? -ne 0 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Warning: useradd failed, continue..." >> $LOGFILE
    else
        # 设置密码，仅在用户新建时
        echo "$FTPACCOUNT:$PASSWORD" | chpasswd >> $LOGFILE 2>&1
    fi
fi

# 2. 创建目录（如果不存在）
mkdir -p "$DIRECTORY"

# 3. 设置 ACL 权限（直接覆盖该用户的权限，不清空其他 ACL）
setfacl -m u:$FTPACCOUNT:rwx "$DIRECTORY"       # 给用户 rwx
setfacl -d -m u:$FTPACCOUNT:rwx "$DIRECTORY"    # 默认 ACL（新建文件继承）

# 4. 日志记录
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Finished setting ACL for $FTPACCOUNT on $DIRECTORY" >> $LOGFILE

# 5. 输出结果给 PHP 判断
echo "success"

exit 0







