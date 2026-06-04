-- 备用 IP 池（幂等）
ALTER TABLE `dnsmgr_dmtask`
ADD COLUMN `backup_mode` tinyint(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `dnsmgr_dmbackup_pool` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int(11) unsigned NOT NULL,
  `ip` varchar(128) NOT NULL,
  `sort` int(11) NOT NULL DEFAULT 0,
  `addtime` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `task_id` (`task_id`),
  KEY `ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
