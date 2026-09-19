<?php

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Route 53 的记录 ID 包含记录值，修改后同步所有引用该记录的任务。
 */
class TaskRecordService
{
    public static function syncRecordId(array $domain, string $oldRecordId, $result, array $record = [], $connection = null): void
    {
        if (($domain['type'] ?? null) !== 'aws' || !is_string($result) || $result === '') {
            return;
        }
        if ($result === $oldRecordId && empty($record)) {
            return;
        }

        try {
            $connection = $connection ?? Db::connect();
            // Base64URL ID 区分大小写，旧表的默认排序规则可能不区分。
            $recordIdHex = strtoupper(bin2hex($oldRecordId));
            $connection->transaction(function () use ($connection, $domain, $recordIdHex, $result, $record) {
                foreach (['dmtask', 'sctask', 'aws_sync'] as $table) {
                    $tasks = $connection->name($table)->where('did', $domain['id'])->whereRaw('HEX(`recordid`) = ?', [$recordIdHex])->select();
                    foreach ($tasks as $task) {
                        $changes = ['recordid' => $result];
                        if (isset($record['Name'])) {
                            $changes['rr'] = $record['Name'];
                        }
                        $recordinfo = json_decode((string)$task['recordinfo'], true);
                        if (is_array($recordinfo)) {
                            // 保留原有的小型表单缓存，不把完整记录写入 recordinfo。
                            foreach (['Value', 'Type', 'Line', 'LineName', 'TTL', 'MX'] as $field) {
                                if (array_key_exists($field, $recordinfo) && array_key_exists($field, $record)) {
                                    $recordinfo[$field] = $record[$field];
                                }
                            }
                            $changes['recordinfo'] = json_encode($recordinfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                        }
                        if ($table === 'aws_sync') {
                            // 其他任务可能改动了 DNS，下一次检测不能继续使用旧的同步缓存。
                            $changes['last_dns_ip'] = null;
                        }
                        $connection->name($table)->where('id', $task['id'])->where('did', $domain['id'])->whereRaw('HEX(`recordid`) = ?', [$recordIdHex])->update($changes);
                    }
                }
            });
        } catch (\Throwable $e) {
            throw new RuntimeException('DNS 已更新，但同步任务记录 ID 失败，请重新获取解析记录后保存任务：' . $e->getMessage(), 0, $e);
        }
    }
}
