#!/usr/bin/env bash
set -euo pipefail

# 完整升级的 MySQL/MariaDB 集成测试；连接参数只使用 DNSMGR_TEST_DB_*，不读取站点 .env。
# 将 php 和 mysql 加入 PATH 后运行：
# DNSMGR_TEST_DB_HOST=127.0.0.1 DNSMGR_TEST_DB_PORT=3306 DNSMGR_TEST_DB_NAME=dnsmgr_test \
#   DNSMGR_TEST_DB_USER=root DNSMGR_TEST_DB_PASS=... bash tools/tests/upgrade_database.sh
# 只创建/清理随机前缀的临时表，不运行同步文件、Composer、缓存清理或进程重启。
PROJECT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
source "$PROJECT_DIR/update.sh"
SITE_DIR=$PROJECT_DIR
DRY_RUN=0
DB_HOST=${DNSMGR_TEST_DB_HOST:-127.0.0.1}
DB_PORT=${DNSMGR_TEST_DB_PORT:-3306}
DB_NAME=${DNSMGR_TEST_DB_NAME:?必须指定隔离测试数据库 DNSMGR_TEST_DB_NAME}
DB_USER=${DNSMGR_TEST_DB_USER:-root}
DB_PASS=${DNSMGR_TEST_DB_PASS:-}
[[ "$DB_HOST" == 127.0.0.1 || "$DB_HOST" == localhost || "$DB_HOST" == ::1 ]] || die "此测试只允许连接本机隔离数据库"
[[ "$DB_PORT" =~ ^[0-9]+$ && "$DB_NAME" =~ ^[a-zA-Z0-9_]+$ ]] || die "测试数据库参数格式错误"
require_cmd php
require_cmd mysql
TARGET_DB_VERSION=$(read_source_dbversion "$PROJECT_DIR/config/app.php")

if [[ "${1:-}" == --upgrade || "${1:-}" == --frequency ]]; then
  [[ "${DNSMGR_TEST_PREFIX_BASE:-}" =~ ^dnsmgr_upgrade_test_[0-9a-f]{16}_$ ]] || die "测试表前缀未初始化"
  DB_PREFIX=${2:?缺少测试表前缀}
  [[ "$DB_PREFIX" == "${DNSMGR_TEST_PREFIX_BASE}legacy_" || "$DB_PREFIX" == "${DNSMGR_TEST_PREFIX_BASE}fresh_" ]] || die "测试表前缀越界"
  if [[ "$1" == --upgrade ]]; then
    upgrade_database
  else
    migrate_aws_frequency_seconds 202506050001_aws_sync_frequency_seconds.sql
  fi
  exit
fi

