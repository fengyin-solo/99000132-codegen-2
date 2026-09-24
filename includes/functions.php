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
 * 认领办理状态（物品级）
 * 无设置 => 0 待登记；1 待核验（已登记，等待失主申请/发布者核验）；2 已认领
 */
function getClaimStatusLabel($status) {
    $map = [0 => '待登记', 1 => '待核验', 2 => '已认领'];
    return $map[$status] ?? '待登记';
}

function getClaimStatusClass($status) {
    $map = [0 => 'pending', 1 => 'pending', 2 => 'claimed'];
    return $map[$status] ?? 'pending';
}

/**
 * 候选状态：0待核验 1已确认 2已驳回
 */
function getCandidateStatusLabel($status) {
    $map = [0 => '待核验', 1 => '已确认', 2 => '已驳回'];
    return $map[$status] ?? '待核验';
}

function getCandidateStatusClass($status) {
    $map = [0 => 'pending', 1 => 'confirmed', 2 => 'rejected'];
    return $map[$status] ?? 'pending';
}

/**
 * 失主/申请人称呼脱敏（非发布者视角）
 */
function maskName($name) {
    $len = mb_strlen($name);
    if ($len <= 1) return $name;
    if ($len === 2) return mb_substr($name, 0, 1) . '*';
    return mb_substr($name, 0, 1) . str_repeat('*', $len - 2) . mb_substr($name, -1);
}

/**
 * 失主联系方式脱敏（非发布者视角）：保留前3后2
 */
function maskPhone($phone) {
    if ($phone === null || $phone === '') return '';
    $len = strlen($phone);
    if ($len <= 5) return str_repeat('*', $len);
    return substr($phone, 0, 3) . str_repeat('*', max(0, $len - 5)) . substr($phone, -2);
}

/**
 * 记录一条认领处理轨迹
 */
