<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$action = $_POST['action'] ?? '';
$messageId = intval($_POST['message_id'] ?? 0);

if ($messageId <= 0) {
    jsonResponse(1, '无效的留言ID');
}

try {
    switch ($action) {
        // 捡到物品的发布者登记认领凭证与保管地点
        case 'register':
            $claimProof = trim($_POST['claim_proof'] ?? '');
            $keepLocation = trim($_POST['keep_location'] ?? '');

            if ($claimProof === '') {
                jsonResponse(1, '请填写认领凭证（只有失主才知道的核验信息）');
            }
            if (mb_strlen($claimProof) > 500) {
                jsonResponse(1, '认领凭证不能超过500字');
            }
            if ($keepLocation === '') {
                jsonResponse(1, '请填写物品保管地点');
            }
            if (mb_strlen($keepLocation) > 200) {
                jsonResponse(1, '保管地点不能超过200字');
            }

            registerLostFoundInfo($messageId, $claimProof, $keepLocation);
            jsonResponse(0, '认领凭证与保管地点登记成功', ['collab' => getLostClaimCollab($messageId)]);

        // 失主提交认领进度
        case 'apply':
            $claimantName = trim($_POST['claimant_name'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $claimProgress = trim($_POST['claim_progress'] ?? '');

            if (mb_strlen($claimantName) > 50) {
                jsonResponse(1, '称呼不能超过50字');
            }
            if (mb_strlen($contact) > 100) {
                jsonResponse(1, '联系方式不能超过100字');
            }
            if (mb_strlen($claimProgress) > 1000) {
                jsonResponse(1, '认领说明不能超过1000字');
            }

            $result = submitLostClaim($messageId, $claimantName, $contact, $claimProgress);
            $collab = getLostClaimCollab($messageId);
            if ($result['duplicated']) {
                // 重复申请（含网络失败重试）：已有候选保留，停在待核验状态
                jsonResponse(0, '您已提交过认领申请，请等待发布者核验', [
                    'duplicated' => true,
                    'collab' => $collab,
                ]);
            }
            if ((int)$result['claim']['is_complete'] !== 1) {
                // 凭证不完整：已登记为候选但停在待核验，需要补充
                jsonResponse(0, '凭证不完整，申请已登记并停在待核验状态，请补充完整信息', [
                    'duplicated' => false,
                    'incomplete' => true,
                    'collab' => $collab,
                ]);
            }
            jsonResponse(0, '认领申请已提交，等待发布者核验', [
                'duplicated' => false,
                'incomplete' => false,
                'collab' => $collab,
            ]);

        // 失主补充不完整的凭证（仍停在待核验，不改变排队顺序）
        case 'supplement':
            $claimId = intval($_POST['claim_id'] ?? 0);
            $claimantName = trim($_POST['claimant_name'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $claimProgress = trim($_POST['claim_progress'] ?? '');

            if ($claimId <= 0) {
                jsonResponse(1, '无效的认领申请ID');
            }

            supplementLostClaim($claimId, $claimantName, $contact, $claimProgress);
            jsonResponse(0, '凭证已补充完整，等待发布者核验', ['collab' => getLostClaimCollab($messageId)]);

        // 发布者核验：确认 / 驳回 / 恢复待核验
        case 'review':
            $claimId = intval($_POST['claim_id'] ?? 0);
            $reviewAction = $_POST['review_action'] ?? '';
            $note = trim($_POST['note'] ?? '');

            if ($claimId <= 0) {
                jsonResponse(1, '无效的认领申请ID');
            }
            if (!in_array($reviewAction, ['confirm', 'reject', 'restore'], true)) {
                jsonResponse(1, '未知的核验操作');
            }

            reviewLostClaim($claimId, $reviewAction, $note);
            $msgMap = [
                'confirm' => '已确认该认领申请',
                'reject' => '已驳回该认领申请',
                'restore' => '已恢复为待核验',
            ];
            jsonResponse(0, $msgMap[$reviewAction], ['collab' => getLostClaimCollab($messageId)]);

        // 失主撤回自己的申请
        case 'withdraw':
            $claimId = intval($_POST['claim_id'] ?? 0);
            if ($claimId <= 0) {
                jsonResponse(1, '无效的认领申请ID');
            }
            withdrawLostClaim($claimId);
            jsonResponse(0, '已撤回认领申请', ['collab' => getLostClaimCollab($messageId)]);

        default:
            jsonResponse(1, '未知操作');
    }
} catch (Exception $e) {
    jsonResponse(1, $e->getMessage());
}
