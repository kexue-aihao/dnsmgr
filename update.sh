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
#   DNSMGR_SKIP_COMPOSER=1          跳过 composer install
#   DNSMGR_RELOAD_PHP=1             升级后尝试 reload php-fpm
#

set -euo pipefail

REPO_URL="${DNSMGR_REPO:-https://github.com/kexue-aihao/dnsmgr.git}"
BRANCH="${DNSMGR_BRANCH:-master}"
SITE_DIR="${DNSMGR_SITE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
WEB_USER="${DNSMGR_WEB_USER:-www}"
DRY_RUN="${DNSMGR_DRY_RUN:-0}"
SKIP_COMPOSER="${DNSMGR_SKIP_COMPOSER:-0}"
RELOAD_PHP="${DNSMGR_RELOAD_PHP:-0}"
TMP_DIR=""
BACKUP_ENV=""

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
  local line
  line=$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" | tail -1 || true)
  [[ -n "$line" ]] || return 1
  echo "$line" | cut -d'=' -f2- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | tr -d '\r'
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
    -e "$sql" 2>/dev/null | head -1
}

mysql_exec_file() {
  local file=$1
  if [[ "$DRY_RUN" == "1" ]]; then
    log "[DRY-RUN] 执行 SQL 文件: $file"
    return 0
  fi
  local tmp_sql
  tmp_sql=$(mktemp)
  sed "s/dnsmgr_/${DB_PREFIX}/g" "$file" > "$tmp_sql"
  MYSQL_PWD="$DB_PASS" mysql \
    -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" \
    --default-character-set=utf8mb4 \
    --force \
    < "$tmp_sql" || true
  rm -f "$tmp_sql"
}

table_exists() {
  local table="${DB_PREFIX}$1"
  local count
  count=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='${table}'" || echo 0)
  [[ "${count:-0}" -gt 0 ]]
}

column_exists() {
  local table="${DB_PREFIX}$1"
  local col=$2
  local count
  count=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='${DB_NAME}' AND table_name='${table}' AND column_name='${col}'" || echo 0)
  [[ "${count:-0}" -gt 0 ]]
}

