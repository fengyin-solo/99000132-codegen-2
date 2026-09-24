<?php
session_start();

/**
 * 返回JSON响应
 */
function jsonResponse($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取留言类型文字
 */
function getTypeLabel($type) {
    $map = ['help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return $map[$type] ?? '其他';
}

/**
 * 获取类型图标
 */
function getTypeIcon($type) {
    $map = ['help' => '🆘', 'suggest' => '💡', 'lost' => '🔍'];
    return $map[$type] ?? '📌';
}

/**
 * 获取状态文字
 */
function getStatusLabel($status) {
    $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
    return $map[$status] ?? '未知';
}

/**
 * 获取状态样式类
 */
function getStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    return $map[$status] ?? '';
}

/**
 * 时间格式化
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . '个月前';
    if ($diff->d > 0) return $diff->d . '天前';
    if ($diff->h > 0) return $diff->h . '小时前';
    if ($diff->i > 0) return $diff->i . '分钟前';
    return '刚刚';
}

/**
 * 检查管理员登录
 */
function requireAdmin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * 过滤输入
 */
function cleanInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * 获取访客唯一标识
 * 基于session和cookie实现匿名用户标识
 */
function getVisitorId() {
    if (empty($_SESSION['visitor_id'])) {
        if (!empty($_COOKIE['visitor_id'])) {
            $_SESSION['visitor_id'] = $_COOKIE['visitor_id'];
        } else {
            $visitorId = md5(uniqid('visitor_', true) . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $_SESSION['visitor_id'] = $visitorId;
            setcookie('visitor_id', $visitorId, time() + 86400 * 365, '/');
        }
    }
    return $_SESSION['visitor_id'];
}

/**
 * 检查留言是否已收藏
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove']
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => false, 'action' => 'remove'];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id) VALUES (?, ?)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add'];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 获取举报类型文字
 */
function getReportTypeLabel($type) {
    $map = [
        'spam' => '垃圾信息',
        'abuse' => '辱骂攻击',
        'illegal' => '违法违规',
        'porn' => '色情低俗',
        'other' => '其他'
    ];
    return $map[$type] ?? '未知';
}

/**
 * 获取举报状态文字
 */
function getReportStatusLabel($status) {
    $map = [
        0 => '待处理',
        1 => '已处理-已删除',
        2 => '已处理-已忽略',
        3 => '已驳回'
    ];
    return $map[$status] ?? '未知';
}

/**
 * 获取举报状态样式类
 */
function getReportStatusClass($status) {
    $map = [
        0 => 'pending',
        1 => 'resolved-deleted',
        2 => 'resolved-ignored',
        3 => 'rejected'
    ];
    return $map[$status] ?? '';
}

/**
 * 检查当前访客是否已举报过某条留言
 */
function hasReported($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 提交举报
 */
function submitReport($messageId, $reportType, $description = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $validTypes = ['spam', 'abuse', 'illegal', 'porn', 'other'];
    if (!in_array($reportType, $validTypes)) {
        throw new Exception('无效的举报类型');
    }

    $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    if (!$stmt->fetch()) {
        throw new Exception('留言不存在或未通过审核');
    }

    if (hasReported($messageId)) {
        throw new Exception('您已经举报过这条留言了');
    }

    $stmt = $db->prepare("INSERT INTO reports (message_id, visitor_id, report_type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$messageId, $visitorId, $reportType, $description]);

    return $db->lastInsertId();
}

/**
 * 获取待处理举报数量
 */
function getPendingReportCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn();
}

/* ===================== 失物认领协同 ===================== */

/**
 * 认领候选状态文字
 */
function getClaimStatusLabel($status) {
    $map = [0 => '待核验', 1 => '已确认', 2 => '已驳回', 3 => '已撤回'];
    return $map[$status] ?? '未知';
}

/**
 * 认领候选状态样式类
 */
function getClaimStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected', 3 => 'withdrawn'];
    return $map[$status] ?? '';
}

/**
 * 办理阶段：none未登记, waiting等待认领, verifying待核验, confirmed已确认
 * 阶段完全由认领凭证与候选列表推导，保证认领进度与候选列表一致
 */
function getClaimStageLabel($stage) {
    $map = [
        'none' => '未开放认领',
        'waiting' => '等待认领',
        'verifying' => '待核验',
        'confirmed' => '已确认认领',
    ];
    return $map[$stage] ?? '未知';
}

/**
 * 取一条已通过审核的失物招领留言
 */
function getLostMessage($messageId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND status = 1 AND type = 'lost'");
    $stmt->execute([$messageId]);
    return $stmt->fetch() ?: null;
}

/**
 * 获取失物招领登记信息（认领凭证、保管地点）
 */
function getLostFoundInfo($messageId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM lost_found_info WHERE message_id = ?");
    $stmt->execute([$messageId]);
    return $stmt->fetch() ?: null;
}

/**
 * 捡到物品的发布者登记认领凭证和保管地点
 * 只有留言发布者本人可以登记；重复登记更新原记录而不是新增
 */
function registerLostFoundInfo($messageId, $claimProof, $keepLocation) {
    $visitorId = getVisitorId();
    $db = getDB();

    $msg = getLostMessage($messageId);
    if (!$msg) {
        throw new Exception('留言不存在或未通过审核');
    }
    if ($msg['visitor_id'] === null || $msg['visitor_id'] !== $visitorId) {
        throw new Exception('只有发布者可以登记认领凭证和保管地点');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM lost_found_info WHERE message_id = ? FOR UPDATE");
        $stmt->execute([$messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("UPDATE lost_found_info SET claim_proof = ?, keep_location = ? WHERE message_id = ?")
                ->execute([$claimProof, $keepLocation, $messageId]);
        } else {
            $db->prepare("INSERT INTO lost_found_info (message_id, visitor_id, claim_proof, keep_location) VALUES (?, ?, ?, ?)")
                ->execute([$messageId, $visitorId, $claimProof, $keepLocation]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    return getLostFoundInfo($messageId);
}

/**
 * 追加一条认领处理记录（须在事务内调用）
 */
function addLostClaimLog($db, $claimId, $messageId, $visitorId, $action, $fromStatus, $toStatus, $note = null) {
    $stmt = $db->prepare("INSERT INTO lost_claim_logs (claim_id, message_id, visitor_id, action, from_status, to_status, note)
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$claimId, $messageId, $visitorId, $action, $fromStatus, $toStatus, $note]);
    return $db->lastInsertId();
}

/**
 * 失主提交认领进度
 *
 * - 凭证不完整：照样登记为候选并停在待核验状态，发布者不能确认，只能等失主补充或驳回
 * - 重复申请：同一访客对同一物品只有一条候选，重试不覆盖已有候选，停在待核验状态
 * - 曾被驳回/撤回：允许重新提交，候选恢复到待核验（记录变更）
 *
 * @return array ['claim' => 候选行, 'duplicated' => bool]
 */
function submitLostClaim($messageId, $claimantName, $contact, $claimProgress) {
    $visitorId = getVisitorId();

    if (mb_strlen($claimantName) > 50 || mb_strlen($contact) > 100 || mb_strlen($claimProgress) > 1000) {
        throw new Exception('填写内容超出长度限制');
    }

    // 凭证完整性：称呼、联系方式、认领进度缺一不可；不完整也要登记并停在待核验
    $isComplete = ($claimantName !== '' && $contact !== '' && $claimProgress !== '') ? 1 : 0;

    $db = getDB();
    $msg = getLostMessage($messageId);
    if (!$msg) {
        throw new Exception('留言不存在或未通过审核');
    }
    $info = getLostFoundInfo($messageId);
    if (!$info) {
        throw new Exception('发布者尚未登记认领凭证，暂不能提交认领');
    }
    if ($msg['visitor_id'] !== null && $msg['visitor_id'] === $visitorId) {
        throw new Exception('发布者不能认领自己发布的物品');
    }

    $db->beginTransaction();
    try {
        // 行锁保证并发重试不会插入/覆盖出第二条候选
        $stmt = $db->prepare("SELECT * FROM lost_claims WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $existing = $stmt->fetch();
        $duplicated = false;

        if ($existing) {
            if ((int)$existing['status'] === 0) {
                // 待核验中的重复申请（含网络失败重试、无论是否完整）：原样保留，不覆盖
                $duplicated = true;
                $db->commit();
                return ['claim' => $existing, 'duplicated' => $duplicated];
            }
            if (in_array((int)$existing['status'], [2, 3], true)) {
                // 已驳回/已撤回后重新提交：恢复为待核验
                $fromStatus = (int)$existing['status'];
                $db->prepare("UPDATE lost_claims
                               SET claimant_name = ?, contact = ?, claim_progress = ?,
                                   status = 0, is_complete = ?, review_note = NULL, reviewed_at = NULL
                               WHERE id = ?")
                    ->execute([$claimantName, $contact, $claimProgress, $isComplete, $existing['id']]);
                addLostClaimLog($db, $existing['id'], $messageId, $visitorId, 'change', $fromStatus, 0,
                    $isComplete ? null : '凭证不完整，停在待核验');
                $db->commit();
                $stmt = $db->prepare("SELECT * FROM lost_claims WHERE id = ?");
                $stmt->execute([$existing['id']]);
                return ['claim' => $stmt->fetch(), 'duplicated' => false];
            }
            // 已确认的申请不允许重复提交
            $db->commit();
            return ['claim' => $existing, 'duplicated' => true];
        }

        $db->prepare("INSERT INTO lost_claims (message_id, visitor_id, claimant_name, contact, claim_progress, status, is_complete)
                      VALUES (?, ?, ?, ?, ?, 0, ?)")
            ->execute([$messageId, $visitorId, $claimantName, $contact, $claimProgress, $isComplete]);
        $claimId = (int)$db->lastInsertId();
        addLostClaimLog($db, $claimId, $messageId, $visitorId, 'submit', null, 0,
            $isComplete ? null : '凭证不完整，停在待核验');

        $db->commit();
        $stmt = $db->prepare("SELECT * FROM lost_claims WHERE id = ?");
        $stmt->execute([$claimId]);
        return ['claim' => $stmt->fetch(), 'duplicated' => false];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 失主补充自己待核验且凭证不完整的申请（不改变提交先后顺序，仍停在待核验）
 */
function supplementLostClaim($claimId, $claimantName, $contact, $claimProgress) {
    $visitorId = getVisitorId();

    if ($claimantName === '' || $contact === '' || $claimProgress === '') {
        throw new Exception('请补全称呼、联系方式和认领说明后再提交');
    }
    if (mb_strlen($claimantName) > 50 || mb_strlen($contact) > 100 || mb_strlen($claimProgress) > 1000) {
        throw new Exception('填写内容超出长度限制');
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM lost_claims WHERE id = ? FOR UPDATE");
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch();
        if (!$claim) {
            $db->rollBack();
            throw new Exception('认领申请不存在');
        }
        if ($claim['visitor_id'] !== $visitorId) {
            $db->rollBack();
            throw new Exception('只能补充自己的认领申请');
        }
        if ((int)$claim['status'] !== 0 || (int)$claim['is_complete'] === 1) {
            $db->rollBack();
            throw new Exception('当前申请无需补充凭证');
        }

        $db->prepare("UPDATE lost_claims SET claimant_name = ?, contact = ?, claim_progress = ?, is_complete = 1 WHERE id = ?")
            ->execute([$claimantName, $contact, $claimProgress, $claimId]);
        addLostClaimLog($db, $claimId, $claim['message_id'], $visitorId, 'supplement', 0, 0, '凭证已补充完整，仍待核验');
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return true;
}

/**
 * 获取同一物品的全部认领候选，按提交先后排列
 */
function getLostClaimCandidates($messageId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM lost_claims WHERE message_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$messageId]);
    return $stmt->fetchAll();
}

/**
 * 获取认领处理记录，按时间先后保留全部流转历史
 */
function getLostClaimLogs($messageId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM lost_claim_logs WHERE message_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$messageId]);
    return $stmt->fetchAll();
}

/**
 * 依据登记信息与候选列表推导最新办理阶段
 */
function deriveClaimStage($info, array $candidates) {
    if (!$info) {
        return 'none';
    }
    $hasPending = false;
    foreach ($candidates as $c) {
        if ((int)$c['status'] === 1) return 'confirmed';
        if ((int)$c['status'] === 0) $hasPending = true;
    }
    return $hasPending ? 'verifying' : 'waiting';
}

/**
 * 发布者核验认领申请：confirm确认 / reject驳回 / restore恢复待核验
 * 每次流转、恢复或变更都在事务内更新候选并写处理记录
 */
function reviewLostClaim($claimId, $action, $note = null) {
    $visitorId = getVisitorId();
    $db = getDB();

    $validActions = ['confirm' => 1, 'reject' => 2, 'restore' => 0];
    if (!isset($validActions[$action])) {
        throw new Exception('未知的核验操作');
    }
    $toStatus = $validActions[$action];
    if ($note !== null) {
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            $note = mb_substr($note, 0, 500);
        }
        $note = $note === '' ? null : $note;
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT c.*, m.visitor_id AS publisher_visitor_id
                              FROM lost_claims c
                              INNER JOIN messages m ON m.id = c.message_id
                              WHERE c.id = ? FOR UPDATE");
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch();
        if (!$claim) {
            $db->rollBack();
            throw new Exception('认领申请不存在');
        }
        if ($claim['publisher_visitor_id'] === null || $claim['publisher_visitor_id'] !== $visitorId) {
            $db->rollBack();
            throw new Exception('只有发布者可以核验认领申请');
        }

        $fromStatus = (int)$claim['status'];

        if ($action === 'confirm') {
            if ($fromStatus !== 0) {
                $db->rollBack();
                throw new Exception('只有待核验的申请可以确认，当前为「' . getClaimStatusLabel($fromStatus) . '」');
            }
            if ((int)$claim['is_complete'] !== 1) {
                $db->rollBack();
                throw new Exception('该申请凭证不完整，请先通知失主补充，暂不能确认');
            }
        } elseif ($action === 'reject') {
            if (!in_array($fromStatus, [0, 1], true)) {
                $db->rollBack();
                throw new Exception('当前状态的申请不能驳回');
            }
        } else {
            if (!in_array($fromStatus, [1, 2], true)) {
                $db->rollBack();
                throw new Exception('只有已确认或已驳回的申请可以恢复为待核验');
            }
        }

        $db->prepare("UPDATE lost_claims SET status = ?, review_note = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$toStatus, $action === 'restore' ? null : $note, $claimId]);
        $logAction = $action === 'restore' ? 'restore' : $action;
        addLostClaimLog($db, $claimId, $claim['message_id'], $visitorId, $logAction, $fromStatus, $toStatus, $note);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return true;
}

/**
 * 失主撤回自己的认领申请（待核验 → 已撤回）
 */
function withdrawLostClaim($claimId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM lost_claims WHERE id = ? FOR UPDATE");
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch();
        if (!$claim) {
            $db->rollBack();
            throw new Exception('认领申请不存在');
        }
        if ($claim['visitor_id'] !== $visitorId) {
            $db->rollBack();
            throw new Exception('只能撤回自己的认领申请');
        }
        if ((int)$claim['status'] !== 0) {
            $db->rollBack();
            throw new Exception('只有待核验的申请可以撤回，当前为「' . getClaimStatusLabel((int)$claim['status']) . '」');
        }

        $db->prepare("UPDATE lost_claims SET status = 3 WHERE id = ?")->execute([$claimId]);
        addLostClaimLog($db, $claimId, $claim['message_id'], $visitorId, 'withdraw', 0, 3, null);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return true;
}

/**
 * 批量获取多条留言的认领办理阶段（用于列表卡片徽标）
 * 阶段同样由登记信息与候选状态实时推导，保证与详情一致
 *
 * @param array $messageIds
 * @return array [messageId => ['stage' => ..., 'label' => ..., 'candidate_count' => int]]
 */
function getClaimStageMap(array $messageIds) {
    $result = [];
    $ids = array_values(array_unique(array_map('intval', $messageIds)));
    if (empty($ids)) {
        return $result;
    }

    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $registered = [];
    $stmt = $db->prepare("SELECT message_id FROM lost_found_info WHERE message_id IN ($placeholders)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $registered[(int)$row['message_id']] = true;
    }

    $stmt = $db->prepare("SELECT message_id,
                                 SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS pending_count,
                                 SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS confirmed_count,
                                 COUNT(*) AS total_count
                          FROM lost_claims WHERE message_id IN ($placeholders) GROUP BY message_id");
    $stmt->execute($ids);
    $claimStats = [];
    foreach ($stmt->fetchAll() as $row) {
        $claimStats[(int)$row['message_id']] = $row;
    }

    foreach ($ids as $id) {
        if (!isset($registered[$id])) {
            continue;
        }
        $stat = $claimStats[$id] ?? null;
        if ($stat && (int)$stat['confirmed_count'] > 0) {
            $stage = 'confirmed';
        } elseif ($stat && (int)$stat['pending_count'] > 0) {
            $stage = 'verifying';
        } else {
            $stage = 'waiting';
        }
        $result[$id] = [
            'stage' => $stage,
            'label' => getClaimStageLabel($stage),
            'candidate_count' => $stat ? (int)$stat['total_count'] : 0,
        ];
    }

    return $result;
}

/**
 * 汇总失物认领协同详情。
 * 认领进度（阶段）与候选列表在同一次读取中组装，始终回到最新阶段：
 * 登记信息、候选（按提交先后）、处理记录、当前访客的申请与发布者身份。
 */
function getLostClaimCollab($messageId) {
    $msg = getLostMessage($messageId);
    if (!$msg) {
        return null;
    }

    $info = getLostFoundInfo($messageId);
    $candidates = getLostClaimCandidates($messageId);
    $logs = getLostClaimLogs($messageId);
    $visitorId = getVisitorId();

    $isPublisher = $msg['visitor_id'] !== null && $msg['visitor_id'] === $visitorId;
    $stage = deriveClaimStage($info, $candidates);

    $myClaim = null;
    foreach ($candidates as &$c) {
        $c['status_label'] = getClaimStatusLabel((int)$c['status']);
        $c['status_class'] = getClaimStatusClass((int)$c['status']);
        $c['is_complete'] = (int)$c['is_complete'] === 1;
        $c['is_mine'] = ($c['visitor_id'] === $visitorId);
        if ($c['is_mine'] && $myClaim === null) {
            $myClaim = $c;
        }
    }
    unset($c);

    // 处理记录附带动作文字
    $actionLabels = [
        'submit' => '提交认领',
        'supplement' => '补充凭证',
        'confirm' => '确认认领',
        'reject' => '驳回申请',
        'withdraw' => '撤回申请',
        'restore' => '恢复待核验',
        'change' => '重新提交',
    ];
    foreach ($logs as &$log) {
        $log['action_label'] = $actionLabels[$log['action']] ?? $log['action'];
        $log['from_status_label'] = $log['from_status'] !== null ? getClaimStatusLabel((int)$log['from_status']) : null;
        $log['to_status_label'] = getClaimStatusLabel((int)$log['to_status']);
    }
    unset($log);

    return [
        'registered' => $info !== null,
        'is_publisher' => $isPublisher,
        'stage' => $stage,
        'stage_label' => getClaimStageLabel($stage),
        'info' => $info ? [
            'claim_proof' => $info['claim_proof'],
            'keep_location' => $info['keep_location'],
            'created_at' => $info['created_at'],
        ] : null,
        'candidates' => array_values($candidates),
        'my_claim' => $myClaim,
        'logs' => $isPublisher ? array_values($logs) : [],
        'candidate_count' => count($candidates),
        'pending_count' => count(array_filter($candidates, function ($c) { return (int)$c['status'] === 0; })),
        'incomplete_count' => count(array_filter($candidates, function ($c) {
            return (int)$c['status'] === 0 && (int)$c['is_complete'] === 0;
        })),
    ];
}
