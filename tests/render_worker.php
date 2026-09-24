<?php
/**
 * 页面渲染工作进程：在临时沙箱目录（TEST_SANDBOX）中包含真实页面副本
 * 真实项目的 config/database.php 不会被修改
 */
error_reporting(E_ALL);

$sandbox = getenv('TEST_SANDBOX');
if (!$sandbox || !is_dir($sandbox)) {
    fwrite(STDERR, "TEST_SANDBOX 未设置\n");
    exit(1);
}
$root = $sandbox;

$visitor = getenv('TEST_VISITOR');
$script = getenv('TEST_SCRIPT');
$_GET = json_decode(getenv('TEST_GET'), true) ?: [];
$_REQUEST = $_GET;
$_COOKIE = ['visitor_id' => $visitor];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'phpunit';
$_SERVER['REQUEST_URI'] = '/' . $script . '?' . http_build_query($_GET);
$_SERVER['SCRIPT_NAME'] = '/' . $script;

chdir($root);
include $root . '/' . basename($script);
