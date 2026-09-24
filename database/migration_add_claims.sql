-- 失物认领协同功能迁移脚本
-- 执行此 SQL 来添加失物认领协同所需的表结构
--
-- 功能说明：
--   1. messages 增加 visitor_id，用于标记留言发布者（认领凭证/核验仅发布者可操作）
--   2. claim_settings：捡到物品的留言发布者登记认领凭证要求、保管地点（与留言 1:1）
--   3. claim_candidates：失主提交的认领申请（候选），按提交先后排队
--   4. claim_events：认领办理过程中的全部处理记录（登记/申请/确认/驳回/恢复/变更）

USE `community_board`;

-- 留言表增加访客标识（发布者身份）
ALTER TABLE `messages`
    ADD COLUMN `visitor_id` VARCHAR(64) DEFAULT NULL COMMENT '发布者访客标识' AFTER `phone`,
    ADD INDEX `idx_visitor_id` (`visitor_id`);

-- 认领设置表（发布者登记认领凭证与保管地点）
CREATE TABLE IF NOT EXISTS `claim_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL COMMENT '失物招领留言ID',
    `claim_requirement` VARCHAR(500) NOT NULL COMMENT '认领凭证要求（需凭什么核验，如钥匙颜色/物品特征）',
    `storage_location` VARCHAR(200) NOT NULL COMMENT '物品保管地点',
    `setup_note` VARCHAR(500) DEFAULT NULL COMMENT '补充说明',
    `setup_by` VARCHAR(64) NOT NULL COMMENT '登记人访客标识（发布者）',
    `claim_status` TINYINT NOT NULL DEFAULT 1 COMMENT '办理状态: 1待核验, 2已认领',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '首次登记时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近更新时间',
    UNIQUE KEY `uk_message` (`message_id`),
    INDEX `idx_claim_status` (`claim_status`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='失物认领设置表';

-- 认领候选表（失主提交的认领申请）
CREATE TABLE IF NOT EXISTS `claim_candidates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL COMMENT '失物招领留言ID',
    `visitor_id` VARCHAR(64) NOT NULL COMMENT '申请人访客标识（失主）',
    `contact_name` VARCHAR(50) NOT NULL COMMENT '认领人称呼',
    `contact_phone` VARCHAR(20) DEFAULT NULL COMMENT '认领人联系方式',
    `claim_evidence` VARCHAR(1000) NOT NULL COMMENT '认领凭证描述（物品特征/凭证信息）',
    `claim_progress` VARCHAR(1000) DEFAULT NULL COMMENT '认领进度补充说明',
    `status` TINYINT NOT NULL DEFAULT 0 COMMENT '候选状态: 0待核验, 1已确认, 2已驳回',
    `reject_reason` VARCHAR(255) DEFAULT NULL COMMENT '驳回原因（publisher_reject发布者驳回 / auto_superseded被其他候选确认后自动置驳）',
    `processed_note` VARCHAR(500) DEFAULT NULL COMMENT '核验备注',
    `processed_at` DATETIME DEFAULT NULL COMMENT '核验时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '提交时间（排队依据）',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近更新时间',
    -- 同一访客对同一物品只保留一条有效候选；被驳回后重新申请走 UPDATE 复用原记录
    UNIQUE KEY `uk_message_visitor` (`message_id`, `visitor_id`),
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_visitor_id` (`visitor_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='认领候选表';

-- 认领处理记录表（保留完整办理轨迹）
CREATE TABLE IF NOT EXISTS `claim_events` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL COMMENT '失物招领留言ID',
    `candidate_id` INT UNSIGNED DEFAULT NULL COMMENT '关联候选ID',
    `actor_visitor_id` VARCHAR(64) NOT NULL COMMENT '操作人访客标识',
    `event_type` VARCHAR(30) NOT NULL COMMENT '事件类型: setup登记, apply申请, confirm确认, reject驳回, restore恢复, change变更',
    `detail` VARCHAR(1000) DEFAULT NULL COMMENT '事件详情',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '发生时间',
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_candidate_id` (`candidate_id`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`candidate_id`) REFERENCES `claim_candidates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='认领处理记录表';

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'claim_%';
-- DESCRIBE claim_settings;
-- DESCRIBE claim_candidates;
-- DESCRIBE claim_events;
