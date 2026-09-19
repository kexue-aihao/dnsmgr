-- Route 53 使用区分大小写的 Base64URL ID，旧表默认排序规则会把不同记录当成同一条。
ALTER TABLE `dnsmgr_dmtask` MODIFY COLUMN `recordid` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE `dnsmgr_sctask` MODIFY COLUMN `recordid` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE `dnsmgr_aws_sync` MODIFY COLUMN `recordid` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
