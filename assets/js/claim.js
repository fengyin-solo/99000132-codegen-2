/**
 * 失物认领协同 - 前端脚本
 * 所有写操作成功后整页刷新，使办理状态、候选列表、处理记录统一回到最新阶段
 * （服务端在同一事务内返回一致快照，避免进度与列表不一致）
 */
(function () {
    const section = document.getElementById('claimSection');
    if (!section) return;

    const messageId = section.dataset.messageId;

    /**
     * 提交认领相关操作；网络失败时给出提示，按钮恢复，绝不本地伪造状态
     * 服务端对重复申请/重试做了幂等保护，不会覆盖已有候选
     */
    function postAction(action, data, btn) {
        const formData = new FormData();
        formData.append('message_id', messageId);
        formData.append('action', action);
        Object.keys(data).forEach(function (key) {
            formData.append(key, data[key]);
        });

        if (btn) {
            btn.disabled = true;
            btn.dataset.originalText = btn.textContent;
            btn.textContent = '处理中...';
        }

        fetch('api/claim.php', {
            method: 'POST',
            body: formData
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                if (result.code === 0) {
                    showToast(result.msg || '操作成功', 'success');
                    // 回到服务端渲染的最新详情：办理状态与候选列表天然一致
                    setTimeout(function () {
                        window.location.reload();
                    }, 600);
                } else {
                    showToast(result.msg || '操作失败', 'error');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = btn.dataset.originalText || btn.textContent;
                    }
                }
            })
            .catch(function (error) {
                console.error('认领操作网络失败:', error);
                showToast('网络错误，请稍后重试（不会影响已提交的申请）', 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = btn.dataset.originalText || btn.textContent;
                }
            });
    }

    function bindCharCount(textareaSelector, counterSelector) {
        const ta = document.querySelector(textareaSelector);
        const counter = document.querySelector(counterSelector);
        if (ta && counter) {
            counter.textContent = ta.value.length;
            ta.addEventListener('input', function () {
                counter.textContent = this.value.length;
            });
        }
    }

    // ---------- 发布者：登记 / 修改认领凭证与保管地点 ----------
    const setupForm = document.getElementById('claimSetupForm');
    if (setupForm) {
        bindCharCount('#claimRequirement', '.setup-req-count');
        bindCharCount('#setupNote', '.setup-note-count');

        const editBtn = document.getElementById('editSetupBtn');
        const cancelBtn = document.getElementById('cancelEditSetupBtn');
        if (editBtn) {
            editBtn.addEventListener('click', function () {
                setupForm.style.display = 'block';
                editBtn.style.display = 'none';
            });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                setupForm.style.display = 'none';
                if (editBtn) editBtn.style.display = 'inline';
            });
        }

        setupForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const requirement = document.getElementById('claimRequirement').value.trim();
            const storageLocation = document.getElementById('storageLocation').value.trim();
            const note = document.getElementById('setupNote').value.trim();

            if (!requirement) {
                showToast('请填写认领凭证要求', 'warning');
                return;
            }
            if (!storageLocation) {
                showToast('请填写保管地点', 'warning');
                return;
            }

            postAction('setup', {
                claim_requirement: requirement,
                storage_location: storageLocation,
                setup_note: note
            }, setupForm.querySelector('.claim-submit-btn'));
        });
    }

    // ---------- 失主：提交认领申请 ----------
    const applyForm = document.getElementById('claimApplyForm');
    if (applyForm) {
        bindCharCount('#claimEvidence', '.apply-evidence-count');
        bindCharCount('#claimProgress', '.apply-progress-count');

        applyForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const contactName = document.getElementById('contactName').value.trim();
            const contactPhone = document.getElementById('contactPhone').value.trim();
            const evidence = document.getElementById('claimEvidence').value.trim();
            const progress = document.getElementById('claimProgress').value.trim();

            // 凭证不完整在前端也拦一道，停留在表单（服务端同样校验并保持待核验状态）
            if (!contactName) {
                showToast('请填写您的称呼', 'warning');
                return;
            }
            if (!evidence) {
                showToast('请填写认领凭证', 'warning');
                return;
            }

            postAction('apply', {
                contact_name: contactName,
                contact_phone: contactPhone,
                claim_evidence: evidence,
                claim_progress: progress
            }, applyForm.querySelector('.claim-submit-btn'));
        });
    }

    // ---------- 发布者：确认 / 驳回 ----------
    section.querySelectorAll('.candidate-confirm-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const candidateId = btn.dataset.candidateId;
            const note = window.prompt('确认该失主认领？可填写核验备注（选填）：', '');
            if (note === null) return; // 取消
            postAction('confirm', {
                candidate_id: candidateId,
                processed_note: note.trim()
            }, btn);
        });
    });

    section.querySelectorAll('.candidate-reject-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const candidateId = btn.dataset.candidateId;
            const reason = window.prompt('请填写驳回原因（凭证不完整/描述不符等，失主可见）：', '');
            if (reason === null) return; // 取消
            if (!reason.trim()) {
                showToast('请填写驳回原因', 'warning');
                return;
            }
            postAction('reject', {
                candidate_id: candidateId,
                processed_note: reason.trim()
            }, btn);
        });
    });

    // ---------- 发布者：撤销确认，恢复待核验 ----------
    const restoreBtn = document.getElementById('restoreClaimBtn');
    if (restoreBtn) {
        restoreBtn.addEventListener('click', function () {
            if (!window.confirm('确定撤销当前确认，恢复为待核验？候选将重新进入核验队列。')) return;
            postAction('restore', {}, restoreBtn);
        });
    }
})();
