<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// 增加浏览量
$db->prepare("UPDATE messages SET views = views + 1 WHERE id = ?")->execute([$id]);

// 获取详情
$stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND status = 1");
$stmt->execute([$id]);
$msg = $stmt->fetch();

if (!$msg) {
    header('Location: index.php');
    exit;
}

$pageTitle = cleanInput($msg['title']) . ' - 社区便民留言板';
$currentPage = '';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

include __DIR__ . '/includes/header.php';
?>

<section class="detail-section">
    <div class="container">
        <div class="detail-card">
            <div class="detail-header">
                <span class="card-type type-<?= $msg['type'] ?>"><?= getTypeIcon($msg['type']) ?> <?= getTypeLabel($msg['type']) ?></span>
                <div class="detail-meta">
                    <span>👤 <?= cleanInput($msg['nickname']) ?></span>
                    <span>🕐 <?= $msg['created_at'] ?></span>
                    <span>👁 <?= $msg['views'] ?> 次浏览</span>
                </div>
            </div>

            <h1 class="detail-title"><?= cleanInput($msg['title']) ?></h1>

            <div class="detail-content">
                <?= nl2br(cleanInput($msg['content'])) ?>
            </div>

            <?php if ($msg['image']): ?>
            <div class="detail-image">
                <img src="<?= cleanInput($msg['image']) ?>" alt="留言图片" onclick="window.open(this.src)">
            </div>
            <?php endif; ?>

            <?php if ($msg['phone']): ?>
            <div class="detail-contact">
                <span>📞 联系方式：<?= cleanInput($msg['phone']) ?></span>
            </div>
            <?php endif; ?>

            <div class="detail-actions">
                <a href="index.php" class="btn btn-secondary">← 返回列表</a>
                <?php $isFav = isFavorited($msg['id']); ?>
                <button class="btn favorite-detail-btn <?= $isFav ? 'btn-warning' : 'btn-secondary' ?>" data-message-id="<?= $msg['id'] ?>" onclick="toggleFavorite(event, this)">
                    <span class="favorite-icon"><?= $isFav ? '⭐' : '☆' ?></span>
                    <span class="favorite-text"><?= $isFav ? '已收藏' : '收藏' ?></span>
                </button>
                <?php $hasReported = hasReported($msg['id']); ?>
                <button class="btn <?= $hasReported ? 'btn-secondary' : 'btn-danger' ?> report-btn" data-message-id="<?= $msg['id'] ?>" onclick="openReportModal(<?= $msg['id'] ?>)" <?= $hasReported ? 'disabled' : '' ?>>
                    <span>🚩</span>
                    <span class="report-text"><?= $hasReported ? '已举报' : '举报' ?></span>
                </button>
                <a href="submit.php" class="btn btn-primary">发布留言</a>
            </div>
        </div>

        <?php if ($msg['type'] === 'lost'):
            $collab = getLostClaimCollab($msg['id']);
            $stageClassMap = ['none' => 'stage-none', 'waiting' => 'stage-waiting', 'verifying' => 'stage-verifying', 'confirmed' => 'stage-confirmed'];
        ?>
        <div class="claim-card" id="claimSection" data-message-id="<?= $msg['id'] ?>">
            <div class="claim-header">
                <h2 class="claim-title">🤝 失物认领协同</h2>
                <span class="claim-stage-badge <?= $stageClassMap[$collab['stage']] ?? '' ?>">
                    办理阶段：<?= cleanInput($collab['stage_label']) ?>
                </span>
            </div>

            <?php if ($collab['registered']): ?>
            <div class="claim-info">
                <div class="claim-info-item">
                    <span class="claim-info-label">🔑 认领凭证要求</span>
                    <p><?= nl2br(cleanInput($collab['info']['claim_proof'])) ?></p>
                </div>
                <div class="claim-info-item">
                    <span class="claim-info-label">📦 保管地点</span>
                    <p><?= nl2br(cleanInput($collab['info']['keep_location'])) ?></p>
                </div>
                <?php if ($collab['is_publisher']): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openRegisterModal(
                    <?= $msg['id'] ?>,
                    <?= json_encode($collab['info']['claim_proof'], JSON_UNESCAPED_UNICODE) ?>,
                    <?= json_encode($collab['info']['keep_location'], JSON_UNESCAPED_UNICODE) ?>
                )">✏️ 修改登记信息</button>
                <?php endif; ?>
            </div>
            <?php elseif ($collab['is_publisher']): ?>
            <div class="claim-register-entry">
                <p class="claim-tip">您是这条留言的发布者。登记认领凭证和保管地点后，失主即可提交认领进度。</p>
                <button type="button" class="btn btn-primary" onclick="openRegisterModal(<?= $msg['id'] ?>, '', '')">📝 登记认领凭证与保管地点</button>
            </div>
            <?php else: ?>
            <div class="claim-register-entry">
                <p class="claim-tip">发布者尚未开放认领登记，请稍后再来查看。</p>
            </div>
            <?php endif; ?>

            <?php if ($collab['registered'] && !$collab['is_publisher']): ?>
            <div class="claim-apply">
                <?php if ($collab['my_claim']):
                    $mine = $collab['my_claim']; ?>
                <div class="claim-my">
                    <h3>我的认领申请</h3>
                    <p><span class="claim-status-tag claim-<?= cleanInput($mine['status_class']) ?>"><?= cleanInput($mine['status_label']) ?></span>
                       <?php if (!$mine['is_complete']): ?>
                       <span class="claim-status-tag claim-incomplete">凭证不完整</span>
                       <?php endif; ?>
                       <span class="claim-time">提交于 <?= cleanInput($mine['created_at']) ?></span></p>
                    <p class="claim-progress-text"><?= $mine['claim_progress'] !== '' ? nl2br(cleanInput($mine['claim_progress'])) : '<em class="text-muted">（未填写认领说明）</em>' ?></p>
                    <?php if ($mine['review_note'] !== null): ?>
                    <p class="claim-review-note">发布者核验备注：<?= nl2br(cleanInput($mine['review_note'])) ?></p>
                    <?php endif; ?>
                    <?php if ((int)$mine['status'] === 0 && !$mine['is_complete']): ?>
                    <button type="button" class="btn btn-warning btn-sm" onclick="openApplyModal(
                        <?= $msg['id'] ?>,
                        <?= (int)$mine['id'] ?>,
                        <?= json_encode($mine['claimant_name'], JSON_UNESCAPED_UNICODE) ?>,
                        <?= json_encode($mine['contact'], JSON_UNESCAPED_UNICODE) ?>,
                        <?= json_encode($mine['claim_progress'], JSON_UNESCAPED_UNICODE) ?>
                    )">📝 补充凭证</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="claimAction(this, 'withdraw', <?= (int)$mine['id'] ?>)">撤回申请</button>
                    <?php elseif ((int)$mine['status'] === 0): ?>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="claimAction(this, 'withdraw', <?= (int)$mine['id'] ?>)">撤回申请</button>
                    <?php elseif (in_array((int)$mine['status'], [2, 3], true)): ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openApplyModal(<?= $msg['id'] ?>, 0, '', '', '')">重新提交认领</button>
                    <?php endif; ?>
                </div>
                <?php elseif ($collab['stage'] !== 'confirmed'): ?>
                <button type="button" class="btn btn-primary" onclick="openApplyModal(<?= $msg['id'] ?>, 0, '', '', '')">🙋 我是失主，提交认领</button>
                <?php else: ?>
                <p class="claim-tip">该物品已被认领。如您也是失主，请联系发布者说明情况。</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="claim-candidates">
                <h3>候选列表 <span class="claim-count">共 <?= (int)$collab['candidate_count'] ?> 人，
                    待核验 <?= (int)$collab['pending_count'] ?> 人<?= (int)$collab['incomplete_count'] > 0 ? '，凭证不完整 ' . (int)$collab['incomplete_count'] . ' 人' : '' ?></span></h3>
                <?php if (empty($collab['candidates'])): ?>
                <p class="claim-empty">暂无认领申请，按提交先后顺序排列候选。</p>
                <?php else: ?>
                <ul class="candidate-list">
                    <?php foreach ($collab['candidates'] as $idx => $c):
                        $canViewContact = $collab['is_publisher'] || $c['is_mine']; ?>
                    <li class="candidate-item candidate-<?= cleanInput($c['status_class']) ?>" data-claim-id="<?= (int)$c['id'] ?>">
                        <div class="candidate-head">
                            <span class="candidate-order">#<?= $idx + 1 ?></span>
                            <span class="candidate-name"><?= cleanInput($c['claimant_name'] !== '' ? $c['claimant_name'] : '（未留称呼）') ?><?= $c['is_mine'] ? '（我）' : '' ?></span>
                            <span class="claim-status-tag claim-<?= cleanInput($c['status_class']) ?>"><?= cleanInput($c['status_label']) ?></span>
                            <?php if ((int)$c['status'] === 0 && !$c['is_complete']): ?>
                            <span class="claim-status-tag claim-incomplete">凭证不完整</span>
                            <?php endif; ?>
                            <span class="candidate-time"><?= cleanInput($c['created_at']) ?></span>
                        </div>
                        <p class="candidate-progress"><?= $c['claim_progress'] !== '' ? nl2br(cleanInput($c['claim_progress'])) : '<em class="text-muted">（未填写认领说明）</em>' ?></p>
                        <?php if ($canViewContact): ?>
                        <p class="candidate-contact">联系方式：<?= cleanInput($c['contact'] !== '' ? $c['contact'] : '（未留联系方式）') ?></p>
                        <?php endif; ?>
                        <?php if ($c['review_note'] !== null): ?>
                        <p class="claim-review-note">核验备注：<?= nl2br(cleanInput($c['review_note'])) ?></p>
                        <?php endif; ?>

                        <?php if ($collab['is_publisher']): ?>
                        <div class="candidate-actions">
                            <?php if ((int)$c['status'] === 0): ?>
                                <?php if ($c['is_complete']): ?>
                            <button type="button" class="btn btn-success btn-sm" onclick="claimAction(this, 'confirm', <?= (int)$c['id'] ?>)">✔ 确认</button>
                                <?php else: ?>
                            <button type="button" class="btn btn-success btn-sm" disabled title="凭证不完整，需失主补充后才能确认">✔ 确认</button>
                                <?php endif; ?>
                            <button type="button" class="btn btn-danger btn-sm" onclick="openReviewModal(<?= (int)$c['id'] ?>, 'reject')">✕ 驳回</button>
                            <?php elseif ((int)$c['status'] === 1): ?>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="claimAction(this, 'restore', <?= (int)$c['id'] ?>)">恢复为待核验</button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="openReviewModal(<?= (int)$c['id'] ?>, 'reject')">改判驳回</button>
                            <?php elseif ((int)$c['status'] === 2): ?>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="claimAction(this, 'restore', <?= (int)$c['id'] ?>)">恢复为待核验</button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <?php if ($collab['is_publisher'] && !empty($collab['logs'])): ?>
            <div class="claim-logs">
                <h3>处理记录</h3>
                <ul class="claim-log-list">
                    <?php foreach ($collab['logs'] as $log): ?>
                    <li class="claim-log-item">
                        <span class="claim-log-time"><?= cleanInput($log['created_at']) ?></span>
                        <span class="claim-log-action"><?= cleanInput($log['action_label']) ?></span>
                        <?php if ($log['from_status_label'] !== null): ?>
                        <span class="claim-log-flow"><?= cleanInput($log['from_status_label']) ?> → <?= cleanInput($log['to_status_label']) ?></span>
                        <?php endif; ?>
                        <?php if ($log['note'] !== null && $log['note'] !== ''): ?>
                        <span class="claim-log-note"><?= nl2br(cleanInput($log['note'])) ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- 认领凭证登记弹窗 -->
