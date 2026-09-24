<?php
/**
 * 失物认领协同领域逻辑功能测试（用 SQLite 内存库模拟 MySQL，SQL 方言均为可移植写法）
 * 用法: /tmp/php tests/test_claims.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$GLOBALS['__test_pdo'] = null;

class TestPDO extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = []) {
        // SQLite 不认识 FOR UPDATE（仅 MySQL 行锁语义），测试中剥除；NOW() 换为 CURRENT_TIMESTAMP
        $query = preg_replace('/\s+FOR\s+UPDATE/i', '', $query);
        $query = preg_replace('/\bNOW\(\)/i', "datetime('now')", $query);
        return parent::prepare($query, $options);
    }
}

function getDB() {
    if ($GLOBALS['__test_pdo'] === null) {
        $pdo = new TestPDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nickname TEXT, phone TEXT, visitor_id TEXT,
            type TEXT, title TEXT, content TEXT, image TEXT,
            status INTEGER DEFAULT 1, views INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )");
        $pdo->exec("CREATE TABLE lost_found_info (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER UNIQUE,
            visitor_id TEXT,
            claim_proof TEXT,
            keep_location TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )");
        $pdo->exec("CREATE TABLE lost_claims (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER,
            visitor_id TEXT,
            claimant_name TEXT,
            contact TEXT,
            claim_progress TEXT,
            status INTEGER DEFAULT 0,
            is_complete INTEGER DEFAULT 0,
            review_note TEXT,
            reviewed_at TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            UNIQUE (visitor_id, message_id)
        )");
        $pdo->exec("CREATE TABLE lost_claim_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            claim_id INTEGER,
            message_id INTEGER,
            visitor_id TEXT,
            action TEXT,
            from_status INTEGER,
            to_status INTEGER,
            note TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $GLOBALS['__test_pdo'] = $pdo;
    }
    return $GLOBALS['__test_pdo'];
}

// 会话/访客模拟：不加载真实 functions.php 里的 session_start，直接内联所需函数
function getVisitorId() { return $GLOBALS['__visitor']; }
function cleanInput($s) { return htmlspecialchars(trim((string)$s), ENT_QUOTES, 'UTF-8'); }
function timeAgo($d) { return $d; }
function getTypeIcon($t) { return ''; }
function isFavorited($id) { return false; }
function hasReported($id) { return false; }

// 提取 functions.php 中失物认领协同段落（从标记注释到文件末尾）
$src = file_get_contents(__DIR__ . '/../includes/functions.php');
$start = strpos($src, '/* ===================== 失物认领协同');
$domain = substr($src, $start);
eval($domain);

$pass = 0;
$fail = 0;
function check($cond, $label) {
    global $pass, $fail;
    if ($cond) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}
function setVisitor($v) { $GLOBALS['__visitor'] = $v; }
function insertMessage($visitorId, $type = 'lost', $status = 1) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO messages (nickname, phone, visitor_id, type, title, content, status)
                         VALUES ('捡到人', '139', ?, ?, '测试', '内容', ?)");
    $stmt->execute([$visitorId, $type, $status]);
    return (int)$db->lastInsertId();
}
function claimStatus($messageId, $visitorId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM lost_claims WHERE message_id = ? AND visitor_id = ?");
    $stmt->execute([$messageId, $visitorId]);
    return $stmt->fetch() ?: null;
}
function expectException($fn, $needle, $label) {
    global $pass, $fail;
    try {
        $fn();
        echo "  ✘ $label（未抛出异常）\n"; $fail++;
    } catch (Exception $e) {
        if (mb_strpos($e->getMessage(), $needle) !== false) {
            echo "  ✔ $label\n"; $pass++;
        } else {
            echo "  ✘ $label（异常信息不符: {$e->getMessage()}）\n"; $fail++;
        }
    }
}

/* ---------- 场景 1：发布者登记凭证与保管地点 ---------- */
echo "场景1：发布者登记\n";
setVisitor('publisher-A');
$mid = insertMessage('publisher-A');
$info = registerLostFoundInfo($mid, '说明包内物品', '物业前台');
check($info['keep_location'] === '物业前台', '登记成功');

setVisitor('stranger-B');
expectException(function () use ($mid) {
    registerLostFoundInfo($mid, 'x', 'y');
}, '只有发布者', '非发布者不能登记');