function addClaimEvent($db, $messageId, $actorVisitorId, $eventType, $detail = null, $candidateId = null) {
    $stmt = $db->prepare(
        "INSERT INTO claim_events (message_id, candidate_id, actor_visitor_id, event_type, detail) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$messageId, $candidateId, $actorVisitorId, $eventType, $detail]);
    return intval($db->lastInsertId());
}

/**
 * 发布者登记/更新认领凭证要求与保管地点
 * - 已认领（claim_status=2）的物品不允许再改，办理状态停在已认领
 * - 重复提交（网络重试）不覆盖已有候选，只更新设置本身
 * 返回 claim_settings 行
 */
function setupClaim($messageId, $visitorId, $requirement, $storageLocation, $note) {
    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id, type, status, visitor_id FROM messages WHERE id = ? FOR UPDATE");
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();
        if (!$msg || intval($msg['status']) !== 1) {
            throw new Exception('留言不存在或未通过审核');
        }
        if ($msg['type'] !== 'lost') {
            throw new Exception('仅失物招领留言可以登记认领凭证');
        }
        if ($msg['visitor_id'] === null || $msg['visitor_id'] !== $visitorId) {
            throw new Exception('只有发布者可以登记认领凭证和保管地点');
        }

        $stmt = $db->prepare("SELECT * FROM claim_settings WHERE message_id = ? FOR UPDATE");
        $stmt->execute([$messageId]);
        $setting = $stmt->fetch();
        if ($setting && intval($setting['claim_status']) === 2) {
            throw new Exception('该物品已认领完成，不能再修改认领设置');
        }

        if ($setting) {
            $stmt = $db->prepare(
                "UPDATE claim_settings
                 SET claim_requirement = ?, storage_location = ?, setup_note = ?
                 WHERE message_id = ?"
            );
            $stmt->execute([$requirement, $storageLocation, $note, $messageId]);
            addClaimEvent($db, $messageId, $visitorId, 'setup', '更新认领凭证要求与保管地点');
        } else {
            $stmt = $db->prepare(
                "INSERT INTO claim_settings
                    (message_id, claim_requirement, storage_location, setup_note, setup_by, claim_status)
                 VALUES (?, ?, ?, ?, ?, 1)"
            );
            $stmt->execute([$messageId, $requirement, $storageLocation, $note, $visitorId]);
            addClaimEvent($db, $messageId, $visitorId, 'setup', '登记认领凭证要求与保管地点');
        }

        $db->commit();
        return getClaimSnapshot($messageId, $visitorId, true);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 失主提交认领申请（认领进度）
 * - 凭证不完整：直接校验失败，不写库、不改变任何候选（停在待核验）
 * - 同一访客重复申请/网络重试：复用并保留已有候选，绝不覆盖；待核验中的申请原样返回
 * - 被驳回后可补充凭证重新提交（复用原记录，保留首次提交时间与候选先后位置）
 * - 已确认或已认领：不允许重复申请
 * 返回 [候选行, 是否新建]
 */
function submitClaimCandidate($messageId, $visitorId, $contactName, $contactPhone, $evidence, $progress) {
    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id, type, status FROM messages WHERE id = ? FOR UPDATE");
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();
        if (!$msg || intval($msg['status']) !== 1) {
            throw new Exception('留言不存在或未通过审核');
        }
        if ($msg['type'] !== 'lost') {
            throw new Exception('仅失物招领留言可以提交认领申请');
        }

        $stmt = $db->prepare("SELECT * FROM claim_settings WHERE message_id = ? FOR UPDATE");
        $stmt->execute([$messageId]);
        $setting = $stmt->fetch();
        if (!$setting) {
            throw new Exception('发布者尚未登记认领凭证和保管地点，请稍后再试');
        }

        $stmt = $db->prepare("SELECT * FROM claim_candidates WHERE message_id = ? AND visitor_id = ? FOR UPDATE");
        $stmt->execute([$messageId, $visitorId]);
        $candidate = $stmt->fetch();

        // 本人已确认优先提示（在物品已认领的通用拦截之前给出明确原因）
        if ($candidate && intval($candidate['status']) === 1) {
            throw new Exception('您的认领已被确认，请勿重复申请');
        }
        if (intval($setting['claim_status']) === 2) {
            throw new Exception('该物品已被认领');
        }

        if ($candidate) {
            if (intval($candidate['status']) === 0) {
                // 重复申请 / 网络失败重试：保留已有候选，不覆盖，停在待核验
                $db->commit();
                return [getClaimSnapshot($messageId, $visitorId, false), false];
            }
            // status=2 被驳回：允许补充凭证重新提交；复用原记录、保留首次提交时间与先后位置
            $stmt = $db->prepare(
                "UPDATE claim_candidates
                 SET contact_name = ?, contact_phone = ?, claim_evidence = ?, claim_progress = ?,
                     status = 0, reject_reason = NULL, processed_note = NULL, processed_at = NULL,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$contactName, $contactPhone, $evidence, $progress, $candidate['id']]);
            addClaimEvent($db, $messageId, $visitorId, 'apply', '补充凭证后重新提交认领申请', $candidate['id']);
            $db->commit();
            return [getClaimSnapshot($messageId, $visitorId, false), true];
        }

        $stmt = $db->prepare(
            "INSERT INTO claim_candidates
                (message_id, visitor_id, contact_name, contact_phone, claim_evidence, claim_progress, status)
             VALUES (?, ?, ?, ?, ?, ?, 0)"
        );
        $stmt->execute([$messageId, $visitorId, $contactName, $contactPhone, $evidence, $progress]);
        $candidateId = intval($db->lastInsertId());
        addClaimEvent($db, $messageId, $visitorId, 'apply', '提交认领申请', $candidateId);

        $db->commit();
        return [getClaimSnapshot($messageId, $visitorId, false), true];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 发布者核验：确认某候选
 * - 首次确认（待核验→已认领）：物品流转为已认领，其他待核验候选继续排队
 * - 已认领后改选他人（变更）：原已确认候选自动置驳并保留记录，新候选确认，物品仍为已认领
 */
function confirmClaimCandidate($messageId, $visitorId, $candidateId, $note) {
    $db = getDB();
    $db->beginTransaction();
    try {
        $msg = lockClaimMessageForPublisher($db, $messageId, $visitorId);
        $setting = lockClaimSetting($db, $messageId);
        if (!$setting) throw new Exception('尚未登记认领凭证，无法核验');

        $stmt = $db->prepare("SELECT * FROM claim_candidates WHERE id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$candidateId, $messageId]);
        $candidate = $stmt->fetch();
        if (!$candidate) throw new Exception('认领申请不存在');
        if (intval($candidate['status']) !== 0) {
            throw new Exception('该申请已处理，无需重复核验');
        }

        $wasClaimed = intval($setting['claim_status']) === 2;
        $previousConfirmedId = null;

        if ($wasClaimed) {
            // 已认领状态下变更确认对象：原已确认候选自动置驳（变更，而非发布者驳回）
            $stmt = $db->prepare("SELECT id FROM claim_candidates WHERE message_id = ? AND status = 1 FOR UPDATE");
            $stmt->execute([$messageId]);
            while ($previous = $stmt->fetch()) {
                $previousConfirmedId = intval($previous['id']);
                $db->prepare(
                    "UPDATE claim_candidates
                     SET status = 2, reject_reason = 'auto_superseded', updated_at = NOW()
                     WHERE id = ?"
                )->execute([$previousConfirmedId]);
            }
        }

        $db->prepare(
            "UPDATE claim_candidates
             SET status = 1, reject_reason = NULL, processed_note = ?, processed_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        )->execute([$note, $candidateId]);

        $db->prepare("UPDATE claim_settings SET claim_status = 2 WHERE message_id = ?")
            ->execute([$messageId]);

        if ($wasClaimed) {
            $detail = '核验后变更确认对象，候选 #' . $candidateId . ' 确认为认领人';
            if ($previousConfirmedId) {
                $detail .= '；原候选 #' . $previousConfirmedId . ' 自动置驳';
            }
            addClaimEvent($db, $messageId, $visitorId, 'change', $detail, $candidateId);
        } else {
            addClaimEvent($db, $messageId, $visitorId, 'confirm', '核验通过，确认认领', $candidateId);
        }

        $db->commit();
        return getClaimSnapshot($messageId, $visitorId, true);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 发布者核验：驳回某候选（可填驳回原因）
 */
function rejectClaimCandidate($messageId, $visitorId, $candidateId, $reason) {
    $db = getDB();
    $db->beginTransaction();
    try {
        lockClaimMessageForPublisher($db, $messageId, $visitorId);
        $setting = lockClaimSetting($db, $messageId);
        if (!$setting) throw new Exception('尚未登记认领凭证，无法核验');

        $stmt = $db->prepare("SELECT * FROM claim_candidates WHERE id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$candidateId, $messageId]);
        $candidate = $stmt->fetch();
        if (!$candidate) throw new Exception('认领申请不存在');
        if (intval($candidate['status']) !== 0) {
            throw new Exception('该申请已处理，无需重复核验');
        }

        $db->prepare(
            "UPDATE claim_candidates
             SET status = 2, reject_reason = 'publisher_reject', processed_note = ?, processed_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        )->execute([$reason, $candidateId]);

        addClaimEvent($db, $messageId, $visitorId, 'reject', '核验驳回' . ($reason !== '' ? '：' . $reason : ''), $candidateId);

        $db->commit();
        return getClaimSnapshot($messageId, $visitorId, true);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 发布者恢复办理：撤销已确认的认领
 * - 物品回到待核验（claim_status=1）
 * - 原已确认候选回到待核验
 * - 因本次确认被自动置驳（auto_superseded）的候选一并回到待核验
 * - 发布者主动驳回（publisher_reject）的记录保留为已驳回
 * 支持在已认领状态下先恢复再确认其他候选（变更）
 */
function restoreClaim($messageId, $visitorId) {
    $db = getDB();
    $db->beginTransaction();
    try {
        lockClaimMessageForPublisher($db, $messageId, $visitorId);
        $setting = lockClaimSetting($db, $messageId);
        if (!$setting) throw new Exception('尚未登记认领凭证');
        if (intval($setting['claim_status']) !== 2) {
            throw new Exception('当前不在已认领状态，无需恢复');
        }

        $db->prepare(
            "UPDATE claim_candidates
             SET status = 0, processed_note = NULL, processed_at = NULL, updated_at = NOW()
             WHERE message_id = ? AND status = 1"
        )->execute([$messageId]);

        $db->prepare(
            "UPDATE claim_candidates
             SET status = 0, reject_reason = NULL, updated_at = NOW()
             WHERE message_id = ? AND status = 2 AND reject_reason = 'auto_superseded'"
        )->execute([$messageId]);

        $db->prepare("UPDATE claim_settings SET claim_status = 1 WHERE message_id = ?")
            ->execute([$messageId]);

        addClaimEvent($db, $messageId, $visitorId, 'restore', '撤销确认，恢复为待核验，候选重新进入核验队列');

        $db->commit();
        return getClaimSnapshot($messageId, $visitorId, true);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/* ---- 核验辅助：锁物品 + 发布者权限 ---- */
function lockClaimMessageForPublisher($db, $messageId, $visitorId) {
    $stmt = $db->prepare("SELECT id, type, status, visitor_id FROM messages WHERE id = ? FOR UPDATE");
    $stmt->execute([$messageId]);
    $msg = $stmt->fetch();
    if (!$msg || intval($msg['status']) !== 1) {
        throw new Exception('留言不存在或未通过审核');
    }
    if ($msg['visitor_id'] === null || $msg['visitor_id'] !== $visitorId) {
        throw new Exception('只有发布者可以核验收领申请');
    }
    return $msg;
}

function lockClaimSetting($db, $messageId) {
    $stmt = $db->prepare("SELECT * FROM claim_settings WHERE message_id = ? FOR UPDATE");
    $stmt->execute([$messageId]);
    return $stmt->fetch();
}

/**
 * 统一认领快照（详情页与所有写操作都返回它，保证认领进度与候选列表始终一致）
 * 结构：
 *   claim_status / status_label / is_publisher / setting / candidates[] / my_candidate / events[]
 * 同一事务内顺序读取 setting、candidates(按提交先后)、events，处于同一一致性视图。
 */
function getClaimSnapshot($messageId, $visitorId = null, $isPublisher = false) {
    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM claim_settings WHERE message_id = ?");
        $stmt->execute([$messageId]);
        $setting = $stmt->fetch();

        $claimStatus = 0;
        $settingOut = null;
        if ($setting) {
            $claimStatus = intval($setting['claim_status']);
            $settingOut = [
                'claim_requirement' => $setting['claim_requirement'],
                'storage_location' => $setting['storage_location'],
                'setup_note' => $setting['setup_note'],
                'created_at' => $setting['created_at'],
                'updated_at' => $setting['updated_at'],
            ];
        }

        // 候选按首次提交先后（id 升序；被驳回后重新申请复用原行，位置不变）
        $stmt = $db->prepare(
            "SELECT c.*,
                    (SELECT COUNT(*) + 1 FROM claim_candidates c2
                     WHERE c2.message_id = c.message_id AND c2.id < c.id) AS queue_position
             FROM claim_candidates c WHERE c.message_id = ? ORDER BY c.id ASC"
        );
        $stmt->execute([$messageId]);
        $rows = $stmt->fetchAll();

        $candidates = [];
        $myCandidate = null;
        foreach ($rows as $i => $row) {
            $status = intval($row['status']);
            $mine = ($visitorId !== null && $row['visitor_id'] === $visitorId);
            $item = [
                'id' => intval($row['id']),
                'contact_name' => $isPublisher ? $row['contact_name'] : maskName($row['contact_name']),
                'contact_phone' => $isPublisher ? (string)$row['contact_phone'] : maskPhone((string)$row['contact_phone']),
                'claim_evidence' => ($isPublisher || $mine) ? $row['claim_evidence'] : '凭证仅发布者可查看',
                'claim_progress' => ($isPublisher || $mine) ? $row['claim_progress'] : '',
                'status' => $status,
                'status_label' => getCandidateStatusLabel($status),
                'status_class' => getCandidateStatusClass($status),
                'reject_reason' => $row['reject_reason'],
                'processed_note' => ($isPublisher || $mine) ? $row['processed_note'] : '',
                'processed_at' => $row['processed_at'],
                'created_at' => $row['created_at'],
                'queue_position' => $i + 1,
                'mine' => $mine,
            ];
            $candidates[] = $item;
            if ($mine) $myCandidate = $item;
        }

        // 处理记录（保留全部轨迹，发布者可见）
        $events = [];
        if ($isPublisher) {
            $stmt = $db->prepare("SELECT * FROM claim_events WHERE message_id = ? ORDER BY id ASC");
            $stmt->execute([$messageId]);
            foreach ($stmt->fetchAll() as $ev) {
                $events[] = [
                    'id' => intval($ev['id']),
                    'event_type' => $ev['event_type'],
                    'event_label' => getClaimEventLabel($ev['event_type']),
                    'detail' => $ev['detail'],
                    'created_at' => $ev['created_at'],
                ];
            }
        }

        // 不变量：claim_status=2 必须恰好有一个已确认候选；读取侧做一次兜底校正
        if ($claimStatus === 2) {
            $confirmed = 0;
            foreach ($candidates as $c) if ($c['status'] === 1) $confirmed++;
            if ($confirmed !== 1) {
                // 理论上不会出现；出现则以候选为准回到待核验，保证详情与列表一致
                $claimStatus = 1;
            }
        } elseif ($claimStatus === 1) {
            foreach ($candidates as $c) {
                if ($c['status'] === 1) { $claimStatus = 2; break; }
            }
        }

        $db->commit();

        return [
            'claim_status' => $claimStatus,
            'status_label' => getClaimStatusLabel($claimStatus),
            'status_class' => getClaimStatusClass($claimStatus),
            'is_publisher' => $isPublisher,
            'setting' => $settingOut,
            'candidate_count' => count($candidates),
            'pending_count' => count(array_filter($candidates, function ($c) { return $c['status'] === 0; })),
            'candidates' => $candidates,
            'my_candidate' => $myCandidate,
            'events' => $events,
        ];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function getClaimEventLabel($type) {
    $map = [
        'setup' => '登记凭证',
        'apply' => '提交申请',
        'confirm' => '确认认领',
        'reject' => '驳回申请',
        'restore' => '恢复待核验',
        'change' => '变更认领人',
    ];
    return $map[$type] ?? '处理';
}
