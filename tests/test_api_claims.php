<?php
/**
 * api/claim.php 端到端测试（在沙箱副本中驱动真实的 api/claim.php 入口）
 * 用法: /tmp/php tests/test_api_claims.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/sandbox.php';

// 会话与输入环境
session_id('test-session');
$_SESSION = [];
$_COOKIE = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'phpunit';

define('TEST_DB_FILE', '/tmp/claim_e2e_' . getmypid() . '.sq3');
@unlink(TEST_DB_FILE);

$sandbox = provisionSandbox();

function createSchema() {
    $pdo = new PDO('sqlite:' . TEST_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, nickname TEXT, phone TEXT, visitor_id TEXT,
        type TEXT, title TEXT, content TEXT, image TEXT, status INTEGER DEFAULT 1,
        views INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE lost_found_info (
        id INTEGER PRIMARY KEY AUTOINCREMENT, message_id INTEGER UNIQUE, visitor_id TEXT,
        claim_proof TEXT, keep_location TEXT,
        created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE lost_claims (
        id INTEGER PRIMARY KEY AUTOINCREMENT, message_id INTEGER, visitor_id TEXT,
        claimant_name TEXT, contact TEXT, claim_progress TEXT,
        status INTEGER DEFAULT 0, is_complete INTEGER DEFAULT 0,
        review_note TEXT, reviewed_at TEXT,
        created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')),
        UNIQUE (visitor_id, message_id))");
    $pdo->exec("CREATE TABLE lost_claim_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, claim_id INTEGER, message_id INTEGER,
        visitor_id TEXT, action TEXT, from_status INTEGER, to_status INTEGER, note TEXT,
        created_at TEXT DEFAULT (datetime('now')))");
    $stmt = $pdo->prepare("INSERT INTO messages (nickname, phone, visitor_id, type, title, content, status)
                          VALUES ('捡到人', '139', ?, 'lost', '捡到钥匙', '一串钥匙', 1)");
    $stmt->execute(['visitor-publisher']);
    return (int)$pdo->lastInsertId();
}
$messageId = createSchema();

$pass = 0; $fail = 0;
function check($cond, $label) {
    global $pass, $fail;
    if ($cond) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

/**
 * 以指定访客身份发起一次 API 调用，返回解码后的 JSON
 */
