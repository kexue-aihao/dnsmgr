-- Route 53 的记录 ID 编码了名称、类型和记录值，可能超过原来的 60 字符。
ALTER TABLE `dnsmgr_dmtask` MODIFY COLUMN `recordid` text NOT NULL;
ALTER TABLE `dnsmgr_sctask` MODIFY COLUMN `recordid` text NOT NULL;
ALTER TABLE `dnsmgr_aws_sync` MODIFY COLUMN `recordid` text NOT NULL;
