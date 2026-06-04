-- 域名分类（域名列表/筛选依赖，幂等）
CREATE TABLE IF NOT EXISTS `dnsmgr_domain_category` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `remark` varchar(100) DEFAULT NULL,
  `sort` int(11) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `dnsmgr_domain`
ADD COLUMN `cid` int(11) unsigned NOT NULL DEFAULT '0';

ALTER TABLE `dnsmgr_domain`
ADD KEY `cid` (`cid`);
