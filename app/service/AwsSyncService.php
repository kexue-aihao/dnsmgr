<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\lib\DnsHelper;

/**
 * AWS 小助理换 IP 后同步到 DNS A 记录
 */
class AwsSyncService
{
    /** 检测间隔下限（秒） */
    public const MIN_FREQUENCY = 10;

    /** 检测间隔上限（秒，24 小时） */
    public const MAX_FREQUENCY = 86400;

    public static function calcNextCheckTime(int $frequency): int
    {
        return time() + max(self::MIN_FREQUENCY, min(self::MAX_FREQUENCY, $frequency));
    }

    /**
     * 常驻进程批量执行
     */
    public function execute(): bool
    {
        $list = Db::name('aws_sync')->where('active', 1)->where('checknexttime', '<=', time())->order('id', 'ASC')->select();
        if (count($list) == 0) {
            return false;
        }

        echo '开始执行 AWS IP 同步任务，共 ' . count($list) . ' 个' . "\n";
        foreach ($list as $row) {
            try {
                $result = $this->executeOne($row);
                Db::name('aws_sync')->where('id', $row['id'])->update([
                    'status' => 1,
                    'errmsg' => null,
                    'checktime' => time(),
                    'checknexttime' => self::calcNextCheckTime((int)$row['frequency']),
                ]);
                echo 'AWS 同步任务 ' . $row['id'] . '：' . $result . "\n";
            } catch (Exception $e) {
                Db::name('aws_sync')->where('id', $row['id'])->update([
                    'status' => 2,
                    'errmsg' => mb_substr($e->getMessage(), 0, 480),
                    'checktime' => time(),
                    'checknexttime' => self::calcNextCheckTime((int)$row['frequency']),
                ]);
                echo 'AWS 同步任务 ' . $row['id'] . ' 失败：' . $e->getMessage() . "\n";
            }
        }
        config_set('aws_sync_time', date('Y-m-d H:i:s'));
        return true;
    }

    /**
     * @throws Exception
     */
    public function executeOne(array $row): string
    {
        $aws = new AwsSbService();
        $newIp = $aws->getInstancePublicIp($row['aws_account_id'], $row['aws_region'], $row['aws_instance_id']);

        Db::name('aws_sync')->where('id', $row['id'])->update(['last_ip' => $newIp]);

        if (!empty($row['last_dns_ip']) && $row['last_dns_ip'] === $newIp) {
            return 'IP 未变化（' . $newIp . '），跳过 DNS 更新';
        }

        $drow = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.id', $row['did'])->field('A.*,B.type,B.config')->find();
        if (!$drow) {
            throw new Exception('域名不存在（ID：' . $row['did'] . '）');
        }

        $recordinfo = json_decode((string)$row['recordinfo'], true);
        if (!is_array($recordinfo) || empty($recordinfo['Line']) || empty($recordinfo['TTL'])) {
            throw new Exception('解析记录信息不完整，请重新获取解析记录后保存');
        }

        $dns = DnsHelper::getModel2($drow);
        $res = $dns->updateDomainRecord(
            $row['recordid'],
            $row['rr'],
            'A',
            $newIp,
            $recordinfo['Line'],
            (int)$recordinfo['TTL']
        );
        if (!$res) {
            throw new Exception('修改解析失败：' . $dns->getError());
        }

        Db::name('aws_sync')->where('id', $row['id'])->update([
            'last_dns_ip' => $newIp,
            'last_ip' => $newIp,
            'sync_count' => (int)$row['sync_count'] + 1,
        ]);

        $domainName = Db::name('domain')->where('id', $row['did'])->value('name');
        $logData = $row['rr'] . '.' . $domainName . ' A 记录 ' . ($row['last_dns_ip'] ?: '未知') . ' → ' . $newIp . '（实例 ' . $row['aws_instance_id'] . '）';
        Db::name('log')->insert([
            'uid' => 0,
            'domain' => $domainName ?: '',
            'action' => 'AWS IP同步',
            'data' => $logData,
            'addtime' => date('Y-m-d H:i:s'),
        ]);

        return '已更新 A 记录为 ' . $newIp;
    }
}