migration_applied() {
  local name=$1
  local count
  count=$(MYSQL_PWD="$DB_PASS" mysql -N -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" \
    -e "SELECT COUNT(*) FROM \`${DB_PREFIX}migration\` WHERE \`name\`='${name}'" 2>/dev/null || echo 0)
  [[ "${count:-0}" -gt 0 ]]
}

mark_migration() {
  local name=$1
  mysql_exec "INSERT INTO \`${DB_PREFIX}migration\` (\`name\`, \`applied_at\`) VALUES ('${name}', NOW());"
}

ensure_migration_table() {
  mysql_exec "CREATE TABLE IF NOT EXISTS \`${DB_PREFIX}migration\` (
    \`name\` varchar(128) NOT NULL,
    \`applied_at\` datetime NOT NULL,
    PRIMARY KEY (\`name\`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
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

  IFS=$'\n' files=($(printf '%s\n' "${files[@]}" | sort))
  unset IFS

  for file in "${files[@]}"; do
    local name
    name=$(basename "$file")
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
    mysql_exec "ALTER TABLE \`${DB_PREFIX}domain\` ADD COLUMN \`cid\` int(11) unsigned NOT NULL DEFAULT '0'" || true
    mysql_exec "ALTER TABLE \`${DB_PREFIX}domain\` ADD KEY \`cid\` (\`cid\`)" || true
    ok "已补加 ${DB_PREFIX}domain.cid"
  fi

  if ! column_exists "account" "name" && column_exists "account" "ak"; then
    warn "检测到旧版 account 表结构(ak)，正在迁移为 name/config..."
    mysql_exec "ALTER TABLE \`${DB_PREFIX}account\` ADD COLUMN \`config\` text DEFAULT NULL" || true
    mysql_exec "ALTER TABLE \`${DB_PREFIX}account\` CHANGE COLUMN \`ak\` \`name\` varchar(255) NOT NULL" || true
    ok "account 表结构已更新"
  fi

  if ! column_exists "account" "config"; then
    warn "缺少 ${DB_PREFIX}account.config，正在补加..."
    mysql_exec "ALTER TABLE \`${DB_PREFIX}account\` ADD COLUMN \`config\` text DEFAULT NULL" || true
    ok "已补加 ${DB_PREFIX}account.config"
  fi
}

sync_files() {
  local src=$1
  local excludes=(
    --exclude=.env
    --exclude=.git
    --exclude=.gitignore
    --exclude=vendor/
    --exclude=runtime/
    --exclude=upgrade-backup-pool/
    --exclude=upgrade-aws-sync/
  )
  local rsync_opts=(-a)
  if [[ "${DNSMGR_RSYNC_DELETE:-0}" == "1" ]]; then
    rsync_opts+=("--delete")
    warn "已启用 --delete，将删除目标目录中源仓库不存在的文件（.env/runtime/vendor 仍排除）"
  fi

  if [[ "$DRY_RUN" == "1" ]]; then
    log "[DRY-RUN] rsync 预览:"
    rsync -av "${rsync_opts[@]}" "${excludes[@]}" --dry-run "$src/" "$SITE_DIR/" | tail -20
    return 0
  fi

  rsync "${rsync_opts[@]}" "${excludes[@]}" "$src/" "$SITE_DIR/"
}

prepare_runtime() {
  local dirs=(runtime runtime/cache runtime/log runtime/session runtime/temp)
  local d
  for d in "${dirs[@]}"; do
    mkdir -p "$SITE_DIR/$d"
  done

  if [[ "$DRY_RUN" == "1" ]]; then
    return 0
  fi

  # 完整清理 ThinkPHP 缓存（含 fields/schema 缓存，避免 /domain/data /account/data 500）
  rm -rf "$SITE_DIR/runtime/cache/"* "$SITE_DIR/runtime/temp/"* 2>/dev/null || true
  find "$SITE_DIR/runtime/cache" "$SITE_DIR/runtime/temp" -mindepth 1 -delete 2>/dev/null || true

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
      for svc in php-fpm php8.2-fpm php8.1-fpm php8.0-fpm php-fpm74; do
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
    public/static/css/bootstrap-table.css
    route/app.php
    app/controller/Domain.php
  )
  local missing=0 f
  for f in "${assets[@]}"; do
    if [[ ! -f "$SITE_DIR/$f" ]]; then
      warn "缺少关键文件: $f（列表页可能空白）"
      missing=1
    fi
  done
  [[ "$missing" -eq 0 ]] && ok "关键程序/静态文件检查通过"

  if [[ "$DRY_RUN" == "1" ]]; then
    return 0
  fi

  local acct_cnt domain_cnt
  acct_cnt=$(mysql_scalar "SELECT COUNT(*) FROM \`${DB_PREFIX}account\`" || echo 0)
  domain_cnt=$(mysql_scalar "SELECT COUNT(*) FROM \`${DB_PREFIX}domain\`" || echo 0)
  log "数据库统计: ${acct_cnt:-0} 个域名账户, ${domain_cnt:-0} 个域名"

  if [[ "${acct_cnt:-0}" -eq 0 && "${domain_cnt:-0}" -eq 0 ]]; then
    warn "账户/域名数量均为 0；若升级前本有数据，请检查 .env 中 DATABASE/PREFIX 是否正确"
  fi

  if ! column_exists "domain" "cid"; then
    warn "domain.cid 仍缺失，域名列表 API 可能报错"
  fi
  if ! table_exists "domain_category"; then
    warn "domain_category 仍缺失，域名分类筛选可能报错"
  fi
}

run_composer() {
  [[ "$SKIP_COMPOSER" == "1" ]] && return 0
  if [[ ! -f "$SITE_DIR/composer.json" ]]; then
    return 0
  fi
  if [[ -f "$SITE_DIR/vendor/autoload.php" && "${DNSMGR_FORCE_COMPOSER:-0}" != "1" ]]; then
    ok "vendor 已存在，跳过 composer（强制更新请设 DNSMGR_FORCE_COMPOSER=1）"
    return 0
  fi
  if ! command -v composer >/dev/null 2>&1; then
    warn "未安装 composer，跳过依赖更新（若 vendor 已存在通常无影响）"
    return 0
  fi
  if [[ "$DRY_RUN" == "1" ]]; then
    log "[DRY-RUN] composer install --no-dev"
    return 0
  fi
  log "执行 composer install --no-dev ..."
  if (cd "$SITE_DIR" && composer install --no-dev --no-interaction --prefer-dist 2>&1); then
    ok "composer 依赖更新完成"
  else
    warn "composer 失败（常见：PHP 版本低于 8.2 或缺少 ssh2 扩展）。站点若原本正常可忽略"
  fi
}

main() {
  log "========== dnsmgr 升级开始 =========="
  log "站点目录: $SITE_DIR"
  log "Git 仓库: $REPO_URL ($BRANCH)"

  require_cmd git
  require_cmd rsync
  require_cmd mysql
  require_cmd sed

  [[ -d "$SITE_DIR" ]] || die "站点目录不存在: $SITE_DIR"
  [[ -f "$SITE_DIR/.env" ]] || die "未找到 $SITE_DIR/.env，请先安装 dnsmgr"

  DB_HOST=$(read_env_value HOSTNAME || echo 127.0.0.1)
  DB_NAME=$(read_env_value DATABASE || die "无法读取 DATABASE")
  DB_USER=$(read_env_value USERNAME || die "无法读取 USERNAME")
  DB_PASS=$(read_env_value PASSWORD || echo "")
  DB_PORT=$(read_env_value HOSTPORT || echo 3306)
  DB_PREFIX=$(read_env_value PREFIX || echo dnsmgr_)

  log "数据库: ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME} 前缀=${DB_PREFIX}"

  if [[ "$DRY_RUN" != "1" ]]; then
    BACKUP_ENV=$(mktemp)
    cp "$SITE_DIR/.env" "$BACKUP_ENV"
    ok "已备份 .env -> $BACKUP_ENV"
  fi

  TMP_DIR=$(mktemp -d)
  log "克隆/更新仓库到临时目录 ..."
  git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$TMP_DIR/src"
  ok "仓库拉取完成: $(git -C "$TMP_DIR/src" rev-parse --short HEAD 2>/dev/null || echo unknown)"

  log "同步程序文件 ..."
  sync_files "$TMP_DIR/src"
  ok "文件同步完成"

  if [[ -n "$BACKUP_ENV" && -f "$BACKUP_ENV" ]]; then
    cp "$BACKUP_ENV" "$SITE_DIR/.env"
    ok "已恢复 .env"
  fi

  prepare_runtime
  apply_migrations
  ensure_core_schema
  run_composer
  fix_public_permissions
  invalidate_opcache
  verify_upgrade

  log "========== dnsmgr 升级完成 =========="
  log "若域名/账户列表仍空白: 浏览器 Ctrl+F5 强刷；F12 看 /domain/data 与 /account/data 是否 200"
  log "若仍 500: 查看 runtime/log/ 下最新日志；确认 runtime 归属 $WEB_USER 且可写"
  log "建议: 登录面板 -> 清理缓存；检查 计划任务 / 容灾切换 / AWS IP同步 是否正常"
  if [[ -n "$BACKUP_ENV" && -f "$BACKUP_ENV" ]]; then
    log ".env 备份仍保留在: $BACKUP_ENV （确认无误后可手动删除）"
  fi
}

main "$@"
