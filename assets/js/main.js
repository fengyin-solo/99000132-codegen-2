/**
 * 社区便民留言板 - 前端脚本
 */
document.addEventListener('DOMContentLoaded', function() {
    // 滚动信息复制实现无缝滚动
    const scrollContent = document.getElementById('scrollContent');
    if (scrollContent) {
        scrollContent.innerHTML += scrollContent.innerHTML;
    }
});

/**
 * 切换收藏状态
 */
function toggleFavorite(event, btn) {
    event.preventDefault();
    event.stopPropagation();

    const messageId = btn.dataset.messageId;
    if (!messageId) return;

    const icon = btn.querySelector('.favorite-icon');
    const text = btn.querySelector('.favorite-text');
    const originalIcon = icon.textContent;
    const originalText = text.textContent;
    const originalClass = btn.className;

    btn.disabled = true;

    const formData = new FormData();
    formData.append('message_id', messageId);
    formData.append('action', 'toggle');

    fetch('api/favorite.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.code === 0) {
            if (result.data.favorited) {
                btn.classList.add('favorited');
                btn.classList.remove('btn-secondary');
                btn.classList.add('btn-warning');
                icon.textContent = '⭐';
                text.textContent = '已收藏';
                showToast(result.msg, 'success');
            } else {
                btn.classList.remove('favorited');
                btn.classList.remove('btn-warning');
                btn.classList.add('btn-secondary');
                icon.textContent = '☆';
                text.textContent = '收藏';
                showToast(result.msg, 'info');

                if (window.location.pathname.includes('favorites.php')) {
                    const card = btn.closest('.message-card');
                    if (card) {
                        card.style.transition = 'all 0.3s ease';
                        card.style.opacity = '0';
                        card.style.transform = 'translateX(-100px)';
                        setTimeout(() => {
                            card.remove();
                            updateFavoritesStats();
                            checkEmptyState();
                        }, 300);
                    }
                }
            }
        } else {
            showToast(result.msg || '操作失败', 'error');
            icon.textContent = originalIcon;
            text.textContent = originalText;
            btn.className = originalClass;
        }
    })
    .catch(error => {
        console.error('收藏操作失败:', error);
        showToast('网络错误，请稍后重试', 'error');
        icon.textContent = originalIcon;
        text.textContent = originalText;
        btn.className = originalClass;
    })
    .finally(() => {
        btn.disabled = false;
    });
}

/**
 * 更新收藏页面统计数据
 */
function updateFavoritesStats() {
    const statNumbers = document.querySelectorAll('.favorites-stats .stat-number');
    statNumbers.forEach(el => {
        const current = parseInt(el.textContent) || 0;
        if (current > 0) {
            el.textContent = current - 1;
        }
    });

    const subtitle = document.querySelector('.page-subtitle');
    if (subtitle) {
        const match = subtitle.textContent.match(/\d+/);
        if (match) {
            const current = parseInt(match[0]) || 0;
            subtitle.textContent = `共收藏 ${Math.max(0, current - 1)} 条留言`;
        }
    }
}

/**
 * 检查收藏页面是否为空
 */
