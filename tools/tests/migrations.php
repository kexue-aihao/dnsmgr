<?php

declare(strict_types=1);

// php tools/tests/migrations.php
// MySQL 集成测试只使用随机前缀的临时表：
// DNSMGR_TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=dnsmgr_test;charset=utf8mb4'
// DNSMGR_TEST_DB_USER=root DNSMGR_TEST_DB_PASS=... php tools/tests/migrations.php
require dirname(__DIR__) . '/migrate.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$sql = <<<'SQL'
-- comment with a semicolon ;
INSERT INTO test VALUES ('one;two', 'it''s,quoted', 'escaped\'value');
/* another ; comment */ ALTER TABLE test ADD COLUMN flag enum('a,b','c') DEFAULT 'a,b', ADD KEY flag (flag);
# trailing comment ;
SELECT '/* string */', '-- string', '# string';
SQL;
$statements = DnsmgrMigrationRunner::splitSql($sql);
check(count($statements) === 3, 'SQL 字符串与注释中的分号必须保留');
$clauses = DnsmgrMigrationRunner::splitSql(substr($statements[1], strlen('ALTER TABLE test ')), ',');
check(count($clauses) === 2, 'ENUM、函数参数与字符串里的逗号不能拆成 ALTER 子句');
check(str_contains($statements[0], "'it''s,quoted'"), 'SQL 转义引号应原样保留');
foreach (["SELECT 'unterminated", 'SELECT (1', 'SELECT 1 /* comment'] as $invalidSql) {
    $failed = false;
    try {
        DnsmgrMigrationRunner::splitSql($invalidSql);
    } catch (RuntimeException $e) {
        $failed = true;
    }
    check($failed, '不完整的 SQL 必须失败，不能丢弃剩余内容');
}
fwrite(STDOUT, "PASS: SQL 字符串、注释、复合 ALTER 切分与无效输入\n");

