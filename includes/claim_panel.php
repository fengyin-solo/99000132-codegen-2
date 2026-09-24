<?php
/**
 * 失物认领协同面板（在 detail.php 中引入）
 * 依赖变量：
 *   $msg           array  留言行（须 type=lost）
 *   $claim         array  getClaimSnapshot() 的返回
 *   $isPublisher   bool   当前访客是否发布者
 */
?>
<section class="claim-section" id="claimSection" data-message-id="<?= $msg['id'] ?>">
    <div class="container">
        <div class="claim-card">
            <div class="claim-head">
                <h2 class="claim-title">🤝 失物认领协同</h2>
                <span class="claim-status-badge claim-badge-<?= $claim['status_class'] ?>" id="claimStatusBadge">
                    <?= $claim['status_label'] ?>
                </span>
            </div>

            <?php if ($claim['setting'] === null): ?>
                <!-- 尚未登记认领凭证 -->
                <div class="claim-empty">
                    <p class="claim-empty-text">发布者还未登记认领凭证与保管地点。</p>
                    <?php if ($isPublisher): ?>
                    <p class="claim-empty-hint">您是本条留言的发布者，登记凭证要求和保管地点后，失主即可提交认领申请。</p>

                    <form class="claim-form" id="claimSetupForm">
                        <div class="form-group">
                            <label for="claimRequirement">认领凭证要求 <span class="required">*</span></label>
                            <textarea id="claimRequirement" name="claim_requirement" rows="3" maxlength="500"
                                      placeholder="失主需凭什么信息核验？例如物品独有特征、内含物品等" required></textarea>
                            <span class="char-count"><span class="setup-req-count">0</span>/500</span>
                        </div>
                        <div class="form-group">
                            <label for="storageLocation">保管地点 <span class="required">*</span></label>
                            <input type="text" id="storageLocation" name="storage_location" maxlength="200"
                                   placeholder="例如：3号楼物业前台" required>
                        </div>
                        <div class="form-group">
                            <label for="setupNote">补充说明 <span class="text-muted">(可选)</span></label>
                            <textarea id="setupNote" name="setup_note" rows="2" maxlength="500"
                                      placeholder="领取时间、联系人等补充信息"></textarea>
                            <span class="char-count"><span class="setup-note-count">0</span>/500</span>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary claim-submit-btn">登记认领凭证</button>
                        </div>
                    </form>
                    <?php else: ?>
                    <p class="claim-empty-hint">请稍后再来查看，或联系留言中的发布者。</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- 认领凭证与保管地点 -->
                <div class="claim-info" id="claimInfo">
                    <div class="claim-info-item">
                        <span class="claim-info-label">📝 认领凭证要求</span>
                        <p><?= nl2br(cleanInput($claim['setting']['claim_requirement'])) ?></p>
                    </div>
                    <div class="claim-info-item">
                        <span class="claim-info-label">📍 保管地点</span>
                        <p><?= nl2br(cleanInput($claim['setting']['storage_location'])) ?></p>
                    </div>
                    <?php if (!empty($claim['setting']['setup_note'])): ?>
                    <div class="claim-info-item">
                        <span class="claim-info-label">ℹ️ 补充说明</span>
                        <p><?= nl2br(cleanInput($claim['setting']['setup_note'])) ?></p>
                    </div>
                    <?php endif; ?>
                    <div class="claim-info-meta">
                        最近更新：<?= cleanInput($claim['setting']['updated_at']) ?>
                        <?php if ($isPublisher && $claim['claim_status'] !== 2): ?>
                        · <button type="button" class="claim-link-btn" id="editSetupBtn">修改凭证/地点</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($isPublisher): ?>
                    <form class="claim-form claim-edit-form" id="claimSetupForm" style="display:none;">
                        <div class="form-group">
                            <label for="claimRequirement">认领凭证要求 <span class="required">*</span></label>
                            <textarea id="claimRequirement" name="claim_requirement" rows="3" maxlength="500" required><?= cleanInput($claim['setting']['claim_requirement']) ?></textarea>
                            <span class="char-count"><span class="setup-req-count">0</span>/500</span>
                        </div>
                        <div class="form-group">
                            <label for="storageLocation">保管地点 <span class="required">*</span></label>
                            <input type="text" id="storageLocation" name="storage_location" maxlength="200"
                                   value="<?= cleanInput($claim['setting']['storage_location']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="setupNote">补充说明 <span class="text-muted">(可选)</span></label>
                            <textarea id="setupNote" name="setup_note" rows="2" maxlength="500"><?= cleanInput((string)$claim['setting']['setup_note']) ?></textarea>
                            <span class="char-count"><span class="setup-note-count">0</span>/500</span>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary claim-submit-btn">保存修改</button>
                            <button type="button" class="btn btn-secondary" id="cancelEditSetupBtn">取消</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- 办理状态说明 -->
                <div class="claim-progress" id="claimProgress">
                    <?php if ($claim['claim_status'] === 2): ?>
                        <p class="claim-progress-claimed">✅ 该物品已完成认领，办理状态：<strong>已认领</strong>。</p>
                        <?php if ($isPublisher): ?>
                        <p class="claim-progress-hint">如确认有误，可撤销确认恢复到待核验；也可在其他候选上直接改选，系统会自动驳回原认领人并保留记录。</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="claim-progress-pending">⏳ 办理状态：<strong>待核验</strong>，当前候选 <?= $claim['candidate_count'] ?> 人，待核验 <?= $claim['pending_count'] ?> 人，按提交先后依次核验。</p>
                    <?php endif; ?>
                </div>

                <?php if ($isPublisher): ?>
                    <!-- 发布者：候选队列（按提交先后）与核验操作 -->
                    <div class="claim-candidates">
                        <h3 class="claim-subtitle">候选列表（按提交先后）</h3>
                        <?php if (empty($claim['candidates'])): ?>
                        <p class="claim-empty-hint">暂无失主提交认领申请。</p>
                        <?php else: ?>
                        <ul class="candidate-list">
                            <?php foreach ($claim['candidates'] as $idx => $c): ?>
                            <li class="candidate-item candidate-<?= $c['status_class'] ?>" data-candidate-id="<?= $c['id'] ?>">
                                <div class="candidate-head">
                                    <span class="candidate-order">#<?= $idx + 1 ?></span>
                                    <span class="candidate-name">👤 <?= cleanInput($c['contact_name']) ?></span>
                                    <?php if ($c['contact_phone'] !== ''): ?>
                                    <span class="candidate-phone">📞 <?= cleanInput($c['contact_phone']) ?></span>
                                    <?php endif; ?>
                                    <span class="candidate-status candidate-badge-<?= $c['status_class'] ?>"><?= $c['status_label'] ?></span>
                                    <span class="candidate-time"><?= cleanInput($c['created_at']) ?> 提交</span>
                                </div>
                                <div class="candidate-body">
                                    <p><strong>认领凭证：</strong><?= nl2br(cleanInput($c['claim_evidence'])) ?></p>
                                    <?php if ($c['claim_progress'] !== null && $c['claim_progress'] !== ''): ?>
                                    <p><strong>认领进度：</strong><?= nl2br(cleanInput($c['claim_progress'])) ?></p>
                                    <?php endif; ?>
                                    <?php if ($c['status'] === 2): ?>
                                    <p class="candidate-reason">
                                        <strong>驳回原因：</strong>
                                        <?php if ($c['reject_reason'] === 'auto_superseded'): ?>
                                        发布者已改选其他认领人，本候选自动置驳
                                        <?php else: ?>
                                        <?= cleanInput((string)$c['processed_note']) ?: '凭证核验未通过' ?>
                                        <?php endif; ?>
                                        <?php if ($c['processed_at']): ?>（<?= cleanInput($c['processed_at']) ?>）<?php endif; ?>
                                    </p>
                                    <?php elseif ($c['status'] === 1): ?>
                                    <p class="candidate-confirmed">已确认的认领人<?php if ($c['processed_note']): ?>：<?= cleanInput($c['processed_note']) ?><?php endif; ?></p>
                                    <?php elseif ($c['processed_note'] !== null && $c['processed_note'] !== ''): ?>
                                    <p class="candidate-reason"><strong>核验备注：</strong><?= cleanInput($c['processed_note']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($c['status'] === 0): ?>
                                <div class="candidate-actions">
                                    <button type="button" class="btn btn-success btn-sm candidate-confirm-btn"
                                            data-candidate-id="<?= $c['id'] ?>">✓ 确认认领</button>
                                    <button type="button" class="btn btn-danger btn-sm candidate-reject-btn"
                                            data-candidate-id="<?= $c['id'] ?>">✕ 驳回</button>
                                </div>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <?php if ($claim['claim_status'] === 2): ?>
                        <div class="claim-publisher-actions">
                            <button type="button" class="btn btn-secondary" id="restoreClaimBtn">↩️ 撤销确认，恢复待核验</button>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($claim['events'])): ?>
                        <div class="claim-events">
                            <h3 class="claim-subtitle">处理记录</h3>
                            <ul class="event-list">
                                <?php foreach ($claim['events'] as $ev): ?>
                                <li class="event-item event-<?= cleanInput($ev['event_type']) ?>">
                                    <span class="event-badge event-badge-<?= cleanInput($ev['event_type']) ?>"><?= cleanInput($ev['event_label']) ?></span>
                                    <span class="event-detail"><?= cleanInput((string)$ev['detail']) ?></span>
                                    <span class="event-time"><?= cleanInput($ev['created_at']) ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- 失主视角 -->
                    <?php if ($my = $claim['my_candidate']): ?>
                    <div class="claim-my candidate-item candidate-<?= $my['status_class'] ?>">
                        <div class="candidate-head">
                            <span class="candidate-status candidate-badge-<?= $my['status_class'] ?>">我的申请：<?= $my['status_label'] ?></span>
                            <?php if ($my['status'] === 0): ?>
                            <span class="candidate-time">排队序号 #<?= $my['queue_position'] ?>，请耐心等待发布者核验</span>
                            <?php endif; ?>
                        </div>
                        <div class="candidate-body">
                            <p><strong>认领凭证：</strong><?= nl2br(cleanInput($my['claim_evidence'])) ?></p>
                            <?php if ($my['claim_progress']): ?>
                            <p><strong>认领进度：</strong><?= nl2br(cleanInput($my['claim_progress'])) ?></p>
                            <?php endif; ?>
                            <?php if ($my['status'] === 1): ?>
                            <p class="candidate-confirmed">🎉 发布者已确认您的认领，请按保管地点联系领取。</p>
                            <?php elseif ($my['status'] === 2): ?>
                            <p class="candidate-reason">
                                <strong>结果：</strong>
                                <?php if ($my['reject_reason'] === 'auto_superseded'): ?>
                                发布者已确认其他认领人，本次申请自动置驳。
                                <?php else: ?>
                                凭证核验未通过<?php if ($my['processed_note']): ?>：<?= cleanInput($my['processed_note']) ?><?php endif; ?>。
                                <?php endif; ?>
                                如确为您的物品，可补充更详细的凭证后重新申请。
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($claim['claim_status'] === 2): ?>
                        <?php if (!$my || $my['status'] !== 1): ?>
                        <p class="claim-closed-hint">该物品已被认领。若您认为有误，请联系发布者核对。</p>
                        <?php endif; ?>
                    <?php elseif ($my && $my['status'] === 0): ?>
                        <p class="claim-empty-hint">您的申请已提交，请勿重复提交；网络异常时重试不会影响已有申请。</p>
                    <?php else: ?>
                        <!-- 未申请或被驳回后可重新申请 -->
                        <form class="claim-form" id="claimApplyForm">
                            <h3 class="claim-subtitle"><?= ($my && $my['status'] === 2) ? '补充凭证，重新申请认领' : '我是失主，提交认领申请' ?></h3>
                            <div class="form-group">
                                <label for="contactName">您的称呼 <span class="required">*</span></label>
                                <input type="text" id="contactName" name="contact_name" maxlength="50" placeholder="请输入您的称呼" required>
                            </div>
                            <div class="form-group">
                                <label for="contactPhone">联系方式 <span class="text-muted">(选填，方便发布者联系)</span></label>
                                <input type="tel" id="contactPhone" name="contact_phone" maxlength="20" placeholder="选填">
                            </div>
                            <div class="form-group">
                                <label for="claimEvidence">认领凭证 <span class="required">*</span></label>
                                <textarea id="claimEvidence" name="claim_evidence" rows="3" maxlength="1000"
                                          placeholder="请描述能证明物品归属的独有特征（与上方凭证要求对应）" required></textarea>
                                <span class="char-count"><span class="apply-evidence-count">0</span>/1000</span>
                            </div>
                            <div class="form-group">
                                <label for="claimProgress">认领进度补充 <span class="text-muted">(选填)</span></label>
                                <textarea id="claimProgress" name="claim_progress" rows="2" maxlength="1000"
                                          placeholder="例如丢失时间、地点、经过等"></textarea>
                                <span class="char-count"><span class="apply-progress-count">0</span>/1000</span>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary claim-submit-btn">提交认领申请</button>
                            </div>
                            <p class="form-tip-text">凭证信息不完整将无法提交；申请提交后进入待核验队列，按提交先后依次核验。</p>
                        </form>
                    <?php endif; ?>

                    <!-- 其他候选概览（脱敏，仅展示排队情况） -->
                    <?php
                    $others = array_filter($claim['candidates'], function ($c) { return empty($c['mine']); });
                    ?>
                    <?php if (!empty($others)): ?>
                    <div class="claim-queue-overview">
                        <h3 class="claim-subtitle">认领排队情况</h3>
                        <ul class="queue-list">
                            <?php foreach ($others as $c): ?>
                            <li class="queue-item">
                                <span>#<?= $c['queue_position'] ?></span>
                                <span class="queue-name"><?= cleanInput($c['contact_name']) ?></span>
                                <span class="candidate-badge-<?= $c['status_class'] ?>"><?= $c['status_label'] ?></span>
                                <span class="candidate-time"><?= timeAgo($c['created_at']) ?>提交</span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
