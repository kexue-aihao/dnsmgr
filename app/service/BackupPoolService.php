<?php

namespace app\service;

use think\facade\Db;

/**
 * 容灾切换备用 IP 池
 */
class BackupPoolService
{
    public static function parseIps($text): array
    {
        if (is_array($text)) {
            $lines = $text;
        } else {
            $lines = preg_split('/[\r\n,;]+/', (string)$text);
        }
        $ips = [];
        foreach ($lines as $line) {
            $ip = trim($line);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }
        return array_values(array_unique($ips));
    }

    public static function addIps(int $taskId, array $ips, array $skipValues = []): int
    {
        if (empty($ips)) {
            return 0;
        }
        $added = 0;
        $maxSort = (int)Db::name('dmbackup_pool')->where('task_id', $taskId)->max('sort');
        $existing = Db::name('dmbackup_pool')->where('task_id', $taskId)->column('ip');
        $existing = array_merge($existing, $skipValues);
        foreach ($ips as $ip) {
            if (in_array($ip, $existing, true)) {
                continue;
            }
            $maxSort++;
            Db::name('dmbackup_pool')->insert([
                'task_id' => $taskId,
                'ip' => $ip,
                'sort' => $maxSort,
                'addtime' => time(),
            ]);
            $existing[] = $ip;
            $added++;
        }
        return $added;
    }

    public static function count(int $taskId): int
    {
        return (int)Db::name('dmbackup_pool')->where('task_id', $taskId)->count();
    }

    public static function list(int $taskId): array
    {
        return Db::name('dmbackup_pool')->where('task_id', $taskId)->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
    }

    public static function peekNext($db, int $taskId): ?array
    {
        $row = $db->name('dmbackup_pool')->where('task_id', $taskId)->order('sort', 'asc')->order('id', 'asc')->find();
        return $row ?: null;
    }

    public static function deleteByTask(int $taskId): void
    {
        Db::name('dmbackup_pool')->where('task_id', $taskId)->delete();
    }

    public static function deleteIp(int $taskId, string $ip): bool
    {
        return Db::name('dmbackup_pool')->where(['task_id' => $taskId, 'ip' => $ip])->delete() > 0;
    }

    public static function resolveTaskId($taskId = null, $domainId = null, $rr = null): ?array
    {
        if ($taskId) {
            $task = Db::name('dmtask')->alias('A')->join('domain B', 'A.did = B.id')->where('A.id', $taskId)->field('A.*,B.name domain')->find();
            return $task ?: null;
        }
        if ($domainId && $rr) {
            $task = Db::name('dmtask')->alias('A')->join('domain B', 'A.did = B.id')->where('A.did', $domainId)->where('A.rr', $rr)->field('A.*,B.name domain')->find();
            return $task ?: null;
        }
        return null;
    }
}
