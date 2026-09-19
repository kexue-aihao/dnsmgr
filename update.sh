#!/usr/bin/env bash
#
# 彩虹 DNS (dnsmgr) 一键升级脚本
# 从 GitHub 拉取代码并同步到当前站点，自动执行增量数据库迁移
#
# 用法:
#   cd /www/wwwroot/your-dnsmgr-site
#   bash update.sh
#
# 可选环境变量:
#   DNSMGR_REPO=https://github.com/kexue-aihao/dnsmgr.git
#   DNSMGR_BRANCH=master
#   DNSMGR_SITE_DIR=/path/to/site   默认=脚本所在目录
#   DNSMGR_WEB_USER=www             文件所有者（不存在时自动检测）
#   DNSMGR_DRY_RUN=1                只预览不写入
#   DNSMGR_SKIP_COMPOSER=1          仅依赖文件未变化且 vendor 存在时跳过
#   DNSMGR_RELOAD_PHP=1             升级后尝试 reload php-fpm
#   DNSMGR_RSYNC_DELETE=1           删除源仓库已移除的代码（部署配置与数据目录仍保留）
#

set -euo pipefail

REPO_URL="${DNSMGR_REPO:-https://github.com/kexue-aihao/dnsmgr.git}"
BRANCH="${DNSMGR_BRANCH:-master}"
SITE_DIR="${DNSMGR_SITE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
UPDATE_SCRIPT="$SITE_DIR/update.sh"
WEB_USER="${DNSMGR_WEB_USER:-www}"
DRY_RUN="${DNSMGR_DRY_RUN:-0}"
SKIP_COMPOSER="${DNSMGR_SKIP_COMPOSER:-0}"
RELOAD_PHP="${DNSMGR_RELOAD_PHP:-0}"
TMP_DIR=""
BACKUP_ENV=""
TARGET_DB_VERSION=""

log()  { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
ok()   { log "OK  $*"; }
warn() { log "WARN $*"; }
err()  { log "ERR $*" >&2; }
die()  { err "$*"; exit 1; }

cleanup() {
  if [[ -n "$TMP_DIR" && -d "$TMP_DIR" ]]; then
    rm -rf "$TMP_DIR"
  fi
}
trap cleanup EXIT

require_cmd() {
  local cmd=$1
  command -v "$cmd" >/dev/null 2>&1 || die "缺少命令: $cmd"
}

read_env_value() {
  local key=$1
  local file="$SITE_DIR/.env"
  [[ -f "$file" ]] || die "未找到 .env，请先完成安装"
  php -r '
    $env = parse_ini_file($argv[1], true, INI_SCANNER_RAW);
    if ($env === false) exit(2);
    $env = array_change_key_case($env, CASE_UPPER);
    $database = $env["DATABASE"] ?? [];
    if (!is_array($database)) exit(2);
    $database = array_change_key_case($database, CASE_UPPER);
    if (!array_key_exists($argv[2], $database)) exit(1);
    echo $database[$argv[2]];
  ' "$file" "$key"
}

read_source_dbversion() {
  # config/app.php 含 app()/env()，只读取静态字面量，不能在升级工具中直接 require。
  php -r '
    $source = file_get_contents($argv[1]);
    if ($source === false) exit(1);
    $tokens = array_values(array_filter(token_get_all($source), static function ($token) {
      return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }));
    $versions = [];
    foreach ($tokens as $index => $token) {
      if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING
          || trim($token[1], chr(34) . chr(39)) !== "dbversion") continue;
      if (($tokens[$index + 1][0] ?? null) !== T_DOUBLE_ARROW) continue;
      $value = $tokens[$index + 2] ?? null;
      if (!is_array($value) || !in_array($value[0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER], true)) exit(2);
      $version = trim($value[1], chr(34) . chr(39));
      if (!preg_match("/^[0-9]+$/D", $version) || !in_array($tokens[$index + 3] ?? null, [",", "]"], true)) exit(2);
      $versions[] = $version;
    }
    if (count($versions) !== 1) exit(2);
    echo $versions[0];
  ' "$1"
}