function apiCall($visitor, array $post) {
    global $sandbox;
    $cmd = '/tmp/php ' . escapeshellarg($sandbox . '/api_worker.php');
    $env = [
        'TEST_SANDBOX' => $sandbox,
        'TEST_VISITOR' => $visitor,
        'TEST_DB' => TEST_DB_FILE,
        'TEST_POST' => json_encode($post, JSON_UNESCAPED_UNICODE),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $sandbox, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $json = json_decode($out, true);
    if ($json === null) {
        return ['code' => -1, 'msg' => '无效JSON: ' . $out . ' / ' . $err, 'data' => null];
    }
    return $json;
}

/* ---- 流程：发布者登记 ---- */
echo "E2E：登记凭证\n";
$r = apiCall('visitor-publisher', [
    'action' => 'register', 'message_id' => $messageId,
    'claim_proof' => '钥匙串有几把钥匙？', 'keep_location' => '物业前台',
]);
check($r['code'] === 0, '发布者登记成功: ' . ($r['msg'] ?? ''));
check($r['data']['collab']['stage'] === 'waiting', '登记后阶段为等待认领');

$r = apiCall('visitor-thief', [
    'action' => 'register', 'message_id' => $messageId,
    'claim_proof' => 'x', 'keep_location' => 'y',
]);
check($r['code'] === 1, '非发布者登记被拒绝');

/* ---- 失主 A 完整申请 ---- */
echo "E2E：失主申请\n";
$r = apiCall('visitor-ownerA', [
    'action' => 'apply', 'message_id' => $messageId,
    'claimant_name' => '张三', 'contact' => '13800001111', 'claim_progress' => '三把钥匙加一个蓝色门禁卡',
]);
check($r['code'] === 0 && empty($r['data']['incomplete']), '完整申请提交成功: ' . $r['msg']);
check($r['data']['collab']['stage'] === 'verifying', '详情返回阶段为待核验');
check(count($r['data']['collab']['candidates']) === 1, '候选列表 1 人，与进度一致');
$claimA = $r['data']['collab']['my_claim']['id'];

/* ---- 失主 B 凭证不完整 ---- */
$r = apiCall('visitor-ownerB', [
    'action' => 'apply', 'message_id' => $messageId,
    'claimant_name' => '', 'contact' => '', 'claim_progress' => '',
]);
check($r['code'] === 0 && !empty($r['data']['incomplete']), '不完整申请 code=0 且标记 incomplete: ' . $r['msg']);
check($r['data']['collab']['stage'] === 'verifying', '不完整仍停在待核验');
check(count($r['data']['collab']['candidates']) === 2, '候选列表 2 人');
check($r['data']['collab']['incomplete_count'] === 1, '不完整计数 1');
$claimB = $r['data']['collab']['my_claim']['id'];

/* ---- 网络失败后重试：同内容/篡改内容都不覆盖 ---- */
echo "E2E：重试幂等\n";
$r = apiCall('visitor-ownerB', [
    'action' => 'apply', 'message_id' => $messageId,
    'claimant_name' => '李四（重试）', 'contact' => '13900000000', 'claim_progress' => '试图覆盖',
]);
check(!empty($r['data']['duplicated']), '重试识别为重复申请');
check(count($r['data']['collab']['candidates']) === 2, '候选仍为 2 人，未新增');
$b = null;
foreach ($r['data']['collab']['candidates'] as $c) { if ((int)$c['id'] === (int)$claimB) $b = $c; }
check($b && $b['claimant_name'] === '', '已有候选不被重试覆盖');

/* ---- 不完整不能确认；驳回可以 ---- */
echo "E2E：核验权限\n";
$r = apiCall('visitor-ownerA', [
    'action' => 'review', 'message_id' => $messageId,
    'claim_id' => $claimA, 'review_action' => 'confirm',
]);
check($r['code'] === 1, '非发布者确认被拒绝');

$r = apiCall('visitor-publisher', [
    'action' => 'review', 'message_id' => $messageId,
    'claim_id' => $claimB, 'review_action' => 'confirm',
]);
check($r['code'] === 1 && mb_strpos($r['msg'], '凭证不完整') !== false, '不完整候选不能确认: ' . $r['msg']);

/* ---- B 补充凭证 ---- */
$r = apiCall('visitor-ownerB', [
    'action' => 'supplement', 'message_id' => $messageId,
    'claim_id' => $claimB, 'claimant_name' => '李四', 'contact' => '13800002222',
    'claim_progress' => '两把钥匙，带红色挂件',
]);
check($r['code'] === 0, '补充凭证成功: ' . $r['msg']);

/* ---- 发布者确认 A ---- */
$r = apiCall('visitor-publisher', [
    'action' => 'review', 'message_id' => $messageId,
    'claim_id' => $claimA, 'review_action' => 'confirm',
]);
check($r['code'] === 0, '确认 A 成功');
check($r['data']['collab']['stage'] === 'confirmed', '阶段流转为已确认');
check($r['data']['collab']['candidates'][0]['id'] == $claimA && $r['data']['collab']['candidates'][0]['status_label'] === '已确认',
    '候选列表首位同步为已确认');
check(count($r['data']['collab']['logs']) >= 4, '处理记录保留完整（' . count($r['data']['collab']['logs']) . ' 条）');

/* ---- 恢复，阶段回到待核验 ---- */
$r = apiCall('visitor-publisher', [
    'action' => 'review', 'message_id' => $messageId,
    'claim_id' => $claimA, 'review_action' => 'restore',
]);
check($r['code'] === 0, '恢复成功');
check($r['data']['collab']['stage'] === 'verifying', '恢复后详情阶段回到待核验');
check($r['data']['collab']['candidates'][0]['status_label'] === '待核验', '候选列表同步回到待核验');

/* ---- 非发布者看不到处理记录 ---- */
$r = apiCall('visitor-ownerA', ['action' => 'apply', 'message_id' => $messageId,
    'claimant_name' => 'x', 'contact' => 'y', 'claim_progress' => 'z']); // 重复申请，仅为取 collab
check($r['data']['collab']['is_publisher'] === false && count($r['data']['collab']['logs']) === 0,
    '普通访客详情不返回处理记录');

echo "\n================\n通过: " . $pass . "，失败: " . $fail . "\n";
@unlink(TEST_DB_FILE);
exit($fail === 0 ? 0 : 1);
