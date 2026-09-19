#!/usr/bin/env bash
set -euo pipefail

# 无网络、无真实站点写入的升级编排回归测试。
# DNSMGR_TEST_PHP=/path/to/php bash tools/tests/upgrade.sh
PROJECT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

if [[ "${1:-}" == "--case" ]]; then
  source "$PROJECT_DIR/update.sh"
  SITE_DIR=$2
  case "$3" in
    migration_failure)
      ensure_migration_table() { :; }
      migration_applied() { return 1; }
      mysql_exec_file() { return 23; }
      mark_migration() { touch "$SITE_DIR/marked"; }
      apply_migrations
      ;;
    missing_source)
      composer() { touch "$SITE_DIR/composer-called"; }
      preflight_source "$SITE_DIR/source"
      ;;
    changed_dependencies)
      SKIP_COMPOSER=1
      git() { return 1; }
      php() { return 0; }
      preflight_source "$PROJECT_DIR"
      ;;
    dry_run)
      DRY_RUN=1
      rsync() { printf '%s\n' "$@" > "$SITE_DIR/rsync-args"; }
      prepare_runtime
      sync_files "$PROJECT_DIR"
      ;;
    env)
      php() { "${DNSMGR_TEST_PHP:-php}" "$@"; }
      [[ "$(read_env_value HOSTNAME)" == "127.0.0.1" ]]
      [[ "$(read_env_value PASSWORD)" == "test;#=quoted value" ]]
      [[ "$(read_env_value PREFIX)" == "test_prefix_" ]]
      [[ "$(read_source_dbversion "$PROJECT_DIR/config/app.php")" =~ ^[0-9]+$ ]]
      [[ "$(read_source_dbversion "$SITE_DIR/version.php")" == 1049 ]]
      if read_source_dbversion "$SITE_DIR/invalid-version.php"; then exit 26; fi
      ;;
    version_marker)
      fail_stage=${4:-none}
      apply_core_schema() { echo core >> "$SITE_DIR/steps"; [[ "$fail_stage" != core ]]; }
      apply_migrations() { echo migrations >> "$SITE_DIR/steps"; [[ "$fail_stage" != migrations ]]; }
      ensure_core_schema() { echo schema >> "$SITE_DIR/steps"; [[ "$fail_stage" != schema ]]; }
      verify_upgrade() { echo verify >> "$SITE_DIR/steps"; [[ "$fail_stage" != verify ]]; }
      mark_core_version() { echo version >> "$SITE_DIR/steps"; touch "$SITE_DIR/marked"; }
      upgrade_database
      ;;
    frequency)
      DB_PREFIX=test_
      mysql_scalar() {
        case "$1" in
          *column_default*) cat "$SITE_DIR/default" ;;
          *) if [[ -f "$SITE_DIR/applied" ]]; then echo 1; else echo 0; fi ;;
        esac
      }
      mysql_exec() {
        case "$1" in
          *'START TRANSACTION;'*)
            value=$(cat "$SITE_DIR/frequency")
            echo "$((value * 60))" > "$SITE_DIR/frequency"
            touch "$SITE_DIR/applied"
            ;;
          *'ALTER TABLE'*) echo 10 > "$SITE_DIR/default" ;;
          *'INSERT IGNORE'*) touch "$SITE_DIR/applied" ;;
          *) exit 24 ;;
        esac
      }
      migrate_aws_frequency_seconds 202506050001_aws_sync_frequency_seconds.sql
      migrate_aws_frequency_seconds 202506050001_aws_sync_frequency_seconds.sql
      ;;
  esac
  exit
fi

TEST_BASE=$(cd "${TMPDIR:-/tmp}" && pwd)
TEST_DIR=$(mktemp -d "$TEST_BASE/dnsmgr-upgrade-test.XXXXXX")
cleanup_tests() {
  [[ -d "$TEST_DIR" ]] || return 0
  local resolved
  resolved=$(cd "$TEST_DIR" && pwd)
  [[ "$resolved" == "$TEST_BASE"/dnsmgr-upgrade-test.* ]] || return 1
  rm -rf -- "$resolved"
}
trap cleanup_tests EXIT

mkdir -p "$TEST_DIR/fail/app/sql/migrations" "$TEST_DIR/missing/source" "$TEST_DIR/dependencies/vendor" "$TEST_DIR/dry"
touch "$TEST_DIR/fail/app/sql/migrations/202601010001_failure.sql"
if bash "$0" --case "$TEST_DIR/fail" migration_failure > "$TEST_DIR/failure.log" 2>&1; then
  echo 'FAIL: SQL 错误未中断升级' >&2; exit 1
fi
[[ ! -f "$TEST_DIR/fail/marked" ]]
echo 'PASS: SQL 失败时不会登记 migration'

