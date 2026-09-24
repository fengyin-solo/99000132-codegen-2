<?php
/**
 * API 测试工作进程：由 test_api_claims.php 以子进程方式调用
 *
 * 在临时沙箱目录（TEST_SANDBOX）中运行：父进程已把整个项目复制进去并写入
 * SQLite 版 config/database.php，因此真实项目的配置文件永远不会被修改。
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$sandbox = getenv('TEST_SANDBOX');
if (!$sandbox || !is_dir($sandbox)) {
    fwrite(STDERR, "TEST_SANDBOX 未设置\n");
    exit(1);
}

$root = $sandbox;

// 模拟会话/访客
$visitor = getenv('TEST_VISITOR');
$_SESSION = ['visitor_id' => $visitor];
$_COOKIE['visitor_id'] = $visitor;
$_POST = json_decode(getenv('TEST_POST'), true) ?: [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'phpunit';

chdir($root);
include $root . '/api/claim.php';