$dsn = getenv('DNSMGR_TEST_DB_DSN');
if (!$dsn) {
    fwrite(STDOUT, "SKIP: 未设置 DNSMGR_TEST_DB_DSN，未执行 MySQL 集成测试\n");
    exit;
}
$db = new PDO($dsn, getenv('DNSMGR_TEST_DB_USER') ?: 'root', getenv('DNSMGR_TEST_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root = dirname(__DIR__, 2);
$prefix = 'dnsmgr_test_' . bin2hex(random_bytes(6)) . '_';
$freshPrefix = $prefix . 'fresh_';
$fixture = tempnam(sys_get_temp_dir(), 'dnsmgr-sql-test-');
check($fixture !== false, '无法创建测试 SQL 文件');

try {
    // 模拟中断过的旧站：config 和 is_notice 已存在，但账户尚未重命名、其余表尚未创建。
    $db->exec("CREATE TABLE `{$prefix}account` (
        id int unsigned PRIMARY KEY AUTO_INCREMENT, type varchar(20) NOT NULL,
        ak varchar(255) NOT NULL, sk varchar(255), ext varchar(255), config text,
        proxy tinyint NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE `{$prefix}domain` (
        id int unsigned PRIMARY KEY, name varchar(255), is_notice tinyint NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE `{$prefix}user` (
        id int unsigned PRIMARY KEY, totp_open tinyint NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT INTO `{$prefix}domain` (id, name) VALUES (1, 'preserved.example.invalid')");
    $insert = $db->prepare("INSERT INTO `{$prefix}account` (type, ak, sk, ext, config) VALUES (?, ?, ?, ?, ?)");
    $insert->execute(['aliyun', 'legacy-test-key', 'legacy-test-secret', '', null]);
    $preserved = '{"AccessKeyId":"already-saved","AccessKeySecret":"keep-this-test-value","proxy":0}';
    $insert->execute(['aliyun', 'display-name', 'unused-old-value', '', $preserved]);
    $insert->execute(['cloudflare', 'user@example.invalid', 'cloudflare-test-token', '1', '']);
    $insert->execute(['custom-provider', 'unknown-type', 'preserve-old-value', '', null]);

    $runner = new DnsmgrMigrationRunner($db, $prefix);
    $runner->executeFile($root . '/app/sql/update.sql');
    require_once $root . '/app/lib/DnsHelper.php';
    check($runner->migrateLegacyAccounts(\app\lib\DnsHelper::$dns_config) === 2, '必须转换每个空 config 的已知旧账户');
    check($runner->hasColumn('account', 'name') && !$runner->hasColumn('account', 'ak'), '旧账户字段应能在 config 已存在时完成重命名');
    foreach (['regtime', 'expiretime', 'checktime', 'noticetime', 'checkstatus', 'cid'] as $column) {
        check($runner->hasColumn('domain', $column), "复合 ALTER 未完成 domain.$column");
    }
    check($runner->hasColumn('user', 'totp_secret'), '重复的 totp_open 不能阻止创建 totp_secret');
    check($runner->hasColumn('dmtask', 'recordid') && $runner->hasColumn('sctask', 'recordid'), '必须先创建上游任务表，再运行本地扩展迁移');
    $accounts = $db->query("SELECT * FROM `{$prefix}account` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $legacy = json_decode($accounts[0]['config'], true, 512, JSON_THROW_ON_ERROR);
    check($legacy['AccessKeyId'] === 'legacy-test-key' && $legacy['AccessKeySecret'] === 'legacy-test-secret' && (int) $legacy['proxy'] === 1, '旧认证信息与代理设置必须保留');
    check($accounts[1]['config'] === $preserved, '已保存的 JSON 配置不能被旧认证列覆盖');
    $cloudflare = json_decode($accounts[2]['config'], true, 512, JSON_THROW_ON_ERROR);
    check($cloudflare['email'] === 'user@example.invalid' && $cloudflare['apikey'] === 'cloudflare-test-token' && (string) $cloudflare['auth'] === '1', 'Cloudflare 的第三个旧认证字段应迁移为 auth');
    check($accounts[3]['config'] === null && $accounts[3]['sk'] === 'preserve-old-value', '未知提供商的旧认证列必须原样保留');
    check($db->query("SELECT name FROM `{$prefix}domain` WHERE id = 1")->fetchColumn() === 'preserved.example.invalid', '升级不能替换已有域名数据');
    $runner->executeFile($root . '/app/sql/update.sql');
    check($runner->migrateLegacyAccounts(\app\lib\DnsHelper::$dns_config) === 0, '重复升级不能重新转换账户');
    check($db->query("SELECT * FROM `{$prefix}account` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $accounts, '重复升级必须保留账户内容');
    fwrite(STDOUT, "PASS: 中断升级重试、复合 ALTER、旧账户认证和现有配置保留\n");

    // 新安装没有 sk/ext；不能把用户的账户名称当作旧 API 凭据。
    $freshRunner = new DnsmgrMigrationRunner($db, $freshPrefix);
    $freshRunner->executeFile($root . '/app/sql/install.sql');
    $db->exec("INSERT INTO `{$freshPrefix}account` (type, name, config) VALUES ('aliyun', 'new-display-name', NULL)");
    $freshRunner->executeFile($root . '/app/sql/update.sql');
    check($freshRunner->migrateLegacyAccounts(\app\lib\DnsHelper::$dns_config) === 0, '新结构账户不应进行旧认证转换');
    check($db->query("SELECT config FROM `{$freshPrefix}account`")->fetchColumn() === null, '不能用新账户的显示名称生成认证信息');
    fwrite(STDOUT, "PASS: 全新安装后重复升级不会误转换账户\n");

    // Base64URL ID 仅大小写不同也代表不同记录；旧表的默认排序规则不能误匹配。
    require_once $root . '/vendor/autoload.php';
    \think\facade\Db::setConfig([
        'default' => 'compat_test',
        'connections' => ['compat_test' => [
            'type' => 'mysql', 'dsn' => $dsn,
            'database' => $db->query('SELECT DATABASE()')->fetchColumn(),
            'username' => getenv('DNSMGR_TEST_DB_USER') ?: 'root',
            'password' => getenv('DNSMGR_TEST_DB_PASS') ?: '',
            'prefix' => $freshPrefix, 'charset' => 'utf8mb4', 'fields_cache' => false,
        ]],
    ]);
    $encodeId = static fn(string $raw): string => rtrim(strtr(base64_encode("www\x1eTXT\x1e" . $raw), '+/', '-_'), '=');
    $firstId = $encodeId('"aaA"');
    $secondId = $encodeId('"aa["');
    $newId = $encodeId('"updated"');
    check($firstId !== $secondId && strcasecmp($firstId, $secondId) === 0, '大小写碰撞测试数据无效');
    foreach (['dmtask', 'sctask', 'aws_sync'] as $table) {
        $db->exec("ALTER TABLE `{$freshPrefix}{$table}` MODIFY recordid text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL");
        $columns = $table === 'dmtask' ? ', frequency' : '';
        $values = $table === 'dmtask' ? ', 1' : '';
        $insertTask = $db->prepare("INSERT INTO `{$freshPrefix}{$table}` (id, did, rr, recordid$columns) VALUES (?, ?, 'www', ?$values)");
        $insertTask->execute([1, 1, $firstId]);
        $insertTask->execute([2, 1, $secondId]);
        $insertTask->execute([3, 2, $firstId]);
    }
    \app\service\TaskRecordService::syncRecordId(['id' => 1, 'type' => 'aws'], $firstId, $newId);
    foreach (['dmtask', 'sctask', 'aws_sync'] as $table) {
        $ids = $db->query("SELECT recordid FROM `{$freshPrefix}{$table}` ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        check($ids === [$newId, $secondId, $firstId], '大小写不同或其他域名的任务被误更新: ' . $table);
    }
    $freshRunner->executeFile($root . '/app/sql/migrations/202609190002_task_record_id_collation.sql');
    $freshRunner->executeFile($root . '/app/sql/migrations/202609190002_task_record_id_collation.sql');
    foreach (['dmtask', 'sctask', 'aws_sync'] as $table) {
        $query = $db->prepare("SELECT COUNT(*) FROM `{$freshPrefix}{$table}` WHERE recordid = ?");
        $query->execute([$secondId]);
        check((int) $query->fetchColumn() === 1, '数据库记录 ID 比较仍忽略大小写: ' . $table);
    }
    \think\facade\Db::connect()->close();
    fwrite(STDOUT, "PASS: MySQL 默认排序规则下精确匹配任务，大小写迁移可重复执行\n");

    file_put_contents($fixture, "ALTER TABLE dnsmgr_domain ADD COLUMN test_before_failure int;\nALTER TABLE dnsmgr_missing_table ADD COLUMN broken int;\nALTER TABLE dnsmgr_domain ADD COLUMN test_after_failure int;");
    $failed = false;
    try {
        $runner->executeFile($fixture);
    } catch (PDOException $e) {
        $failed = true;
    }
    check($failed && $runner->hasColumn('domain', 'test_before_failure') && !$runner->hasColumn('domain', 'test_after_failure'), '真实 SQL 错误必须立即中断后续语句');
    fwrite(STDOUT, "PASS: 真实 SQL 错误中断迁移\n");
} finally {
    if (is_file($fixture)) unlink($fixture);
    // 只删除此进程随机前缀的测试表，不读取或修改任何站点配置。
    $tables = $db->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND LEFT(table_name, ?) = ?');
    $tables->execute([strlen($prefix), $prefix]);
    foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
        check(str_starts_with($table, $prefix) && preg_match('/^[a-zA-Z0-9_]+$/D', $table) === 1, '测试表清理范围异常');
        $db->exec('DROP TABLE `' . $table . '`');
    }
}
