-- 失物认领协同表迁移脚本
-- 执行此 SQL 来添加失物认领协同功能所需的表结构

USE `community_board`;

-- 留言表增加发布者访客标识，用于认领核验时确认发布者身份
ALTER TABLE `messages`
    ADD COLUMN `visitor_id` VARCHAR(64) DEFAULT NULL COMMENT '发布者访客标识' AFTER `phone`,
    ADD INDEX `idx_visitor_id` (`visitor_id`);

-- 失物招领信息表：捡到物品的发布者登记认领凭证与保管地点
CREATE TABLE IF NOT EXISTS `lost_found_info` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL COMMENT '关联失物招领留言ID',
    `visitor_id` VARCHAR(64) NOT NULL COMMENT '登记人（发布者）访客标识',
    `claim_proof` VARCHAR(500) NOT NULL COMMENT '认领凭证：只有失主才知道的核验信息',
    `keep_location` VARCHAR(200) NOT NULL COMMENT '物品保管地点',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '登记时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    UNIQUE KEY `uk_message_id` (`message_id`),
    INDEX `idx_visitor_id` (`visitor_id`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='失物招领信息表';

-- 认领候选表：失主提交认领进度，发布者核验确认或驳回
CREATE TABLE IF NOT EXISTS `lost_claims` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL COMMENT '关联失物招领留言ID',
    `visitor_id` VARCHAR(64) NOT NULL COMMENT '认领人访客标识',
    `claimant_name` VARCHAR(50) NOT NULL COMMENT '认领人称呼',
    `contact` VARCHAR(100) NOT NULL COMMENT '认领人联系方式',
    `claim_progress` VARCHAR(1000) NOT NULL COMMENT '认领进度：物品描述、遗失经过等',
    `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待核验, 1已确认, 2已驳回, 3已撤回',
    `is_complete` TINYINT NOT NULL DEFAULT 0 COMMENT '凭证是否完整: 0不完整(停在待核验), 1完整',
    `review_note` VARCHAR(500) DEFAULT NULL COMMENT '核验备注',
    `reviewed_at` DATETIME DEFAULT NULL COMMENT '核验时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '提交认领时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_visitor_id` (`visitor_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='失物认领候选表';

-- 认领处理记录表：记录每一次流转、恢复或变更，候选列表与详情始终回到最新阶段
CREATE TABLE IF NOT EXISTS `lost_claim_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `claim_id` INT UNSIGNED NOT NULL COMMENT '关联认领候选ID',
    `message_id` INT UNSIGNED NOT NULL COMMENT '关联失物招领留言ID',
    `visitor_id` VARCHAR(64) NOT NULL COMMENT '操作人访客标识',
    `action` VARCHAR(20) NOT NULL COMMENT '动作: submit提交, confirm确认, reject驳回, withdraw撤回, restore恢复待核验, change变更',
    `from_status` TINYINT DEFAULT NULL COMMENT '变更前状态',
    `to_status` TINYINT DEFAULT NULL COMMENT '变更后状态',
    `note` VARCHAR(500) DEFAULT NULL COMMENT '备注',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '操作时间',
    INDEX `idx_claim_id` (`claim_id`),
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`claim_id`) REFERENCES `lost_claims`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='失物认领处理记录表';

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'lost_%';
-- DESCRIBE lost_found_info;
-- DESCRIBE lost_claims;
-- DESCRIBE lost_claim_logs;