for stage in core migrations schema verify; do
  mkdir -p "$TEST_DIR/version-$stage"
  if bash "$0" --case "$TEST_DIR/version-$stage" version_marker "$stage" > "$TEST_DIR/version-$stage.log" 2>&1; then
    echo "FAIL: $stage 失败后仍继续升级" >&2; exit 1
  fi
  [[ ! -f "$TEST_DIR/version-$stage/marked" ]]
done
mkdir -p "$TEST_DIR/version-success"
bash "$0" --case "$TEST_DIR/version-success" version_marker
[[ -f "$TEST_DIR/version-success/marked" ]]
[[ "$(cat "$TEST_DIR/version-success/steps")" == $'core\nmigrations\nschema\nverify\nversion' ]]
echo 'PASS: 仅全部迁移和关键结构验证成功后写数据库版本'

if bash "$0" --case "$TEST_DIR/missing" missing_source > "$TEST_DIR/missing.log" 2>&1; then
  echo 'FAIL: 不完整更新源未被拒绝' >&2; exit 1
fi
[[ ! -f "$TEST_DIR/missing/composer-called" ]]
echo 'PASS: 不完整或未合并的更新源在安装依赖前被拒绝'

cp "$PROJECT_DIR/composer.json" "$TEST_DIR/dependencies/composer.json"
printf '{}\n' > "$TEST_DIR/dependencies/composer.lock"
touch "$TEST_DIR/dependencies/vendor/autoload.php"
if bash "$0" --case "$TEST_DIR/dependencies" changed_dependencies > "$TEST_DIR/dependencies.log" 2>&1; then
  echo 'FAIL: 依赖变化时仍允许跳过 Composer' >&2; exit 1
fi
grep -q 'DNSMGR_SKIP_COMPOSER' "$TEST_DIR/dependencies.log"
echo 'PASS: 依赖变化时拒绝 DNSMGR_SKIP_COMPOSER'

bash "$0" --case "$TEST_DIR/dry" dry_run > "$TEST_DIR/dry.log" 2>&1
[[ ! -d "$TEST_DIR/dry/runtime" ]]
for option in --dry-run --exclude=.env --exclude=runtime/ --exclude=/public/.user.ini --exclude=/public/.well-known/; do
  grep -Fxq -- "$option" "$TEST_DIR/dry/rsync-args"
done
echo 'PASS: 预览不创建 runtime，环境配置和站点部署文件被排除'

for mode in legacy seconds interrupted unknown; do
  mkdir -p "$TEST_DIR/$mode"
done
echo 3 > "$TEST_DIR/legacy/default"
echo 3 > "$TEST_DIR/legacy/frequency"
echo 10 > "$TEST_DIR/seconds/default"
echo 10 > "$TEST_DIR/seconds/frequency"
echo 3 > "$TEST_DIR/interrupted/default"
echo 180 > "$TEST_DIR/interrupted/frequency"
touch "$TEST_DIR/interrupted/applied"
for mode in legacy seconds interrupted; do
  bash "$0" --case "$TEST_DIR/$mode" frequency > "$TEST_DIR/$mode.log" 2>&1
  [[ "$(cat "$TEST_DIR/$mode/default")" == 10 && -f "$TEST_DIR/$mode/applied" ]]
done
[[ "$(cat "$TEST_DIR/legacy/frequency")" == 180 ]]
[[ "$(cat "$TEST_DIR/seconds/frequency")" == 10 ]]
[[ "$(cat "$TEST_DIR/interrupted/frequency")" == 180 ]]
echo 7 > "$TEST_DIR/unknown/default"
echo 7 > "$TEST_DIR/unknown/frequency"
if bash "$0" --case "$TEST_DIR/unknown" frequency > "$TEST_DIR/unknown.log" 2>&1; then
  echo 'FAIL: 无法识别的 AWS 单位仍继续迁移' >&2; exit 1
fi
[[ ! -f "$TEST_DIR/unknown/applied" ]]
echo 'PASS: AWS 分钟转换、已有秒级设置和中断重试均不会重复换算'

mkdir -p "$TEST_DIR/env"
cat > "$TEST_DIR/env/.env" <<'ENV'
[Database]
hostname = 127.0.0.1
password = "test;#=quoted value"
prefix = test_prefix_
ENV
cat > "$TEST_DIR/env/version.php" <<'PHP'
<?php
// 静态读取不应执行 app()/env()，也不能取到注释中的 'dbversion' => '0'。
return ['timezone' => env('APP.TIMEZONE'), 'path' => app()->getRootPath(), 'dbversion' => '1049'];
PHP
cat > "$TEST_DIR/env/invalid-version.php" <<'PHP'
<?php return ['dbversion' => env('DB.VERSION')];
PHP
bash "$0" --case "$TEST_DIR/env" env
echo 'PASS: 环境配置支持大小写和特殊字符，数据库版本静态读取不执行框架代码'
