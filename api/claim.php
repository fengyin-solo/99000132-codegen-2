<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$messageId = intval($_POST['message_id'] ?? $_GET['message_id'] ?? 0);

if ($messageId <= 0) {
    jsonResponse(1, '无效的留言ID');
}

$visitorId = getVisitorId();
$db = getDB();

// 校验留言存在且已通过审核、为失物招领类型
$stmt = $db->prepare("SELECT id, type, status, visitor_id FROM messages WHERE id = ? AND status = 1");
$stmt->execute([$messageId]);
$msg = $stmt->fetch();
if (!$msg) {
    jsonResponse(1, '留言不存在或未通过审核');
}
if ($msg['type'] !== 'lost') {
    jsonResponse(1, '仅失物招领留言支持认领协同');
}

// 发布者身份（留言早于功能上线没有 visitor_id 时，任何人都无法冒充发布者）
$isPublisher = $msg['visitor_id'] !== null && $msg['visitor_id'] === $visitorId;

/**
 * 统一返回最新快照，保证认领进度与候选列表一致
 */
function outputSnapshot($snapshot) {
    jsonResponse(0, 'ok', $snapshot);
}

try {
    if ($action === 'detail') {
        outputSnapshot(getClaimSnapshot($messageId, $visitorId, $isPublisher));
    }

    if ($method !== 'POST') {
        jsonResponse(405, '不支持的请求方式');
    }

    switch ($action) {
        case 'setup': {
            // 捡到物品的发布者登记认领凭证与保管地点
            if (!$isPublisher) {
                jsonResponse(1, '只有发布者可以登记认领凭证和保管地点');
            }
            $requirement = trim($_POST['claim_requirement'] ?? '');
            $storageLocation = trim($_POST['storage_location'] ?? '');
            $note = trim($_POST['setup_note'] ?? '');

            if ($requirement === '') {
                jsonResponse(1, '请填写认领凭证要求（失主需凭什么核验，如物品独有特征）');
            }
            if (mb_strlen($requirement) > 500) {
                jsonResponse(1, '认领凭证要求不能超过500字');
            }
            if ($storageLocation === '') {
                jsonResponse(1, '请填写物品保管地点');
            }
            if (mb_strlen($storageLocation) > 200) {
                jsonResponse(1, '保管地点不能超过200字');
            }
            if (mb_strlen($note) > 500) {
                jsonResponse(1, '补充说明不能超过500字');
            }

            $snapshot = setupClaim($messageId, $visitorId, $requirement, $storageLocation, $note);
            jsonResponse(0, '认领凭证与保管地点已登记', $snapshot);
        }

        case 'apply': {
            // 失主提交认领申请（认领进度）
            $contactName = trim($_POST['contact_name'] ?? '');
            $contactPhone = trim($_POST['contact_phone'] ?? '');
            $evidence = trim($_POST['claim_evidence'] ?? '');
            $progress = trim($_POST['claim_progress'] ?? '');

            // 凭证不完整：停在待核验状态，不写入、不覆盖任何候选
            if ($contactName === '') {
                jsonResponse(1, '请填写您的称呼');
            }
            if (mb_strlen($contactName) > 50) {
                jsonResponse(1, '称呼不能超过50个字符');
            }
            if ($contactPhone !== '' && mb_strlen($contactPhone) > 20) {
                jsonResponse(1, '联系方式不能超过20个字符');
            }
            if ($evidence === '') {
                jsonResponse(1, '请填写认领凭证（描述物品独有特征等证明信息）');
            }
            if (mb_strlen($evidence) > 1000) {
                jsonResponse(1, '认领凭证不能超过1000字');
            }
            if (mb_strlen($progress) > 1000) {
                jsonResponse(1, '认领进度说明不能超过1000字');
            }

            list($snapshot, $isNew) = submitClaimCandidate(
                $messageId, $visitorId, $contactName, $contactPhone ?: null, $evidence, $progress ?: null
            );
            jsonResponse(0, $isNew ? '认领申请已提交，等待发布者核验' : '您已提交过申请，申请仍在待核验队列中，无需重复提交', $snapshot);
        }

        case 'confirm': {
            if (!$isPublisher) {
                jsonResponse(1, '只有发布者可以核验认领申请');
            }
            $candidateId = intval($_POST['candidate_id'] ?? 0);
            $note = trim($_POST['processed_note'] ?? '');
            if ($candidateId <= 0) {
                jsonResponse(1, '无效的候选申请');
            }
            if (mb_strlen($note) > 500) {
                jsonResponse(1, '核验备注不能超过500字');
            }
            $snapshot = confirmClaimCandidate($messageId, $visitorId, $candidateId, $note);
            jsonResponse(0, '已确认该认领，办理状态更新为已认领', $snapshot);
        }

        case 'reject': {
            if (!$isPublisher) {
                jsonResponse(1, '只有发布者可以核验认领申请');
            }
            $candidateId = intval($_POST['candidate_id'] ?? 0);
            $reason = trim($_POST['processed_note'] ?? '');
            if ($candidateId <= 0) {
                jsonResponse(1, '无效的候选申请');
            }
            if (mb_strlen($reason) > 500) {
                jsonResponse(1, '驳回原因不能超过500字');
            }
            $snapshot = rejectClaimCandidate($messageId, $visitorId, $candidateId, $reason);
            jsonResponse(0, '已驳回该认领申请', $snapshot);
        }

        case 'restore': {
            if (!$isPublisher) {
                jsonResponse(1, '只有发布者可以恢复办理状态');
            }
            $snapshot = restoreClaim($messageId, $visitorId);
            jsonResponse(0, '已撤销确认，办理状态恢复为待核验', $snapshot);
        }

        default:
            jsonResponse(1, '未知操作');
    }
} catch (Exception $e) {
    jsonResponse(1, $e->getMessage());
}