<div class="modal" id="registerModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📝 登记认领凭证与保管地点</h3>
            <button class="modal-close" type="button" onclick="closeRegisterModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="registerForm">
                <input type="hidden" id="registerMessageId" name="message_id">
                <div class="form-group">
                    <label for="registerProof">认领凭证 <span class="required">*</span></label>
                    <textarea id="registerProof" name="claim_proof" rows="4" maxlength="500" placeholder="设置只有真正失主才知道的核验问题，例如：包内有什么证件/物品？钥匙串上有几把钥匙？" required></textarea>
                </div>
                <div class="form-group">
                    <label for="registerLocation">保管地点 <span class="required">*</span></label>
                    <input type="text" id="registerLocation" name="keep_location" maxlength="200" placeholder="例如：3号楼物业办公室" required>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRegisterModal()">取消</button>
                    <button type="submit" class="btn btn-primary" id="registerSubmitBtn">保存登记</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 失主认领弹窗 -->
<div class="modal" id="applyModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🙋 提交认领申请</h3>
            <button class="modal-close" type="button" onclick="closeApplyModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="applyForm">
                <input type="hidden" id="applyMessageId" name="message_id">
                <input type="hidden" id="applyClaimId" name="claim_id" value="0">
                <div class="form-group">
                    <label for="applyName">您的称呼 <span class="required">*</span></label>
                    <input type="text" id="applyName" name="claimant_name" maxlength="50" placeholder="请输入您的称呼">
                </div>
                <div class="form-group">
                    <label for="applyContact">联系方式 <span class="required">*</span></label>
                    <input type="text" id="applyContact" name="contact" maxlength="100" placeholder="手机号或微信号">
                </div>
                <div class="form-group">
                    <label for="applyProgress">认领进度说明 <span class="required">*</span></label>
                    <textarea id="applyProgress" name="claim_progress" rows="5" maxlength="1000" placeholder="请对照认领凭证描述物品特征、遗失时间和经过。三项信息都填写才算凭证完整，不完整将停在待核验状态。"></textarea>
                </div>
                <div class="form-tip">
                    <p>⚠️ 凭证不完整的申请会登记为候选并停在待核验状态，需补充完整后发布者才能确认；重复提交不会覆盖已有申请。</p>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeApplyModal()">取消</button>
                    <button type="submit" class="btn btn-primary" id="applySubmitBtn">提交认领</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 驳回弹窗 -->
