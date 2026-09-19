<?php

/** Run: php -d extension=pdo_sqlite tools/tests/task_services_test.php */
namespace app\lib {
    class DnsHelper
    {
        public static $dns;
        public static $line_name = ['aws' => ['DEF' => 'default'], 'cloudflare' => ['DEF' => '0']];
        public static function getModel2($domain) { return self::$dns; }
        public static function getModel($account, $domain = null, $domainId = null) { return self::$dns; }
    }

    class NewDb
    {
        public static int $released = 0;
        public static function pool() { return \think\facade\Db::connect(); }
        public static function release($connection, bool $broken = false) { self::$released++; }
    }
}

namespace app\service {
    class AwsSbService
    {
        public static array $ips = [];
        public function getInstancePublicIp($accountId, $region, $instanceId): string
        {
            $result = self::$ips[$instanceId] ?? '198.51.100.20';
            if ($result instanceof \Throwable) throw $result;
            return $result;
        }
    }
}

namespace app\utils {
    class CheckUtils
    {
        public static bool $healthy = false;
        public static function tcp(...$args): array { return ['status' => self::$healthy, 'errmsg' => 'unreachable']; }
        public static function curl(...$args): array { return self::tcp(); }
        public static function ping(...$args): array { return self::tcp(); }
    }

