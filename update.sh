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
#   DNSMGR_WEB_USER=www             文件所有者
#   DNSMGR_DRY_RUN=1                只预览不写入
#   DNSMGR_SKIP_COMPOSER=1          跳过 composer install
#

set -euo pipefail

REPO_URL="${DNSMGR_REPO:-https://github.com/kexue-aihao/dnsmgr.git}"
BRANCH="${DNSMGR_BRANCH:-master}"
SITE_DIR="${DNSMGR_SITE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
WEB_USER="${DNSMGR_WEB_USER:-www}"
DRY_RUN="${DNSMGR_DRY_RUN:-0}"
SKIP_COMPOSER="${DNSMGR_SKIP_COMPOSER:-0}"
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

sync_files() {
  local src=$1
  local excludes=(
    --exclude=.env
    --exclude=.git
    --exclude=.gitignore
    --exclude=vendor/
    --exclude=runtime/cache/
    --exclude=runtime/log/
    --exclude=runtime/session/
    --exclude=runtime/temp/
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
  for d in "${dirs[@]}"; do
    mkdir -p "$SITE_DIR/$d"
  done

  if [[ "$DRY_RUN" == "1" ]]; then
    return 0
  fi

  rm -rf "$SITE_DIR/runtime/cache/"*
  find "$SITE_DIR/runtime/cache" -type f -name "*.php" -delete 2>/dev/null || true

  if id "$WEB_USER" >/dev/null 2>&1; then
    chown -R "$WEB_USER:$WEB_USER" "$SITE_DIR/runtime" 2>/dev/null || warn "chown runtime 失败，请手动检查权限"
    chmod -R 775 "$SITE_DIR/runtime" 2>/dev/null || true
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
  if [[ "$DRY_RUN" == "1" ]]; then
    git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$TMP_DIR/src"
  else
    git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$TMP_DIR/src"
  fi
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
  run_composer

  log "========== dnsmgr 升级完成 =========="
  log "建议: 登录面板 -> 清理缓存；检查 计划任务 / 容灾切换 / AWS IP同步 是否正常"
  if [[ -n "$BACKUP_ENV" && -f "$BACKUP_ENV" ]]; then
    log ".env 备份仍保留在: $BACKUP_ENV （确认无误后可手动删除）"
  fi
}

main "$@"