setVisitor('publisher-A');
registerLostFoundInfo($mid, '新凭证问题', '保安亭');
$db = getDB();
check((int)$db->query("SELECT COUNT(*) FROM lost_found_info")->fetchColumn() === 1, '重复登记更新而不是新增');

/* ---------- 场景 2：多人按提交先后成为候选 ---------- */
echo "场景2：多人申请，按提交先后\n";
setVisitor('owner-1');
submitLostClaim($mid, '张三', '13800000001', '蓝色书包，内有数学课本');
usleep(1100000); // 保证 created_at 可区分（SQLite datetime 精度为秒）
setVisitor('owner-2');
submitLostClaim($mid, '李四', '13800000002', '黑色钱包');

$collab = getLostClaimCollab($mid);
check($collab['stage'] === 'verifying', '有待核验候选时阶段为「待核验」');
check($collab['candidate_count'] === 2, '候选共 2 人');
check($collab['candidates'][0]['claimant_name'] === '张三', '候选按提交先后：张三在前');
check($collab['candidates'][1]['claimant_name'] === '李四', '候选按提交先后：李四在后');

/* ---------- 场景 3：凭证不完整停在待核验，且不能被确认 ---------- */
echo "场景3：凭证不完整\n";
setVisitor('owner-3');
submitLostClaim($mid, '', '', '');
$c3 = claimStatus($mid, 'owner-3');
check($c3 !== null && (int)$c3['status'] === 0 && (int)$c3['is_complete'] === 0, '不完整申请已登记且停在待核验');

$collab = getLostClaimCollab($mid);
check($collab['candidate_count'] === 3, '候选共 3 人（不完整也入列）');
check($collab['incomplete_count'] === 1, '不完整候选计数为 1');
check($collab['stage'] === 'verifying', '阶段仍为待核验');

setVisitor('publisher-A');
expectException(function () use ($c3) {
    reviewLostClaim((int)$c3['id'], 'confirm');
}, '凭证不完整', '发布者不能确认凭证不完整的申请');

/* ---------- 场景 4：补充凭证后可确认 ---------- */
echo "场景4：补充凭证并确认\n";
setVisitor('owner-3');
supplementLostClaim((int)$c3['id'], '王五', '13800000003', '补充：红色雨伞，伞柄有贴纸');
$c3b = claimStatus($mid, 'owner-3');
check((int)$c3b['is_complete'] === 1 && (int)$c3b['status'] === 0, '补充后完整且仍待核验');
$order = array_column(getLostClaimCandidates($mid), 'visitor_id');
check($order === ['owner-1', 'owner-2', 'owner-3'], '补充凭证不改变排队顺序');

setVisitor('publisher-A');
reviewLostClaim((int)$c3b['id'], 'confirm');
$collab = getLostClaimCollab($mid);
check($collab['stage'] === 'confirmed', '确认后阶段为「已确认认领」');

/* ---------- 场景 5：状态流转/恢复 ---------- */
echo "场景5：恢复为待核验\n";
reviewLostClaim((int)$c3b['id'], 'restore');
$collab = getLostClaimCollab($mid);
check($collab['stage'] === 'verifying', '恢复后阶段回到待核验');
$c3c = claimStatus($mid, 'owner-3');
check((int)$c3c['status'] === 0 && $c3c['review_note'] === null, '恢复后候选状态为待核验且清空备注');

/* ---------- 场景 6：驳回第一个，失主重新提交 ---------- */
echo "场景6：驳回与重新提交\n";
reviewLostClaim((int)$collab['candidates'][0]['id'], 'reject', '特征不符');
setVisitor('owner-1');
$r = submitLostClaim($mid, '张三（补充）', '13800000001', '蓝色书包，数学课本写有班级三年二班');
check($r['duplicated'] === false, '被驳回后允许重新提交');
$c1 = claimStatus($mid, 'owner-1');
check((int)$c1['status'] === 0, '重新提交后恢复待核验');