function checkEmptyState() {
    const list = document.querySelector('.message-list');
    if (!list) return;

    const cards = list.querySelectorAll('.message-card');
    if (cards.length === 0) {
        const container = document.querySelector('.message-list-section .container');
        if (container) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">⭐</div>
                    <p>暂无收藏的留言</p>
                    <a href="index.php" class="btn btn-primary">去浏览留言</a>
                </div>
            `;
        }
    }
}

/**
 * 显示提示消息
 */
function showToast(message, type = 'info') {
    const existingToast = document.querySelector('.toast-message');
    if (existingToast) {
        existingToast.remove();
    }

    const toast = document.createElement('div');
    toast.className = `toast-message toast-${type}`;
    toast.textContent = message;

    const styles = {
        position: 'fixed',
        top: '80px',
        left: '50%',
        transform: 'translateX(-50%)',
        padding: '12px 24px',
        borderRadius: '8px',
        color: '#fff',
        fontSize: '0.9rem',
        zIndex: '9999',
        boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
        animation: 'slideDown 0.3s ease',
        maxWidth: '90%',
        textAlign: 'center'
    };

    const typeColors = {
        success: 'background: #10b981',
        error: 'background: #ef4444',
        info: 'background: #3b82f6',
        warning: 'background: #f59e0b'
    };

    Object.assign(toast.style, styles);
    toast.style.cssText += ';' + (typeColors[type] || typeColors.info);

    const styleSheet = document.createElement('style');
    styleSheet.textContent = `
        @keyframes slideDown {
            from { opacity: 0; transform: translate(-50%, -20px); }
            to { opacity: 1; transform: translate(-50%, 0); }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
    `;
    document.head.appendChild(styleSheet);

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'fadeOut 0.3s ease forwards';
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 2000);
}

/**
 * 打开举报弹窗
 */
function openReportModal(messageId) {
    const modal = document.getElementById('reportModal');
    if (!modal) return;

    document.getElementById('reportMessageId').value = messageId;
    document.getElementById('reportForm').reset();
    document.getElementById('reportDescCount').textContent = '0';
    modal.style.display = 'flex';
}

/**
 * 关闭举报弹窗
 */
function closeReportModal() {
    const modal = document.getElementById('reportModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * 初始化举报表单
 */
function initReportForm() {
    const form = document.getElementById('reportForm');
    if (!form) return;

    const descInput = document.getElementById('reportDescription');
    const descCount = document.getElementById('reportDescCount');

    if (descInput && descCount) {
        descInput.addEventListener('input', function() {
            descCount.textContent = this.value.length;
        });
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        submitReport();
    });

    document.getElementById('reportModal').addEventListener('click', function(e) {
        if (e.target === this) closeReportModal();
    });
}

/**
 * 提交举报
 */
function submitReport() {
    const form = document.getElementById('reportForm');
    if (!form) return;

    const submitBtn = document.getElementById('reportSubmitBtn');
    const messageId = document.getElementById('reportMessageId').value;
    const reportType = form.querySelector('input[name="report_type"]:checked');
    const description = document.getElementById('reportDescription').value;

    if (!reportType) {
        showToast('请选择举报类型', 'warning');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = '提交中...';

    const formData = new FormData();
    formData.append('message_id', messageId);
    formData.append('report_type', reportType.value);
    formData.append('description', description);
    formData.append('action', 'submit');

    fetch('api/report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.code === 0) {
            showToast(result.msg, 'success');
            closeReportModal();

            const reportBtn = document.querySelector('.report-btn[data-message-id="' + messageId + '"]');
            if (reportBtn) {
                reportBtn.disabled = true;
                reportBtn.classList.remove('btn-danger');
                reportBtn.classList.add('btn-secondary');
                const reportText = reportBtn.querySelector('.report-text');
                if (reportText) {
                    reportText.textContent = '已举报';
                }
            }
        } else {
            showToast(result.msg || '举报失败', 'error');
        }
    })
    .catch(error => {
        console.error('举报提交失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = '提交举报';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initReportForm();
    initClaimForms();
});

/* ===================== 失物认领协同 ===================== */

/**
 * 统一发起认领协同请求
 * 网络失败不做本地状态变更，重试由服务端幂等处理，不会覆盖已有候选
 */
function claimRequest(formData, btn, busyText) {
    const originalText = btn ? btn.textContent : '';
    if (btn) {
        btn.disabled = true;
        btn.textContent = busyText || '处理中...';
    }

    return fetch('api/claim.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.code === 0) {
            showToast(result.msg, 'success');
            // 重新读取详情：办理状态流转/恢复/变更后，候选列表与详情回到最新阶段
            window.location.reload();
            return result;
        }
        showToast(result.msg || '操作失败', 'error');
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        return null;
    })
    .catch(error => {
        console.error('认领操作失败:', error);
        showToast('网络错误，请稍后重试（重试不会覆盖已提交的申请）', 'error');
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        return null;
    });
}

/* ----- 认领凭证与保管地点登记 ----- */
function openRegisterModal(messageId, claimProof, keepLocation) {
    const modal = document.getElementById('registerModal');
    if (!modal) return;
    document.getElementById('registerMessageId').value = messageId;
    document.getElementById('registerProof').value = claimProof || '';
    document.getElementById('registerLocation').value = keepLocation || '';
    modal.style.display = 'flex';
}

function closeRegisterModal() {
    const modal = document.getElementById('registerModal');
    if (modal) modal.style.display = 'none';
}

/* ----- 失主提交认领 / 补充凭证 ----- */
function openApplyModal(messageId, claimId, name, contact, progress) {
    const modal = document.getElementById('applyModal');
    if (!modal) return;
    document.getElementById('applyMessageId').value = messageId;
    document.getElementById('applyClaimId').value = claimId || 0;
    document.getElementById('applyName').value = name || '';
    document.getElementById('applyContact').value = contact || '';
    document.getElementById('applyProgress').value = progress || '';
    const submitBtn = document.getElementById('applySubmitBtn');
    const titleEl = modal.querySelector('h3');
    if (claimId) {
        if (titleEl) titleEl.textContent = '📝 补充认领凭证';
        submitBtn.textContent = '补充凭证';
    } else {
        if (titleEl) titleEl.textContent = '🙋 提交认领申请';
        submitBtn.textContent = '提交认领';
    }
    modal.style.display = 'flex';
}

function closeApplyModal() {
    const modal = document.getElementById('applyModal');
    if (modal) modal.style.display = 'none';
}

/* ----- 发布者驳回 ----- */
function openReviewModal(claimId, reviewAction) {
    const modal = document.getElementById('reviewModal');
    if (!modal) return;
    const section = document.getElementById('claimSection');
    document.getElementById('reviewMessageId').value = section ? section.dataset.messageId : '';
    document.getElementById('reviewClaimId').value = claimId;
    document.getElementById('reviewNote').value = '';
    modal.style.display = 'flex';
}

function closeReviewModal() {
    const modal = document.getElementById('reviewModal');
    if (modal) modal.style.display = 'none';
}

/**
 * 发布者核验（确认/恢复）或失主撤回等无需弹窗的操作
 */
function claimAction(btn, reviewAction, claimId) {
    const section = document.getElementById('claimSection');
    if (!section) return;

    if (reviewAction === 'withdraw' && !window.confirm('确定撤回您的认领申请吗？')) {
        return;
    }
    if (reviewAction === 'restore' && !window.confirm('恢复后该申请将回到待核验阶段，确定吗？')) {
        return;
    }

    const formData = new FormData();
    formData.append('message_id', section.dataset.messageId);
    formData.append('claim_id', claimId);
    formData.append('action', 'review');
    formData.append('review_action', reviewAction);

    if (reviewAction === 'withdraw') {
        formData.set('action', 'withdraw');
        formData.delete('review_action');
    }

    const busyTextMap = {confirm: '确认中...', reject: '驳回中...', restore: '恢复中...', withdraw: '撤回中...'};
    claimRequest(formData, btn, busyTextMap[reviewAction] || '处理中...');
}

function initClaimForms() {
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'register');
            claimRequest(formData, document.getElementById('registerSubmitBtn'), '保存中...');
        });
    }

    const applyForm = document.getElementById('applyForm');
    if (applyForm) {
        applyForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // 凭证不完整也允许提交：服务端登记为候选并停在待核验状态
            const formData = new FormData(this);
            const isSupplement = parseInt(document.getElementById('applyClaimId').value, 10) > 0;
            if (isSupplement) {
                // 补充凭证时三项必须齐全，否则仍停在待核验
                const name = document.getElementById('applyName').value.trim();
                const contact = document.getElementById('applyContact').value.trim();
                const progress = document.getElementById('applyProgress').value.trim();
                if (!name || !contact || !progress) {
                    showToast('请补全称呼、联系方式和认领说明', 'warning');
                    return;
                }
                formData.append('action', 'supplement');
            } else {
                formData.append('action', 'apply');
            }
            claimRequest(formData, document.getElementById('applySubmitBtn'),
                isSupplement ? '补充中...' : '提交中...');
        });
    }

    const reviewForm = document.getElementById('reviewForm');
    if (reviewForm) {
        reviewForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'review');
            formData.append('review_action', 'reject');
            claimRequest(formData, document.getElementById('reviewSubmitBtn'), '驳回中...');
        });
    }

    // 点击遮罩关闭弹窗
    ['registerModal', 'applyModal', 'reviewModal'].forEach(function(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) this.style.display = 'none';
            });
        }
    });
}