    class MsgNotice
    {
        public static array $sent = [];
        public static function send(...$args): void { self::$sent[] = $args; }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    use app\lib\DnsHelper;
    use app\lib\NewDb;
    use app\service\AwsSbService;
    use app\service\AwsSyncService;
    use app\service\OptimizeService;
    use app\service\ScheduleService;
    use app\service\TaskRecordService;
    use app\service\TaskRunner;
    use app\utils\CheckUtils;
    use app\utils\MsgNotice;
    use think\facade\Db;

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDERR, "pdo_sqlite is required; run with -d extension=pdo_sqlite\n");
        exit(1);
    }

    function config_get($key, $default = null, $force = false) { return $GLOBALS['test_config'][$key] ?? $default; }
    function config_set($key, $value) { $GLOBALS['test_config'][$key] = $value; }
    function isNullOrEmpty($value): bool { return $value === null || $value === ''; }
    function getDnsType($value): string { return str_contains($value, ':') ? 'AAAA' : 'A'; }

    final class FakeDns
    {
        public string $id = 'record-before';
        public string $value = '198.51.100.10';
        public string $error = '';
        public array $calls = [];
        public bool $failNext = false;
        public bool $booleanResult = false;

        public function updateDomainRecord($recordId, $name, $type, $value, $line = 'default', $ttl = 300)
        {
            $this->calls[] = ['id' => $recordId, 'value' => $value, 'ttl' => $ttl];
            if ($this->failNext) {
                $this->failNext = false;
                $this->error = 'DNS request failed';
                return false;
            }
            if ($recordId !== $this->id) {
                $this->error = 'Stale record ID';
                return false;
            }
            $this->value = $value;
            if ($this->booleanResult) return true;
            $this->id = 'record-' . $value;
            return $this->id;
        }

        public function getError(): string { return $this->error; }
        public function getSubDomainRecords($name, ...$args): array
        {
            return ['total' => 1, 'list' => [[
                'RecordId' => $this->id, 'Name' => $name, 'Type' => 'A',
                'Value' => $this->value, 'Line' => 'default', 'TTL' => 300,
            ]]];
        }
    }

    final class FixedOptimizeService extends OptimizeService
    {
        public function get_ip_address2($cdn_type = 1, $ip_type = 'v4')
        {
            return ['DEF' => [['ip' => '198.51.100.25']]];
        }
    }

    function expect($actual, $expected, string $message): void
    {
        if ($actual !== $expected) {
            throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
        }
    }

    function fixture(): void
    {
        $GLOBALS['test_config'] = [];
        DnsHelper::$dns = new FakeDns();
        NewDb::$released = 0;
        AwsSbService::$ips = [];
        CheckUtils::$healthy = false;
        MsgNotice::$sent = [];
        Db::setConfig([
            'default' => 'test',
            'connections' => ['test' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => 't_']],
        ]);
        $db = Db::connect('test', true);
        $schemas = [
            'account' => 'id INTEGER PRIMARY KEY, type TEXT, config TEXT',
            'domain' => 'id INTEGER PRIMARY KEY, aid INTEGER, name TEXT, thirdid TEXT',
            'dmtask' => 'id INTEGER PRIMARY KEY, did INTEGER, rr TEXT, recordid TEXT COLLATE NOCASE, recordinfo TEXT, main_value TEXT, backup_value TEXT, backup_mode INTEGER DEFAULT 1, type INTEGER DEFAULT 2, status INTEGER DEFAULT 0, cycle INTEGER DEFAULT 1, errcount INTEGER DEFAULT 0, switchtime INTEGER DEFAULT 0, checktype INTEGER DEFAULT 1, checkurl TEXT DEFAULT "", timeout INTEGER DEFAULT 2, tcpport INTEGER DEFAULT 80, proxy INTEGER DEFAULT 0, cdn INTEGER DEFAULT 0',
            'sctask' => 'id INTEGER PRIMARY KEY, did INTEGER, rr TEXT, recordid TEXT, recordinfo TEXT, value TEXT, type INTEGER DEFAULT 0, switchtype INTEGER DEFAULT 0, switchtime TEXT DEFAULT "2000-01-01 00:00", line TEXT DEFAULT "", nexttime INTEGER DEFAULT 1, active INTEGER DEFAULT 1, updatetime INTEGER DEFAULT 0',
            'aws_sync' => 'id INTEGER PRIMARY KEY, did INTEGER, rr TEXT, recordid TEXT, recordinfo TEXT, aws_account_id TEXT DEFAULT "account", aws_region TEXT DEFAULT "us-east-1", aws_instance_id TEXT DEFAULT "i-test", frequency INTEGER DEFAULT 10, last_dns_ip TEXT, last_ip TEXT, sync_count INTEGER DEFAULT 0, active INTEGER DEFAULT 1, status INTEGER DEFAULT 0, errmsg TEXT, checktime INTEGER DEFAULT 0, checknexttime INTEGER DEFAULT 0',
            'dmbackup_pool' => 'id INTEGER PRIMARY KEY, task_id INTEGER, ip TEXT, sort INTEGER',
            'dmlog' => 'id INTEGER PRIMARY KEY, taskid INTEGER, action INTEGER, errmsg TEXT, date TEXT',
            'log' => 'id INTEGER PRIMARY KEY, uid INTEGER, domain TEXT, action TEXT, data TEXT, addtime TEXT',
        ];
        foreach ($schemas as $table => $columns) {
            $db->execute('CREATE TABLE t_' . $table . ' (' . $columns . ')');
        }
        Db::name('account')->insert(['id' => 1, 'type' => 'aws', 'config' => '{}']);
        Db::name('domain')->insert(['id' => 1, 'aid' => 1, 'name' => 'example.test', 'thirdid' => 'zone-1']);
        Db::name('domain')->insert(['id' => 2, 'aid' => 1, 'name' => 'other.test', 'thirdid' => 'zone-2']);
    }

    function seedTask(string $table, int $id = 1, array $extra = []): array
    {
        $task = [
            'id' => $id, 'did' => 1, 'rr' => 'www', 'recordid' => 'record-before',
            'recordinfo' => json_encode(['Line' => 'default', 'LineName' => 'Default', 'TTL' => 300]),
        ];
        if ($table === 'dmtask') {
            $task += ['main_value' => '198.51.100.10', 'backup_value' => '198.51.100.99'];
        } elseif ($table === 'sctask') {
            $task['value'] = '198.51.100.21';
            $task['recordinfo'] = json_encode(['Value' => '198.51.100.10', 'Line' => 'default', 'LineName' => 'Default', 'TTL' => 300]);
        } else {
            $task['last_dns_ip'] = '198.51.100.10';
        }
        Db::name($table)->insert(array_replace($task, $extra));
        return Db::name($table)->where('id', $id)->find();
    }

    function seedAllTasks(): void
    {
        foreach (['dmtask', 'sctask', 'aws_sync'] as $table) seedTask($table);
    }

    $tests = [];
    $tests['Route 53 changes update only matching references and refresh small caches'] = function () {
        seedAllTasks();
        seedTask('sctask', 2, ['did' => 2]);
        seedTask('sctask', 3, ['recordid' => 'unrelated-record']);
        TaskRecordService::syncRecordId(['id' => 1, 'type' => 'aws'], 'record-before', 'record-after', [
            'Name' => 'renamed', 'Value' => '198.51.100.22', 'TTL' => 900,
        ]);
        foreach (['dmtask', 'sctask', 'aws_sync'] as $table) {
            $task = Db::name($table)->where('id', 1)->find();
            expect($task['recordid'], 'record-after', $table . ' must follow the changed ID');
            expect($task['rr'], 'renamed', $table . ' must follow a renamed record');
            $info = json_decode($task['recordinfo'], true);
            expect($info['TTL'], 900, $table . ' must refresh TTL');
            expect(isset($info['Name']), false, 'Full names must not inflate cached record info');
        }
        expect(Db::name('sctask')->where('id', 2)->value('recordid'), 'record-before', 'Other domains must be isolated');
        expect(Db::name('sctask')->where('id', 3)->value('recordid'), 'unrelated-record', 'Other records must be isolated');
        expect(Db::name('dmtask')->where('id', 1)->value('main_value'), '198.51.100.10', 'Keep the configured monitoring target');
        expect(Db::name('sctask')->where('id', 1)->value('value'), '198.51.100.21', 'Keep the scheduled target');
        expect(json_decode(Db::name('sctask')->where('id', 1)->value('recordinfo'), true)['Value'], '198.51.100.22', 'Refresh the schedule form cache');
        expect(Db::name('aws_sync')->where('id', 1)->value('last_dns_ip'), null, 'Invalidate AWS synchronization cache');
    };
    $tests['Boolean results and other providers preserve record IDs'] = function () {
        seedAllTasks();
        foreach ([true, false, 1, '', null] as $result) {
            TaskRecordService::syncRecordId(['id' => 1, 'type' => 'aws'], 'record-before', $result);
        }
        TaskRecordService::syncRecordId(['id' => 1, 'type' => 'cloudflare'], 'record-before', 'unrelated-result');
        foreach (['dmtask', 'sctask', 'aws_sync'] as $table) {
            expect(Db::name($table)->where('id', 1)->value('recordid'), 'record-before', 'Never persist a success flag as an ID');
        }
    };
    $tests['Record IDs match exactly even on a case-insensitive database column'] = function () {
        $first = 'd3d3HlRYVB4iYWFBIg';
        $second = 'd3d3HlRYVB4iYWFbIg';
        seedTask('dmtask', 1, ['recordid' => $first]);
        seedTask('dmtask', 2, ['recordid' => $second]);
        expect(Db::name('dmtask')->where('recordid', $first)->count(), 2, 'Fixture must reproduce a case-insensitive comparison');
        TaskRecordService::syncRecordId(['id' => 1, 'type' => 'aws'], $first, 'updated-id');
        expect(Db::name('dmtask')->where('id', 1)->value('recordid'), 'updated-id', 'Exact record must update');
        expect(Db::name('dmtask')->where('id', 2)->value('recordid'), $second, 'Different case must remain a different record');
    };
    $tests['Database failure rolls back every task reference and remains visible'] = function () {
        seedAllTasks();
        Db::execute("CREATE TRIGGER reject_sync BEFORE UPDATE ON t_aws_sync BEGIN SELECT RAISE(ABORT, 'simulated database failure'); END");
        $failed = false;
        try {
            TaskRecordService::syncRecordId(['id' => 1, 'type' => 'aws'], 'record-before', 'record-after');
        } catch (RuntimeException $e) {
            $failed = str_contains($e->getMessage(), 'DNS 已更新') && $e->getPrevious() !== null;
        }
        expect($failed, true, 'A database synchronization error must surface');
        foreach (['dmtask', 'sctask', 'aws_sync'] as $table) {
            expect(Db::name($table)->where('id', 1)->value('recordid'), 'record-before', 'All reference updates must roll back together');
        }
    };
    $tests['Several scheduled changes reuse the latest ID even with a stale task list'] = function () {
        seedAllTasks();
        $second = seedTask('sctask', 2, ['value' => '198.51.100.22']);
        $service = new ScheduleService();
        $service->execute_one(Db::name('sctask')->where('id', 1)->find());
        $service->execute_one($second);
        expect(DnsHelper::$dns->value, '198.51.100.22', 'The second schedule must update the same DNS record');
        expect(DnsHelper::$dns->calls[1]['id'], 'record-198.51.100.21', 'The second schedule must reread its refreshed ID');
        foreach (['dmtask', 'sctask', 'aws_sync'] as $table) {
            expect(Db::name($table)->where('id', 1)->value('recordid'), 'record-198.51.100.22', 'All tasks must follow repeated changes');
        }
    };
    $tests['AWS sync preserves seconds and invalidated caches allow reconciliation'] = function () {
        seedAllTasks();
        $stale = Db::name('aws_sync')->where('id', 1)->find();
        $service = new AwsSyncService();
        $service->executeOne($stale);
        expect(DnsHelper::$dns->value, '198.51.100.20', 'AWS instance IP should reach DNS');
        $service->executeOne($stale);
        expect(count(DnsHelper::$dns->calls), 1, 'Unchanged IP must avoid an unnecessary DNS call');
        $schedule = Db::name('sctask')->where('id', 1)->find();
        (new ScheduleService())->execute_one($schedule);
        $service->executeOne($stale);
        expect(DnsHelper::$dns->value, '198.51.100.20', 'An invalidated cache must reconcile the AWS IP again');
        expect((int)Db::name('aws_sync')->where('id', 1)->value('sync_count'), 2, 'Only successful updates increment the counter');
        $now = time();
        foreach ([0 => 10, 10 => 10, 60 => 60, 90000 => 86400] as $interval => $expected) {
            $next = AwsSyncService::calcNextCheckTime($interval);
            expect($next >= $now + $expected && $next <= time() + $expected, true, 'Frequency must remain in seconds');
        }
    };
    $tests['An AWS task Throwable does not prevent other due tasks from running'] = function () {
        seedTask('aws_sync', 1, ['aws_instance_id' => 'i-broken']);
        seedTask('aws_sync', 2, ['aws_instance_id' => 'i-working']);
        AwsSbService::$ips['i-broken'] = new TypeError('Invalid upstream response');
        ob_start();
        try { (new AwsSyncService())->execute(); } finally { ob_end_clean(); }
        expect((int)Db::name('aws_sync')->where('id', 1)->value('status'), 2, 'Broken task must record failure');
        expect((int)Db::name('aws_sync')->where('id', 2)->value('status'), 1, 'Other due tasks must continue');
        expect((int)Db::name('aws_sync')->where('id', 1)->value('checknexttime') >= time() + 9, true, 'A failed task must wait before retrying');
    };
    $tests['Failed failover keeps its pool and state, then succeeds on retry'] = function () {
        seedAllTasks();
        Db::name('dmbackup_pool')->insert(['id' => 1, 'task_id' => 1, 'ip' => '198.51.100.30', 'sort' => 1]);
        Db::name('dmbackup_pool')->insert(['id' => 2, 'task_id' => 1, 'ip' => '198.51.100.31', 'sort' => 2]);
        $task = Db::name('dmtask')->where('id', 1)->find();
        DnsHelper::$dns->failNext = true;
        $runner = new TaskRunner();
        $runner->execute($task);
        expect((int)Db::name('dmtask')->where('id', 1)->value('status'), 0, 'Do not commit a failed switch');
        expect(Db::name('dmbackup_pool')->count(), 2, 'A failure must not consume a backup IP');
        expect(Db::name('dmlog')->count(), 0, 'A failure must not be logged as a completed switch');
        expect(count(MsgNotice::$sent), 0, 'A failure must not send a success notification');
        $runner->execute($task);
        expect(DnsHelper::$dns->value, '198.51.100.30', 'Retry must use the first backup IP');
        expect((int)Db::name('dmtask')->where('id', 1)->value('status'), 1, 'Commit state after a successful switch');
        expect(Db::name('dmtask')->where('id', 1)->value('main_value'), '198.51.100.30', 'Pool mode must monitor the newly selected IP');
        expect(Db::name('dmbackup_pool')->count(), 1, 'Consume exactly one IP after success');
        expect(Db::name('dmbackup_pool')->value('ip'), '198.51.100.31', 'Preserve the next backup');
        expect(Db::name('sctask')->where('id', 1)->value('recordid'), 'record-198.51.100.30', 'Failover must update other task references');
        expect(NewDb::$released, 2, 'Release the borrowed database connection on both paths');
        CheckUtils::$healthy = true;
        $runner->execute($task);
        expect((int)Db::name('dmtask')->where('id', 1)->value('status'), 0, 'A later healthy check must use the refreshed task');
        expect(Db::name('dmbackup_pool')->count(), 1, 'Recovery must not consume another IP');
    };
    $tests['Ordinary boolean DNS adapters keep their IDs during AWS synchronization'] = function () {
        seedAllTasks();
        Db::name('account')->where('id', 1)->update(['type' => 'cloudflare']);
        DnsHelper::$dns->booleanResult = true;
        (new AwsSyncService())->executeOne(Db::name('aws_sync')->where('id', 1)->find());
        expect(Db::name('aws_sync')->where('id', 1)->value('recordid'), 'record-before', 'A boolean success must not corrupt the stored ID');
    };
    $tests['IP optimization propagates changed Route 53 IDs to existing tasks'] = function () {
        seedAllTasks();
        $result = (new FixedOptimizeService())->execute_one([
            'did' => 1, 'rr' => 'www', 'ip_type' => 'v4', 'cdn_type' => 1, 'type' => 0, 'recordnum' => 1, 'ttl' => 120,
        ]);
        expect(str_contains($result, '修改1条'), true, 'The optimizer must update the existing record');
        foreach (['dmtask', 'sctask', 'aws_sync'] as $table) {
            expect(Db::name($table)->where('id', 1)->value('recordid'), 'record-198.51.100.25', 'Optimization must preserve associated task references');
        }
    };

    $failed = 0;
    foreach ($tests as $name => $test) {
        try {
            fixture();
            $test();
            echo '[PASS] ' . $name . "\n";
        } catch (Throwable $e) {
            $failed++;
            fwrite(STDERR, '[FAIL] ' . $name . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        }
    }
    echo count($tests) . ' tests, ' . $failed . " failed\n";
    exit($failed === 0 ? 0 : 1);
}
