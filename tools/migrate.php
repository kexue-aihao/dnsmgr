<?php

// update.sh 的 SQL 执行器：无需 vendor，失败时返回非零状态。
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

final class DnsmgrMigrationRunner
{
    public function __construct(private PDO $db, private string $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
            throw new InvalidArgumentException('数据库表前缀格式不正确');
        }
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** 普通 DDL/DML 切分；保留字符串和括号内的分隔符，不支持存储过程。 */
    public static function splitSql(string $sql, string $separator = ';'): array
    {
        $parts = [];
        $part = '';
        $quote = null;
        $depth = 0;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($quote !== null) {
                $part .= $char;
                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $part .= $sql[++$i];
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $part .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $part .= $char;
                continue;
            }
            if ($char === '#' || ($char === '-' && $next === '-' && ($i + 2 === $length || ctype_space($sql[$i + 2])))) {
                while ($i < $length && $sql[$i] !== "\n") $i++;
                $part .= ' ';
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) throw new RuntimeException('迁移 SQL 包含未闭合注释');
                $i = $end + 1;
                $part .= ' ';
                continue;
            }
            if ($char === '(') $depth++;
            if ($char === ')' && --$depth < 0) throw new RuntimeException('迁移 SQL 括号不匹配');
            if ($char === $separator && $depth === 0) {
                if (trim($part) !== '') $parts[] = trim($part);
                $part = '';
            } else {
                $part .= $char;
            }
        }
        if ($quote !== null || $depth !== 0) throw new RuntimeException('迁移 SQL 包含未闭合字符串或括号');
        if (trim($part) !== '') $parts[] = trim($part);
        return $parts;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $query->execute([$this->prefix . $table, $column]);
        return (int) $query->fetchColumn() > 0;
    }

    public function executeFile(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('迁移文件不可读');
        }
        $sql = str_replace('dnsmgr_', $this->prefix, file_get_contents($file));
        foreach (self::splitSql($sql) as $statement) {
            // 历史 update.sql 有多字段 ALTER；逐项执行，避免第一个重复字段使后面的新字段丢失。
            if (preg_match('/^(ALTER\s+TABLE\s+`?\w+`?\s+)(.+)$/is', $statement, $match)) {
                foreach (self::splitSql($match[2], ',') as $clause) {
                    $this->executeStatement($match[1] . $clause);
                }
            } else {
                $this->executeStatement($statement);
            }
        }
    }

    private function executeStatement(string $statement): void
    {
        try {
            $this->db->exec($statement);
        } catch (PDOException $e) {
            $errno = (int) ($e->errorInfo[1] ?? 0);
            $duplicateColumn = $errno === 1060 && preg_match('/^ALTER\s+TABLE\s+`?\w+`?\s+ADD\s+COLUMN\b/i', $statement);
            $duplicateIndex = $errno === 1061 && preg_match('/^ALTER\s+TABLE\s+`?\w+`?\s+ADD\s+(?:KEY|INDEX)\b/i', $statement);
            $accountRename = $errno === 1054
                && preg_match('/^ALTER\s+TABLE\s+`?' . preg_quote($this->prefix, '/') . 'account`?\s+CHANGE\s+COLUMN\s+`?ak`?\s+`?name`?\s+/i', $statement)
                && !$this->hasColumn('account', 'ak') && $this->hasColumn('account', 'name');
            if (!$duplicateColumn && !$duplicateIndex && !$accountRename) throw $e;
            fwrite(STDOUT, "已存在，跳过已完成的字段或索引变更\n");
        }
    }

    /** 旧版本把认证信息放在 ak/sk/ext 中；只补齐空 config，不改用户已保存的 JSON。 */
    public function migrateLegacyAccounts(array $providers): int
    {
        if (!$this->hasColumn('account', 'sk') && !$this->hasColumn('account', 'ext')) return 0;
        $table = '`' . $this->prefix . 'account`';
        $rows = $this->db->query("SELECT * FROM $table WHERE config IS NULL OR config = ''");
        $update = $this->db->prepare("UPDATE $table SET config = ? WHERE id = ? AND (config IS NULL OR config = '')");
        $count = 0;
        $this->db->beginTransaction();
        try {
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($providers[$row['type']]['config'])) {
                    fwrite(STDOUT, '保留未知 DNS 类型的旧账户，需检查账户 ID: ' . (int) $row['id'] . "\n");
                    continue;
                }
                $config = [];
                $fields = ['name', 'sk', 'ext'];
                $index = 0;
                foreach ($providers[$row['type']]['config'] as $field => $definition) {
                    if ($field === 'proxy') {
                        $config[$field] = $row['proxy'] ?? 0;
                    } elseif ($index < count($fields)) {
                        $config[$field] = $row[$fields[$index++]] ?? '';
                    }
                }
                $update->execute([json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $row['id']]);
                $count += $update->rowCount();
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $count;
    }
}

// 允许测试加载执行器，只有直接从 CLI 调用才连接数据库。
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

try {
    $file = $argv[1] ?? '';
    if (!is_file($file) || !is_readable($file)) throw new RuntimeException('迁移文件不可读');
    $prefix = getenv('DNSMGR_DB_PREFIX');
    if ($prefix === false || !preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
        throw new RuntimeException('数据库表前缀格式不正确');
    }
    $db = new PDO(
        'mysql:host=' . getenv('DNSMGR_DB_HOST') . ';port=' . getenv('DNSMGR_DB_PORT')
        . ';dbname=' . getenv('DNSMGR_DB_NAME') . ';charset=utf8mb4',
        getenv('DNSMGR_DB_USER'),
        getenv('DNSMGR_DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $runner = new DnsmgrMigrationRunner($db, $prefix);
    $runner->executeFile($file);
    if (in_array('--core', array_slice($argv, 2), true)) {
        require_once dirname(__DIR__) . '/app/lib/DnsHelper.php';
        $count = $runner->migrateLegacyAccounts(\app\lib\DnsHelper::$dns_config);
        fwrite(STDOUT, "旧 DNS 账户配置迁移完成: $count 个\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, '迁移失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
