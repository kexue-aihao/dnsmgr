-- AWS IP 同步：检测间隔由分钟改为秒
UPDATE `dnsmgr_aws_sync` SET `frequency` = `frequency` * 60 WHERE `frequency` >= 1 AND `frequency` <= 1440;
ALTER TABLE `dnsmgr_aws_sync` MODIFY COLUMN `frequency` int(11) NOT NULL DEFAULT 10;