detect_web_user() {
  if id "$WEB_USER" >/dev/null 2>&1; then
    return 0
  fi
  local u
  for u in www www-data nginx apache; do
    if id "$u" >/dev/null 2>&1; then
      WEB_USER="$u"
      ok "自动检测到 Web 用户: $WEB_USER"
      return 0
    fi
  done
  if [[ -d "$SITE_DIR/runtime" ]]; then
    local owner
    owner=$(stat -c '%U' "$SITE_DIR/runtime" 2>/dev/null || stat -f '%Su' "$SITE_DIR/runtime" 2>/dev/null || true)
    if [[ -n "$owner" && "$owner" != "root" ]] && id "$owner" >/dev/null 2>&1; then
      WEB_USER="$owner"
      ok "从 runtime 目录推断 Web 用户: $WEB_USER"
      return 0
    fi
  fi
  warn "未找到可用 Web 用户（当前: $WEB_USER），runtime 权限可能需手动 chown"
  return 1
}

mysql_exec() {
  local sql=$1
  if [[ "$DRY_RUN" == "1" ]]; then
    log "[DRY-RUN] SQL: ${sql:0:120}..."
    return 0
  fi
  MYSQL_PWD="$DB_PASS" mysql \
    -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" \
    --default-character-set=utf8mb4 \
    -e "$sql"
}

mysql_scalar() {
  local sql=$1
  if [[ "$DRY_RUN" == "1" ]]; then
    echo 0
    return 0
  fi
  MYSQL_PWD="$DB_PASS" mysql -N -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" \
    --default-character-set=utf8mb4 \
    -e "$sql" | head -1
}

mysql_exec_file() {
  local file=$1
  shift
  if [[ "$DRY_RUN" == "1" ]]; then
    log "[DRY-RUN] 执行 SQL 文件: $file"
    return 0
  fi
  # 由 PDO 逐项执行，只允许跳过已完成的字段/索引变更和旧账户字段重命名。
  # 任何其他 SQL 错误必须中断升级，不能登记为已完成。
  DNSMGR_DB_HOST="$DB_HOST" DNSMGR_DB_PORT="$DB_PORT" \
    DNSMGR_DB_NAME="$DB_NAME" DNSMGR_DB_USER="$DB_USER" \
    DNSMGR_DB_PASS="$DB_PASS" DNSMGR_DB_PREFIX="$DB_PREFIX" \
    php "$SITE_DIR/tools/migrate.php" "$file" "$@"
}

table_exists() {
  local table="${DB_PREFIX}$1"
  local count
  count=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='${table}'") || die "无法检查数据表: $table"
  [[ "${count:-0}" -gt 0 ]]
}

column_exists() {
  local table="${DB_PREFIX}$1"
  local col=$2
  local count
  count=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${table}' AND column_name='${col}'") || die "无法检查字段: $table.$col"
  [[ "${count:-0}" -gt 0 ]]
}

migration_applied() {
  local name=$1
  local count
  count=$(mysql_scalar "SELECT COUNT(*) FROM \`${DB_PREFIX}migration\` WHERE \`name\`='${name}'") || die "无法读取迁移记录"
  [[ "${count:-0}" -gt 0 ]]
}

mark_migration() {
  local name=$1
  mysql_exec "INSERT IGNORE INTO \`${DB_PREFIX}migration\` (\`name\`, \`applied_at\`) VALUES ('${name}', NOW());"
}

