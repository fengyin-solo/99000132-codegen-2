<?php
/**
 * 页面渲染冒烟测试：在沙箱副本中以多种访客身份渲染 detail.php / index.php
 * 真实项目的 config/database.php 不被修改。
 * 用法: /tmp/php tests/test_render.php
 */
error_reporting(E_ALL);
require_once __DIR__ . '/sandbox.php';

define('TEST_DB_FILE', '/tmp/claim_render_' . getmypid() . '.sq3');
@unlink(TEST_DB_FILE);

$pdo = new PDO('sqlite:' . TEST_DB_FILE);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT, nickname TEXT, phone TEXT, visitor_id TEXT,
    type TEXT, title TEXT, content TEXT, image TEXT, status INTEGER DEFAULT 1,
    views INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE admins (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, password TEXT, created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE favorites (id INTEGER PRIMARY KEY AUTOINCREMENT, visitor_id TEXT, message_id INTEGER, created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT, message_id INTEGER, visitor_id TEXT, report_type TEXT,
    description TEXT, status INTEGER DEFAULT 0, processed_by INTEGER, processed_at TEXT,
    process_note TEXT, created_at TEXT DEFAULT (datetime('now')))");
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

// 一条已登记、含三种状态候选的失物留言；一条普通求助
$pdo->exec("INSERT INTO messages (nickname, phone, visitor_id, type, title, content, status, views)
            VALUES ('捡到人','13900000000','visitor-publisher','lost','捡到一串钥匙','详情内容',1,5)");
$lostId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO messages (nickname, phone, visitor_id, type, title, content, status, views)
            VALUES ('张大爷',NULL,'visitor-other','help','楼道灯坏了','内容',1,3)");
$helpId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO lost_found_info (message_id, visitor_id, claim_proof, keep_location)
               VALUES (?, 'visitor-publisher', '钥匙串上有几把钥匙？', '3号楼物业')")
    ->execute([$lostId]);
$pdo->prepare("INSERT INTO lost_claims (message_id, visitor_id, claimant_name, contact, claim_progress, status, is_complete)
               VALUES (?, 'visitor-a', '张三', '1381', '三把钥匙带蓝色门禁卡', 0, 1)")->execute([$lostId]);
$pdo->prepare("INSERT INTO lost_claims (message_id, visitor_id, claimant_name, contact, claim_progress, status, is_complete, review_note, reviewed_at)
               VALUES (?, 'visitor-b', '', '', '', 2, 0, '凭证不足', datetime('now'))")->execute([$lostId]);
$pdo->prepare("INSERT INTO lost_claims (message_id, visitor_id, claimant_name, contact, claim_progress, status, is_complete, review_note, reviewed_at)
               VALUES (?, 'visitor-c', '王五', '1383', '一把红色挂件钥匙', 1, 1, '特征吻合', datetime('now'))")->execute([$lostId]);
$pdo->exec("INSERT INTO lost_claim_logs (claim_id, message_id, visitor_id, action, from_status, to_status)
            VALUES (1, $lostId, 'visitor-a', 'submit', NULL, 0),
                   (2, $lostId, 'visitor-b', 'submit', NULL, 0),
                   (3, $lostId, 'visitor-c', 'submit', NULL, 0),
                   (3, $lostId, 'visitor-publisher', 'confirm', 0, 1)");

$sandbox = provisionSandbox();

$pass = 0; $fail = 0;
function check($cond, $label) {
    global $pass, $fail;
    if ($cond) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

function renderPage($script, array $get, $visitor) {
    global $sandbox;
    $env = [
        'TEST_SANDBOX' => $sandbox,
        'TEST_DB' => TEST_DB_FILE,
        'TEST_VISITOR' => $visitor,
        'TEST_SCRIPT' => $script,
        'TEST_GET' => json_encode($get),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open('/tmp/php ' . escapeshellarg($sandbox . '/render_worker.php'),
        $descriptors, $pipes, $sandbox, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    return [$out, $err];
}

// 发布者视角：含登记信息、全部候选、处理记录、确认/恢复按钮
[$html, $err] = renderPage('detail.php', ['id' => $lostId], 'visitor-publisher');
check($err === '' && strpos($html, 'Fatal error') === false && strpos($html, 'Warning') === false && strpos($html, 'Deprecated') === false,
    '发布者渲染无错误/警告' . ($err ? " -> $err" : ''));
check(strpos($html, '失物认领协同') !== false, '包含协同区块');
check(strpos($html, '已确认认领') !== false, '阶段显示已确认认领');
check(strpos($html, '钥匙串上有几把钥匙') !== false, '显示认领凭证');
check(strpos($html, '3号楼物业') !== false, '显示保管地点');
check(strpos($html, '处理记录') !== false, '发布者可见处理记录');
check(strpos($html, '恢复为待核验') !== false, '已确认候选有恢复按钮');
check(strpos($html, '凭证不完整') !== false, '不完整候选有标记');
check(strpos($html, '联系方式：') !== false, '发布者可见联系方式');

// 失主 A 视角：只看到自己的联系方式、看不到处理记录
[$html, $err] = renderPage('detail.php', ['id' => $lostId], 'visitor-a');
check($err === '', '认领人渲染无错误' . ($err ? " -> $err" : ''));
check(strpos($html, '处理记录') === false, '认领人看不到处理记录');
check(strpos($html, '1383') === false, '认领人看不到他人的联系方式');
check(strpos($html, '1381') !== false, '认领人能看到自己的联系方式');
check(strpos($html, '撤回申请') !== false, '待核验候选可撤回');

// 失主 B（被驳回且不完整）视角：可重新提交
[$html, $err] = renderPage('detail.php', ['id' => $lostId], 'visitor-b');
check(strpos($html, '重新提交认领') !== false, '被驳回的失主可重新提交');

// 纯路人视角
[$html, $err] = renderPage('detail.php', ['id' => $lostId], 'visitor-stranger');
check(strpos($html, '该物品已被认领') !== false, '路人看到已认领提示');
check(strpos($html, '我是失主，提交认领') === false, '已确认阶段路人无提交入口');

// 求助留言详情：不渲染协同区块
[$html, $err] = renderPage('detail.php', ['id' => $helpId], 'visitor-other');
check(strpos($html, '失物认领协同') === false, '求助留言无协同区块');

// 首页：徽标渲染
[$html, $err] = renderPage('index.php', [], 'visitor-stranger');
check($err === '' && strpos($html, 'Warning') === false, '首页渲染无错误/警告' . ($err ? " -> $err" : ''));
check(strpos($html, '🤝 已确认认领') !== false, '首页失物卡片显示认领阶段徽标');

@unlink(TEST_DB_FILE);

echo "\n================\n通过: " . $pass . "，失败: " . $fail . "\n";
exit($fail === 0 ? 0 : 1);
