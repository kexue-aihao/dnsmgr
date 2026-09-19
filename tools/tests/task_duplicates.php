<?php

/** Run: php -d extension=pdo_sqlite tools/tests/task_duplicates.php */
namespace app\controller {
    function checkPermission(...$args): bool { return true; }
    function json($data) { return $data; }
    function input($key, $default = null, $filter = null)
    {
        if ($key === 'param.action') return $GLOBALS['duplicate_action'];
        $key = substr($key, strlen('post.'));
        $integer = str_ends_with($key, '/d');
        if ($integer) $key = substr($key, 0, -2);
        $value = $GLOBALS['duplicate_input'][$key] ?? $default;
        if ($integer) return (int)$value;
        return $filter === 'trim' && is_string($value) ? trim($value) : $value;
    }
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    use app\controller\Dmonitor;
    use app\controller\Schedule;
    use think\facade\Db;

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDERR, "pdo_sqlite is required; run with -d extension=pdo_sqlite\n");
        exit(1);
    }

    function expectCode(array $response, int $code, string $message): void
    {
        if ($response['code'] !== $code) {
            throw new RuntimeException($message . ': ' . json_encode($response, JSON_UNESCAPED_UNICODE));
        }
    }

    function fixture(): void
    {
        Db::setConfig([
            'default' => 'test',
            'connections' => ['test' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => 't_']],
        ]);
        $db = Db::connect('test', true);
        $common = 'id INTEGER PRIMARY KEY AUTOINCREMENT, did INTEGER, rr TEXT, recordid TEXT COLLATE NOCASE, recordinfo TEXT, type INTEGER, cycle INTEGER, remark TEXT, addtime INTEGER, active INTEGER';
        $db->execute('CREATE TABLE t_dmtask (' . $common . ', main_value TEXT, backup_value TEXT, backup_mode INTEGER, checktype INTEGER, checkurl TEXT, tcpport INTEGER, frequency INTEGER, timeout INTEGER, proxy INTEGER, cdn INTEGER)');
        $db->execute('CREATE TABLE t_sctask (' . $common . ', switchtype INTEGER, switchdate TEXT, switchtime TEXT, value TEXT, line TEXT, nexttime INTEGER)');
    }

    function invoke($controller, string $method, string $action, int $domain, string $recordId, array $extra = []): array
    {
        $GLOBALS['duplicate_action'] = $action;
        $GLOBALS['duplicate_input'] = array_replace([
            'did' => $domain, 'rr' => 'www', 'recordid' => $recordId,
            'recordinfo' => '{"Line":"default","TTL":300}',
            'type' => 0, 'cycle' => 1, 'remark' => '',
            'main_value' => '198.51.100.10', 'backup_value' => '', 'backup_pool' => '', 'backup_mode' => 0,
            'checktype' => 1, 'checkurl' => '', 'tcpport' => 80, 'frequency' => 10, 'timeout' => 2, 'proxy' => 0, 'cdn' => 0,
            'switchtype' => 0, 'switchdate' => '', 'switchtime' => '2030-01-01 08:00', 'value' => '198.51.100.20', 'line' => '',
        ], $extra);
        return $controller->$method();
    }

    try {
        foreach ([Dmonitor::class => ['task_op', 'dmtask'], Schedule::class => ['stask_op', 'sctask']] as $class => [$method, $table]) {
            fixture();
            $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            expectCode(invoke($controller, $method, 'add', 1, 'AbCRecordId'), 0, 'The first task should be accepted');
            if (Db::name($table)->where('recordid', 'abcrecordid')->count() !== 1) {
                throw new RuntimeException('The fixture must reproduce a case-insensitive database collation');
            }
            expectCode(invoke($controller, $method, 'add', 2, 'AbCRecordId'), 0, 'The same ID in another domain is a different record');
            expectCode(invoke($controller, $method, 'add', 1, 'AbCRecordId'), -1, 'A true duplicate in the same domain must be rejected');
            expectCode(invoke($controller, $method, 'add', 1, 'abcrecordid'), 0, 'Case-sensitive record IDs must remain distinct');
            expectCode(invoke($controller, $method, 'edit', 2, 'AbCRecordId', ['id' => 2]), 0, 'Editing a task must ignore matching IDs in other domains');
            expectCode(invoke($controller, $method, 'edit', 1, 'abcrecordid', ['id' => 3]), 0, 'Editing must exclude itself and preserve case-sensitive comparison');
            expectCode(invoke($controller, $method, 'edit', 1, 'AbCRecordId', ['id' => 3]), -1, 'Editing into a same-domain duplicate must be rejected');
            if (Db::name($table)->where('id', 3)->value('recordid') !== 'abcrecordid') {
                throw new RuntimeException('A rejected edit must leave the saved task unchanged');
            }
            if ($table === 'sctask') {
                expectCode(invoke($controller, $method, 'add', 1, 'AbCRecordId', ['switchtime' => '2030-01-01 09:00']), 0, 'A different schedule time remains valid');
                expectCode(invoke($controller, $method, 'add', 1, 'AbCRecordId', ['switchtype' => 1]), 0, 'A different scheduled operation remains valid');
            }
            echo '[PASS] ' . $class . " add/edit domain isolation, exact IDs, and duplicate protection\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        exit(1);
    }
    echo "Task duplicate checks passed\n";
}