ensure_migration_table() {
  mysql_exec "CREATE TABLE IF NOT EXISTS \`${DB_PREFIX}migration\` (
    \`name\` varchar(128) NOT NULL,
    \`applied_at\` datetime NOT NULL,
    PRIMARY KEY (\`name\`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
}

apply_core_schema() {
  log "补齐上游历史表结构并迁移旧 DNS 账户配置..."
  # 不执行 install.sql：它包含 DROP TABLE。累计 update.sql 会保留现有数据。
  mysql_exec_file "$SITE_DIR/app/sql/update.sql" --core
}

apply_migrations() {
  local mig_dir="$SITE_DIR/app/sql/migrations"
  [[ -d "$mig_dir" ]] || { warn "无 migrations 目录，跳过数据库迁移"; return 0; }

  ensure_migration_table

  local files=()
  shopt -s nullglob
  files=("$mig_dir"/*.sql)
  shopt -u nullglob
  [[ ${#files[@]} -gt 0 ]] || { ok "没有待执行的 migration 文件"; return 0; }

  local file
  for file in "${files[@]}"; do
    local name
    name=$(basename "$file")
    [[ "$name" =~ ^[a-zA-Z0-9_.-]+\.sql$ ]] || die "迁移文件名不合法: $name"
    # 单位转换和迁移记录在同一事务内完成；即使中途退出也不会重复乘以 60。
    if [[ "$name" == "202506050001_aws_sync_frequency_seconds.sql" ]]; then
      migrate_aws_frequency_seconds "$name"
      continue
    fi
    if migration_applied "$name"; then
      log "已应用，跳过: $name"
      continue
    fi
    log "应用 migration: $name"
    mysql_exec_file "$file"
    if [[ "$DRY_RUN" != "1" ]]; then
      mark_migration "$name"
    fi
    ok "migration 完成: $name"
  done
}

migrate_aws_frequency_seconds() {
  local name=$1 default_value
  default_value=$(mysql_scalar "SELECT column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${DB_PREFIX}aws_sync' AND column_name='frequency'")
  if ! migration_applied "$name"; then
    if [[ "$default_value" == "3" ]]; then
      mysql_exec "START TRANSACTION;
        UPDATE \`${DB_PREFIX}aws_sync\` SET frequency = frequency * 60 WHERE frequency BETWEEN 1 AND 1440;
        INSERT INTO \`${DB_PREFIX}migration\` (name, applied_at) VALUES ('${name}', NOW());
        COMMIT;"
      ok "AWS 检测间隔已由分钟转换为秒"
    elif [[ "$default_value" == "10" ]]; then
      mark_migration "$name"
      ok "AWS 检测间隔已为秒，保留现有设置"
    else
      die "无法识别 aws_sync.frequency 的单位（默认值: $default_value），请检查表结构"
    fi
  fi
  if [[ "$default_value" != "10" ]]; then
    mysql_exec "ALTER TABLE \`${DB_PREFIX}aws_sync\` MODIFY COLUMN frequency int(11) NOT NULL DEFAULT 10;"
  fi
}

ensure_core_schema() {
  log "检查核心表结构（域名/账户列表依赖）..."

  if ! table_exists "account"; then
    die "表 ${DB_PREFIX}account 不存在，请先完成 dnsmgr 安装"
  fi
  if ! table_exists "domain"; then
    die "表 ${DB_PREFIX}domain 不存在，请先完成 dnsmgr 安装"
  fi

  if ! table_exists "domain_category"; then
    warn "缺少 ${DB_PREFIX}domain_category，正在补建..."
    mysql_exec "CREATE TABLE IF NOT EXISTS \`${DB_PREFIX}domain_category\` (
      \`id\` int(11) unsigned NOT NULL AUTO_INCREMENT,
      \`name\` varchar(50) NOT NULL,
      \`remark\` varchar(100) DEFAULT NULL,
      \`sort\` int(11) NOT NULL DEFAULT '0',
      \`addtime\` datetime DEFAULT NULL,
      PRIMARY KEY (\`id\`),
      KEY \`sort\` (\`sort\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ok "已创建 ${DB_PREFIX}domain_category"
  fi

  if ! column_exists "domain" "cid"; then
    warn "缺少 ${DB_PREFIX}domain.cid，正在补加..."
    mysql_exec "ALTER TABLE \`${DB_PREFIX}domain\` ADD COLUMN \`cid\` int(11) unsigned NOT NULL DEFAULT '0'"
    mysql_exec "ALTER TABLE \`${DB_PREFIX}domain\` ADD KEY \`cid\` (\`cid\`)"
    ok "已补加 ${DB_PREFIX}domain.cid"
  fi

  if ! column_exists "account" "name" && column_exists "account" "ak"; then
    warn "检测到旧版 account 表结构(ak)，正在迁移为 name/config..."
    if ! column_exists "account" "config"; then
      mysql_exec "ALTER TABLE \`${DB_PREFIX}account\` ADD COLUMN \`config\` text DEFAULT NULL"
    fi
    mysql_exec "ALTER TABLE \`${DB_PREFIX}account\` CHANGE COLUMN \`ak\` \`name\` varchar(255) NOT NULL"
    ok "account 表结构已更新"
  fi

  if ! column_exists "account" "config"; then
    warn "缺少 ${DB_PREFIX}account.config，正在补加..."
    mysql_exec "ALTER TABLE \`${DB_PREFIX}account\` ADD COLUMN \`config\` text DEFAULT NULL"
    ok "已补加 ${DB_PREFIX}account.config"
  fi

  if ! column_exists "dmtask" "backup_mode"; then
    warn "缺少 ${DB_PREFIX}dmtask.backup_mode，正在补加..."
    mysql_exec "ALTER TABLE \`${DB_PREFIX}dmtask\` ADD COLUMN \`backup_mode\` tinyint(1) NOT NULL DEFAULT 0"
    ok "已补加 ${DB_PREFIX}dmtask.backup_mode"
  fi

  if ! table_exists "dmbackup_pool"; then
    warn "缺少 ${DB_PREFIX}dmbackup_pool，正在补建..."
    mysql_exec "CREATE TABLE IF NOT EXISTS \`${DB_PREFIX}dmbackup_pool\` (
      \`id\` int(11) unsigned NOT NULL AUTO_INCREMENT,
      \`task_id\` int(11) unsigned NOT NULL,
      \`ip\` varchar(128) NOT NULL,
      \`sort\` int(11) NOT NULL DEFAULT 0,
      \`addtime\` int(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (\`id\`),
      KEY \`task_id\` (\`task_id\`),
      KEY \`ip\` (\`ip\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ok "已创建 ${DB_PREFIX}dmbackup_pool"
  fi

  if ! table_exists "aws_sync"; then
    warn "缺少 ${DB_PREFIX}aws_sync，正在补建..."
    mysql_exec "CREATE TABLE IF NOT EXISTS \`${DB_PREFIX}aws_sync\` (
      \`id\` int(11) unsigned NOT NULL AUTO_INCREMENT,
      \`did\` int(11) unsigned NOT NULL,
      \`rr\` varchar(128) NOT NULL,
      \`recordid\` text NOT NULL,
      \`recordinfo\` varchar(200) DEFAULT NULL,
      \`aws_account_id\` varchar(64) NOT NULL DEFAULT '',
      \`aws_region\` varchar(64) NOT NULL DEFAULT '',
      \`aws_instance_id\` varchar(64) NOT NULL DEFAULT '',
      \`last_ip\` varchar(128) DEFAULT NULL,
      \`last_dns_ip\` varchar(128) DEFAULT NULL,
      \`frequency\` int(11) NOT NULL DEFAULT 10,
      \`checktime\` int(11) NOT NULL DEFAULT 0,
      \`checknexttime\` int(11) NOT NULL DEFAULT 0,
      \`sync_count\` int(11) NOT NULL DEFAULT 0,
      \`status\` tinyint(1) NOT NULL DEFAULT 0,
      \`errmsg\` varchar(500) DEFAULT NULL,
      \`remark\` varchar(100) DEFAULT NULL,
      \`active\` tinyint(1) NOT NULL DEFAULT 1,
      \`addtime\` int(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (\`id\`),
      KEY \`did\` (\`did\`),
      KEY \`aws_instance_id\` (\`aws_instance_id\`),
      KEY \`checknexttime\` (\`checknexttime\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ok "已创建 ${DB_PREFIX}aws_sync"
  fi
}

mark_core_version() {
  [[ "$TARGET_DB_VERSION" =~ ^[0-9]+$ ]] || die "无法确认目标数据库版本"
  mysql_exec "INSERT INTO \`${DB_PREFIX}config\` (\`key\`, \`value\`) VALUES ('version', '${TARGET_DB_VERSION}')
    ON DUPLICATE KEY UPDATE \`value\` = VALUES(\`value\`);"
  ok "数据库版本已更新为 $TARGET_DB_VERSION"
}

upgrade_database() {
  apply_core_schema
  apply_migrations
  ensure_core_schema
  verify_upgrade
  # 所有迁移与关键结构验证成功后才写版本，避免 Web 首页重复执行累计 update.sql。
  mark_core_version
}

sync_files() {
  local src=$1
  local excludes=(
    --exclude=.env
    --exclude=/.env.*
    --exclude=.git
    --exclude=.gitignore
    --exclude=/.user.ini
    --exclude=/public/.user.ini
    --exclude=/.well-known/
    --exclude=/public/.well-known/
    --exclude=/docker-compose*.yml
    --exclude=/docker-compose*.yaml
    --exclude=/compose*.yml
    --exclude=/compose*.yaml
    --exclude=/.codex*/
    --exclude=/.claude/
    --exclude=/.ace-tool/
    --exclude=/.github/
    --exclude=/tools/tests/
    --exclude=/AGENTS.md
    --exclude=vendor/
    --exclude=runtime/
    --exclude=upgrade-backup-pool/
    --exclude=upgrade-aws-sync/
  )
  local rsync_opts=(-a)
  if [[ "${DNSMGR_RSYNC_DELETE:-0}" == "1" ]]; then
    rsync_opts+=("--delete")
    warn "已启用 --delete，将删除目标目录中源仓库不存在的代码；环境配置、runtime/vendor、部署文件与 ACME 验证目录仍排除"
  fi

  if [[ "$DRY_RUN" == "1" ]]; then
    log "[DRY-RUN] rsync 预览:"
    rsync -av "${rsync_opts[@]}" "${excludes[@]}" --dry-run "$src/" "$SITE_DIR/" | tail -20
    return 0
  fi

  rsync "${rsync_opts[@]}" "${excludes[@]}" "$src/" "$SITE_DIR/"
  ensure_update_script_executable
}

# rsync 从 Git 拉取的 update.sh 通常为 644，覆盖后需恢复 +x，否则下次无法 ./update.sh
ensure_update_script_executable() {
  [[ "$DRY_RUN" == "1" ]] && return 0
  if [[ -f "$UPDATE_SCRIPT" ]]; then
    chmod +x "$UPDATE_SCRIPT" 2>/dev/null && ok "已恢复 update.sh 可执行权限" \
      || warn "chmod +x update.sh 失败，下次请使用: bash update.sh"
  fi
}

prepare_runtime() {
  [[ "$DRY_RUN" == "1" ]] && return 0
  local dirs=(runtime runtime/cache runtime/log runtime/session runtime/temp)
  local d
  for d in "${dirs[@]}"; do
    mkdir -p "$SITE_DIR/$d"
  done

  # 完整清理 ThinkPHP 缓存（含 fields/schema 缓存，避免 /domain/data /account/data 500）
  rm -rf "$SITE_DIR/runtime/cache/"* "$SITE_DIR/runtime/temp/"* 2>/dev/null || true
  find "$SITE_DIR/runtime/cache" "$SITE_DIR/runtime/temp" -mindepth 1 -delete 2>/dev/null || true
  find "$SITE_DIR/runtime" -type f -name "*.php" -delete 2>/dev/null || true

  detect_web_user || true
  if id "$WEB_USER" >/dev/null 2>&1; then
    chown -R "$WEB_USER:$WEB_USER" "$SITE_DIR/runtime" 2>/dev/null || warn "chown runtime 失败，请手动: chown -R $WEB_USER:$WEB_USER runtime"
    chmod -R 775 "$SITE_DIR/runtime" 2>/dev/null || true
    ok "runtime 目录权限已修复 ($WEB_USER)"
  fi
}

fix_public_permissions() {
  [[ "$DRY_RUN" == "1" ]] && return 0
  detect_web_user || true
  if id "$WEB_USER" >/dev/null 2>&1; then
    if [[ -d "$SITE_DIR/public/static" ]]; then
      chown -R "$WEB_USER:$WEB_USER" "$SITE_DIR/public/static" 2>/dev/null || true
    fi
    chmod -R a+rX "$SITE_DIR/public/static" 2>/dev/null || true
  fi
}

invalidate_opcache() {
  [[ "$DRY_RUN" == "1" ]] && return 0
  touch "$SITE_DIR/public/index.php" 2>/dev/null || true
  if [[ "$RELOAD_PHP" == "1" ]]; then
    if command -v systemctl >/dev/null 2>&1; then
      for svc in php-fpm php8.5-fpm php8.4-fpm php8.3-fpm php8.2-fpm; do
        if systemctl is-active --quiet "$svc" 2>/dev/null; then
          systemctl reload "$svc" && ok "已 reload $svc" && return 0
        fi
      done
    fi
    warn "未找到可 reload 的 php-fpm 服务，可手动 reload 或设 DNSMGR_RELOAD_PHP=0"
  fi
}

verify_upgrade() {
  local assets=(
    public/static/js/jquery-3.7.1.min.js
    public/static/js/bootstrap-table-1.21.4.min.js
    public/static/js/custom.js
    public/static/js/xlsx.full.min.js
    public/static/css/bootstrap-table.css
    route/app.php
    app/controller/Domain.php
    app/controller/Awssync.php
    app/controller/Cloudflare.php
    app/service/BackupPoolService.php
    app/service/AwsSyncService.php
    app/service/CloudflareEnhanceService.php
    app/service/TaskRecordService.php
    app/lib/dns/goedge.php
    app/lib/dns/aws.php
    app/lib/dns/henet.php
    app/lib/dns/dynv6.php
    app/command/Awssynctask.php
    tools/migrate.php
    app/view/domain/record_import.html
    app/view/domain/record_search.html
  )
  local missing=0 f
  for f in "${assets[@]}"; do
    if [[ ! -f "$SITE_DIR/$f" ]]; then
      warn "缺少关键文件: $f（列表页可能空白）"
      missing=1
    fi
  done
  [[ "$missing" -eq 0 ]] || die "升级文件不完整"
  ok "关键程序/静态文件检查通过"

  if [[ "$DRY_RUN" == "1" ]]; then
    return 0
  fi

  local acct_cnt domain_cnt
  acct_cnt=$(mysql_scalar "SELECT COUNT(*) FROM \`${DB_PREFIX}account\`") || die "无法验证账户数据"
  domain_cnt=$(mysql_scalar "SELECT COUNT(*) FROM \`${DB_PREFIX}domain\`") || die "无法验证域名数据"
  log "数据库统计: ${acct_cnt:-0} 个域名账户, ${domain_cnt:-0} 个域名"

  if [[ "${acct_cnt:-0}" -eq 0 && "${domain_cnt:-0}" -eq 0 ]]; then
    warn "账户/域名数量均为 0；若升级前本有数据，请检查 .env 中 DATABASE/PREFIX 是否正确"
  fi

  local table column spec count
  for table in config account domain user permission log domain_alias domain_category dmtask dmlog sctask \
    aws_sync dmbackup_pool optimizeip cert_account cert_order cert_domain cert_deploy cert_cname; do
    table_exists "$table" || die "升级后缺少关键表: ${DB_PREFIX}${table}"
  done
  for spec in account:name account:config domain:cid domain:remark domain:is_notice domain:expiretime domain:checkstatus \
    user:totp_open user:totp_secret dmtask:proxy dmtask:cdn dmtask:backup_mode aws_sync:frequency; do
    table=${spec%%:*}
    column=${spec#*:}
    column_exists "$table" "$column" || die "升级后缺少关键字段: ${DB_PREFIX}${table}.${column}"
  done
  for table in dmtask sctask aws_sync; do
    count=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE()
      AND table_name='${DB_PREFIX}${table}' AND column_name='recordid' AND data_type IN ('text', 'mediumtext', 'longtext')
      AND collation_name='utf8mb4_bin'") \
      || die "无法验证 ${DB_PREFIX}${table}.recordid"
    [[ "${count:-0}" -gt 0 ]] || die "${DB_PREFIX}${table}.recordid 容量或大小写规则未完成升级"
  done
  ok "核心表结构与任务记录 ID 容量检查通过"
}

preflight_source() {
  local src=$1 f
  for f in app/controller/Awssync.php app/controller/Cloudflare.php app/service/BackupPoolService.php \
    app/service/AwsSyncService.php app/service/CloudflareEnhanceService.php app/command/Awssynctask.php \
    app/service/TaskRecordService.php app/lib/dns/goedge.php app/lib/dns/aws.php app/lib/dns/henet.php \
    app/lib/dns/dynv6.php app/lib/DnsHelper.php tools/migrate.php config/app.php composer.json composer.lock update.sh \
    app/sql/update.sql app/sql/migrations/202609190001_task_record_ids.sql \
    app/sql/migrations/202609190002_task_record_id_collation.sql \
    app/view/domain/record_import.html app/view/domain/record_search.html public/static/js/xlsx.full.min.js; do
    [[ -f "$src/$f" ]] || die "更新源缺少 $f，请使用已合并上游功能的本地分支仓库"
  done
  local conflict_status=0
  git -C "$src" grep -n -E '^(<<<<<<< |>>>>>>> )' -- app config route composer.json composer.lock update.sh tools/migrate.php \
    && conflict_status=0 || conflict_status=$?
  [[ "$conflict_status" == "1" ]] || die "更新源包含未解决的合并冲突，或无法读取源码（Git 状态: $conflict_status）"
  php -l "$src/tools/migrate.php" >/dev/null || die "更新源的迁移工具语法检查失败"
  if [[ "$SKIP_COMPOSER" == "1" ]]; then
    [[ -f "$SITE_DIR/vendor/autoload.php" ]] || die "vendor 不存在，不能跳过依赖安装"
    cmp -s "$src/composer.json" "$SITE_DIR/composer.json" && cmp -s "$src/composer.lock" "$SITE_DIR/composer.lock" \
      || die "Composer 依赖已变化，请取消 DNSMGR_SKIP_COMPOSER"
  else
    require_cmd composer
    # 在覆盖代码之前检查锁定依赖的 PHP 版本及扩展要求。
    composer check-platform-reqs --lock --no-dev --working-dir="$src"
  fi
}

run_composer() {
  [[ "$SKIP_COMPOSER" == "1" ]] && return 0
  log "执行 composer install --no-dev ..."
  if (cd "$SITE_DIR" && composer install --no-dev --no-interaction --prefer-dist 2>&1); then
    ok "composer 依赖更新完成"
  else
    die "composer 安装失败，升级未完成，请修复依赖后重新运行"
  fi
}

main() {
  log "========== dnsmgr 升级开始 =========="
  log "站点目录: $SITE_DIR"
  log "Git 仓库: $REPO_URL ($BRANCH)"

  require_cmd git
  require_cmd rsync
  require_cmd php
  require_cmd cmp
  php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "需要 PHP 8.2 或更高版本"

  [[ -d "$SITE_DIR" ]] || die "站点目录不存在: $SITE_DIR"
  [[ -f "$SITE_DIR/.env" ]] || die "未找到 $SITE_DIR/.env，请先安装 dnsmgr"

  TMP_DIR=$(mktemp -d)
  log "克隆/更新仓库到临时目录 ..."
  git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$TMP_DIR/src"
  ok "仓库拉取完成: $(git -C "$TMP_DIR/src" rev-parse --short HEAD 2>/dev/null || echo unknown)"

  preflight_source "$TMP_DIR/src"
  TARGET_DB_VERSION=$(read_source_dbversion "$TMP_DIR/src/config/app.php") || die "更新源没有明确的数字 dbversion，尚未覆盖站点"
  if [[ "$DRY_RUN" == "1" ]]; then
    sync_files "$TMP_DIR/src"
    log "[DRY-RUN] 将安装锁定依赖、补齐上游表结构和旧账户配置、执行增量迁移并清理缓存；未写入站点或数据库"
    return 0
  fi

  require_cmd mysql
  php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);' || die "缺少 pdo_mysql 扩展"
  DB_HOST=$(read_env_value HOSTNAME || echo 127.0.0.1)
  DB_NAME=$(read_env_value DATABASE) || die "无法读取 DATABASE"
  DB_USER=$(read_env_value USERNAME) || die "无法读取 USERNAME"
  DB_PASS=$(read_env_value PASSWORD || echo "")
  DB_PORT=$(read_env_value HOSTPORT || echo 3306)
  DB_PREFIX=$(read_env_value PREFIX || echo dnsmgr_)
  [[ "$DB_PREFIX" =~ ^[a-zA-Z0-9_]*$ ]] || die "数据库表前缀只能包含字母、数字和下划线"
  [[ "$DB_PORT" =~ ^[0-9]+$ ]] || die "数据库端口格式不正确"
  mysql_scalar "SELECT 1" >/dev/null || die "数据库连接失败"
  local table
  for table in account domain user; do
    table_exists "$table" || die "表 ${DB_PREFIX}${table} 不存在，请确认 .env 指向已安装的站点数据库"
  done
  log "数据库: ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME} 前缀=${DB_PREFIX}"
  BACKUP_ENV=$(mktemp)
  cp "$SITE_DIR/.env" "$BACKUP_ENV"
  ok "已备份 .env -> $BACKUP_ENV"

  log "同步程序文件 ..."
  sync_files "$TMP_DIR/src"
  ok "文件同步完成"

  if [[ -n "$BACKUP_ENV" && -f "$BACKUP_ENV" ]]; then
    cp "$BACKUP_ENV" "$SITE_DIR/.env"
    ok "已恢复 .env"
  fi

  run_composer
  upgrade_database
  prepare_runtime
  fix_public_permissions
  invalidate_opcache

  log "========== dnsmgr 升级完成 =========="
  log "若域名/账户列表仍空白: 浏览器 Ctrl+F5 强刷；F12 看 /domain/data 与 /account/data 是否 200"
  log "若仍 500: 查看 runtime/log/ 下最新日志；确认 runtime 归属 $WEB_USER 且可写"
  log "建议: 登录面板 -> 清理缓存；检查 计划任务 / 容灾切换 / AWS IP同步 是否正常"
  log "请按实际进程名重启容灾监控和 AWS 同步常驻进程，并确认 certtask 计划任务使用 PHP 8.2+"
  log "Supervisor 示例: supervisorctl restart dmtask awssynctask（仅填写已配置的进程名）；Docker 部署请重启容器"
  if [[ -n "$BACKUP_ENV" && -f "$BACKUP_ENV" ]]; then
    log ".env 备份仍保留在: $BACKUP_ENV （确认无误后可手动删除）"
  fi

  ensure_update_script_executable
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