/* ---------- 场景 7：重复申请 / 网络重试不覆盖 ---------- */
echo "场景7：重复申请与网络重试幂等\n";
setVisitor('owner-2');
$before = claimStatus($mid, 'owner-2');
$r1 = submitLostClaim($mid, '李四-重试内容', '13999999999', '被重试覆盖的内容');
check($r1['duplicated'] === true, '重复申请返回 duplicated');
$after = claimStatus($mid, 'owner-2');
check($after['claim_progress'] === $before['claim_progress'], '重试不覆盖已有候选内容');
check($after['contact'] === $before['contact'], '重试不覆盖联系方式');
check((int)$after['status'] === 0, '重复申请停在待核验');
$cnt = (int)getDB()->query("SELECT COUNT(*) FROM lost_claims WHERE visitor_id='owner-2'")->fetchColumn();
check($cnt === 1, '同一访客只有一条候选');

// 不完整申请的重试也不覆盖
setVisitor('owner-3');
// 先驳回再以不完整内容重新提交，制造一个待核验的不完整候选
setVisitor('publisher-A');
reviewLostClaim((int)$c3b['id'], 'reject');
setVisitor('owner-3');
submitLostClaim($mid, '', '', '');
$inc = claimStatus($mid, 'owner-3');
check((int)$inc['is_complete'] === 0, '构造出不完整候选');
$r2 = submitLostClaim($mid, '王五重试', '138', '完整重试内容');
check($r2['duplicated'] === true, '待核验中的不完整候选重试也视为重复');
$inc2 = claimStatus($mid, 'owner-3');
check((int)$inc2['is_complete'] === 0 && $inc2['claimant_name'] === '', '不完整候选不被重试覆盖');

/* ---------- 场景 8：处理记录完整保留 ---------- */
echo "场景8：处理记录\n";
$logs = getLostClaimLogs($mid);
$actions = array_column($logs, 'action');
foreach (['submit', 'supplement', 'confirm', 'restore', 'reject', 'change'] as $a) {
    check(in_array($a, $actions), "处理记录包含 $a");
}

/* ---------- 场景 9：撤回后重新提交 ---------- */
echo "场景9：撤回\n";
setVisitor('owner-2');
withdrawLostClaim((int)$before['id']);
check((int)claimStatus($mid, 'owner-2')['status'] === 3, '撤回后状态为已撤回');
$collab = getLostClaimCollab($mid);
// owner-1 待核验、owner-3 待核验不完整 => 仍 verifying
check($collab['stage'] === 'verifying', '仍有待核验候选时阶段不变');
setVisitor('owner-2');
submitLostClaim($mid, '李四', '13800000002', '黑色钱包，内有身份证');
check((int)claimStatus($mid, 'owner-2')['status'] === 0, '撤回后可重新提交并回到待核验');

/* ---------- 场景 10：非发布者无权核验、非本人无权撤回 ---------- */
echo "场景10：权限\n";
setVisitor('owner-1');
expectException(function () use ($c1) {
    reviewLostClaim((int)$c1['id'], 'confirm');
}, '只有发布者', '非发布者不能核验');
setVisitor('owner-2');
expectException(function () use ($c1) {
    withdrawLostClaim((int)$c1['id']);
}, '只能撤回自己', '不能撤回他人申请');

/* ---------- 场景 11：阶段一致性 ---------- */
echo "场景11：候选列表与认领进度一致\n";
setVisitor('publisher-A');
$ids = array_column(getLostClaimCandidates($mid), 'id');
// 驳回全部候选 => waiting
foreach ($ids as $cid) {
    $c = getDB()->query("SELECT status FROM lost_claims WHERE id=$cid")->fetch();
    if ((int)$c['status'] === 0) {
        reviewLostClaim((int)$cid, 'reject');
    }
}
$collab = getLostClaimCollab($mid);
check($collab['stage'] === 'waiting', '无待核验/确认候选时回到等待认领');
$map = getClaimStageMap([$mid, 99999]);
check(isset($map[$mid]) && $map[$mid]['stage'] === 'waiting', '批量阶段映射与详情一致');
check(!isset($map[99999]), '未登记认领的留言不出现在阶段映射');

/* ---------- 场景 12：非失物招领 / 未审核留言 ---------- */
echo "场景12：边界\n";
setVisitor('publisher-A');
$helpId = insertMessage('publisher-A', 'help');
check(getLostClaimCollab($helpId) === null, '求助留言无认领协同');
$pendingId = insertMessage('publisher-A', 'lost', 0);
check(getLostClaimCollab($pendingId) === null, '未审核留言无认领协同');

echo "\n================\n通过: " . $pass . "，失败: " . $fail . "\n";
exit($fail === 0 ? 0 : 1);
