# 备用IP池功能 - 最小升级包

本目录只包含本次功能涉及的文件，**不是整站覆盖包**。

## 上传到服务器

将本目录下文件按相同路径覆盖到 dnsmgr 站点根目录：

```
upgrade-backup-pool/
├── app/service/BackupPoolService.php   ← 新文件
├── app/service/TaskRunner.php
├── app/controller/Dmonitor.php
├── app/utils/MsgNotice.php
├── app/view/dmonitor/task.html
├── app/view/dmonitor/taskform.html
├── app/view/dmonitor/taskinfo.html
├── route/app.php
├── app/sql/install.sql                 ← 仅新安装需要，线上可忽略
├── app/sql/update.sql                  ← 参考用
└── migrate-backup-pool.sql             ← 线上必须执行
```

## 绝对不要覆盖

- config/database.php 或 .env（数据库配置）
- runtime/（缓存，可删后自动生成）
- vendor/（Composer 依赖）
- public/static/（除非你知道改过）

## 升级步骤

1. 备份数据库和站点目录
2. 上传并覆盖上述 9 个 PHP/HTML/路由文件（不含 sql 目录也可，sql 单独执行）
3. 在 MySQL 执行 `migrate-backup-pool.sql`
   - 若 `backup_mode` 字段已存在，跳过 ALTER 那句
4. 删除 `runtime/cache/*`
5. 重启容灾进程：`php think dmtask` 或 `docker restart dnsmgr`

## 验证

后台 → 容灾切换 → 添加策略 → 应看到「备用IP池（用后删除）」选项。