<div class="modal" id="reviewModal" style="display:none;">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>✕ 驳回认领申请</h3>
            <button class="modal-close" type="button" onclick="closeReviewModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="reviewForm">
                <input type="hidden" id="reviewMessageId" name="message_id">
                <input type="hidden" id="reviewClaimId" name="claim_id">
                <div class="form-group">
                    <label for="reviewNote">驳回原因 <span class="text-muted">(可选，最多500字)</span></label>
                    <textarea id="reviewNote" name="note" rows="4" maxlength="500" placeholder="说明凭证不符之处，便于失主补充后重新提交"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeReviewModal()">取消</button>
                    <button type="submit" class="btn btn-danger" id="reviewSubmitBtn">确认驳回</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 举报弹窗 -->
<div class="modal" id="reportModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🚩 举报留言</h3>
            <button class="modal-close" onclick="closeReportModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="reportForm">
                <input type="hidden" id="reportMessageId" name="message_id">
                <div class="form-group">
                    <label>举报类型 <span class="required">*</span></label>
                    <div class="report-type-options">
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="spam" required>
                            <span>🗑️ 垃圾信息</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="abuse">
                            <span>😡 辱骂攻击</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="illegal">
                            <span>⚖️ 违法违规</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="porn">
                            <span>🔞 色情低俗</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="other">
                            <span>📝 其他</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="reportDescription">补充说明 <span class="text-muted">(可选，最多500字)</span></label>
                    <textarea id="reportDescription" name="description" rows="4" maxlength="500" placeholder="请描述具体的违规内容，帮助我们更好地处理..."></textarea>
                    <span class="char-count"><span id="reportDescCount">0</span>/500</span>
                </div>
                <div class="form-tip">
                    <p>⚠️ 恶意举报将被限制功能使用，请如实填写举报内容。</p>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeReportModal()">取消</button>
                    <button type="submit" class="btn btn-danger" id="reportSubmitBtn">提交举报</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