export DNSMGR_TEST_PREFIX_BASE="dnsmgr_upgrade_test_$(php -r 'echo bin2hex(random_bytes(8));')_"
[[ "$DNSMGR_TEST_PREFIX_BASE" =~ ^dnsmgr_upgrade_test_[0-9a-f]{16}_$ ]] || die "无法生成测试表前缀"
TEST_BASE=$(cd "${TMPDIR:-/tmp}" && pwd)
TEST_LOG_DIR=$(mktemp -d "$TEST_BASE/dnsmgr-db-upgrade.XXXXXX")
cleanup_database_tests() {
  local test_status=$? tables table resolved
  tables=$(MYSQL_PWD="$DB_PASS" mysql -N -B -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" \
    -e "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND LEFT(table_name, ${#DNSMGR_TEST_PREFIX_BASE})='${DNSMGR_TEST_PREFIX_BASE}'") || exit 1
  while IFS= read -r table; do
    table=${table%$'\r'}
    [[ -n "$table" ]] || continue
    [[ "$table" == "$DNSMGR_TEST_PREFIX_BASE"* && "$table" =~ ^[a-zA-Z0-9_]+$ ]] || exit 1
    mysql_exec "DROP TABLE \`$table\`;" || test_status=1
  done <<< "$tables"
  resolved=$(cd "$TEST_LOG_DIR" && pwd)
  [[ "$resolved" == "$TEST_BASE"/dnsmgr-db-upgrade.* ]] || exit 1
  rm -rf -- "$resolved"
  exit "$test_status"
}
trap cleanup_database_tests EXIT

run_upgrade() {
  local name=$1
  # 必须使用独立 Bash 进程；把函数直接放在 if 中会关闭其内部的 errexit。
  if ! bash "$0" --upgrade "$DB_PREFIX" > "$TEST_LOG_DIR/$name.log" 2>&1; then
    cat "$TEST_LOG_DIR/$name.log" >&2
    return 1
  fi
}

assert_scalar() {
  local expected=$1 sql=$2 message=$3 actual
  actual=$(mysql_scalar "$sql")
  [[ "$actual" == "$expected" ]] || die "$message（预期 $expected，实际 $actual）"
}

DB_PREFIX="${DNSMGR_TEST_PREFIX_BASE}legacy_"
mysql_exec_file "$PROJECT_DIR/app/sql/install.sql" > "$TEST_LOG_DIR/install-legacy.log"
mysql_exec "INSERT INTO \`${DB_PREFIX}account\` (id,type,name,config) VALUES (1,'aliyun','fixture-account','{\"test\":\"keep\"}');
  INSERT INTO \`${DB_PREFIX}domain\` (id,aid,name) VALUES (1,1,'upgrade.example.invalid');
  UPDATE \`${DB_PREFIX}config\` SET \`value\`='1048' WHERE \`key\`='version';
  ALTER TABLE \`${DB_PREFIX}aws_sync\` MODIFY COLUMN frequency int NOT NULL DEFAULT 3;
  INSERT INTO \`${DB_PREFIX}dmtask\` (did,rr,recordid,frequency) VALUES (1,'www','initial-record',1);
  INSERT INTO \`${DB_PREFIX}sctask\` (did,rr,recordid) VALUES (1,'www','initial-record');
  INSERT INTO \`${DB_PREFIX}aws_sync\` (did,rr,recordid,frequency) VALUES (1,'www','initial-record',3);"
for table in dmtask sctask aws_sync; do
  mysql_exec "ALTER TABLE \`${DB_PREFIX}${table}\` MODIFY COLUMN recordid varchar(60) NOT NULL;"
done
ensure_migration_table
mysql_exec "CREATE TRIGGER \`${DB_PREFIX}blocked_marker\` BEFORE INSERT ON \`${DB_PREFIX}migration\`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced migration marker failure';"
if bash "$0" --frequency "$DB_PREFIX" > "$TEST_LOG_DIR/transaction-failure.log" 2>&1; then
  die "迁移记录写入失败时仍返回成功"
fi
assert_scalar 3 "SELECT frequency FROM \`${DB_PREFIX}aws_sync\` WHERE id=1" "AWS 更新与登记失败未一并回滚"
assert_scalar 0 "SELECT COUNT(*) FROM \`${DB_PREFIX}migration\`" "失败事务留下了迁移记录"
assert_scalar 1048 "SELECT \`value\` FROM \`${DB_PREFIX}config\` WHERE \`key\`='version'" "失败事务不应修改版本"
mysql_exec "DROP TRIGGER \`${DB_PREFIX}blocked_marker\`;"
echo 'PASS: AWS 单位转换与迁移登记在真实事务中一并回滚'

run_upgrade legacy-first
assert_scalar 180 "SELECT frequency FROM \`${DB_PREFIX}aws_sync\` WHERE id=1" "分钟间隔未转换为秒"
assert_scalar 10 "SELECT column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${DB_PREFIX}aws_sync' AND column_name='frequency'" "AWS 默认单位未切换"
assert_scalar "$TARGET_DB_VERSION" "SELECT \`value\` FROM \`${DB_PREFIX}config\` WHERE \`key\`='version'" "完整升级没有写入目标数据库版本"
migrations=("$PROJECT_DIR/app/sql/migrations/"*.sql)
assert_scalar "${#migrations[@]}" "SELECT COUNT(*) FROM \`${DB_PREFIX}migration\`" "并非所有迁移都已登记"
for table in dmtask sctask aws_sync; do
  mysql_exec "UPDATE \`${DB_PREFIX}${table}\` SET recordid=REPEAT('record-id-',80) WHERE id=1;"
  assert_scalar 800 "SELECT CHAR_LENGTH(recordid) FROM \`${DB_PREFIX}${table}\` WHERE id=1" "长记录 ID 被截断"
done
run_upgrade legacy-repeat
assert_scalar 180 "SELECT frequency FROM \`${DB_PREFIX}aws_sync\` WHERE id=1" "重复升级再次转换了 AWS 间隔"
assert_scalar "${#migrations[@]}" "SELECT COUNT(*) FROM \`${DB_PREFIX}migration\`" "重复升级产生额外迁移记录"
for table in dmtask sctask aws_sync; do
  assert_scalar 1 "SELECT recordid=REPEAT('record-id-',80) FROM \`${DB_PREFIX}${table}\` WHERE id=1" "重复升级损坏了长记录 ID"
done
assert_scalar '{"test":"keep"}' "SELECT config FROM \`${DB_PREFIX}account\` WHERE id=1" "重复升级覆盖了已有账户配置"
assert_scalar upgrade.example.invalid "SELECT name FROM \`${DB_PREFIX}domain\` WHERE id=1" "重复升级修改了已有域名"
echo 'PASS: 完整升级与重复执行保留数据，三个任务表均可保存 800 字符记录 ID'

mysql_exec "ALTER TABLE \`${DB_PREFIX}aws_sync\` MODIFY COLUMN frequency int NOT NULL DEFAULT 3;"
run_upgrade legacy-interrupted
assert_scalar 180 "SELECT frequency FROM \`${DB_PREFIX}aws_sync\` WHERE id=1" "已登记但未修改默认值的迁移发生重复转换"
assert_scalar 10 "SELECT column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${DB_PREFIX}aws_sync' AND column_name='frequency'" "中断恢复没有修复默认值"
echo 'PASS: 已提交单位转换但未修改默认值的中断状态可安全恢复'

DB_PREFIX="${DNSMGR_TEST_PREFIX_BASE}fresh_"
mysql_exec_file "$PROJECT_DIR/app/sql/install.sql" > "$TEST_LOG_DIR/install-fresh.log"
mysql_exec "INSERT INTO \`${DB_PREFIX}aws_sync\` (did,rr,recordid,frequency) VALUES (1,'www','fresh-record',10);"
run_upgrade fresh-first
run_upgrade fresh-repeat
assert_scalar 10 "SELECT frequency FROM \`${DB_PREFIX}aws_sync\` WHERE id=1" "新安装的秒级 AWS 间隔被当作分钟"
assert_scalar "$TARGET_DB_VERSION" "SELECT \`value\` FROM \`${DB_PREFIX}config\` WHERE \`key\`='version'" "新安装重复升级的版本错误"
assert_scalar "${#migrations[@]}" "SELECT COUNT(*) FROM \`${DB_PREFIX}migration\`" "新安装未完成全部迁移"
echo 'PASS: 新安装的 10 秒设置与数据库版本在完整重复升级后保持正确'
