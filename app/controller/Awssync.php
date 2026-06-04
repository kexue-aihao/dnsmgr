<?php

namespace app\controller;

use app\BaseController;
use Exception;
use think\facade\Cache;
use think\facade\Db;
use think\facade\View;
use app\lib\DnsHelper;
use app\service\AwsSbService;
use app\service\AwsSyncService;

class Awssync extends BaseController
{
    public function set()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if ($this->request->isPost()) {
            $token = input('post.aws_sb_token', null, 'trim');
            $apiUrl = input('post.aws_sb_api_url', null, 'trim');
            if ($apiUrl === '') {
                $apiUrl = 'https://api.aws.sb';
            }
            config_set('aws_sb_token', $token);
            config_set('aws_sb_api_url', rtrim($apiUrl, '/'));
            Cache::delete('configs');
            return json(['code' => 0, 'msg' => '保存成功']);
        }
        return View::fetch();
    }

    public function test()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $accountId = input('post.aws_account_id', null, 'trim');
        $region = input('post.aws_region', null, 'trim');
        $instanceId = input('post.aws_instance_id', null, 'trim');

        if ($accountId === '' || $region === '' || $instanceId === '') {
            $task = Db::name('aws_sync')->where('active', 1)->order('id', 'ASC')->find();
            if (!$task) {
                return json(['code' => -1, 'msg' => '请填写测试用的账号/区域/实例 ID，或先添加一条同步任务']);
            }
            $accountId = $task['aws_account_id'];
            $region = $task['aws_region'];
            $instanceId = $task['aws_instance_id'];
        }

        if ($region === '' && $accountId !== '') {
            $aws = new AwsSbService();
            foreach ($aws->getAccounts() as $acc) {
                if ($acc['id'] === $accountId) {
                    $region = \app\service\AwsRegionResolver::resolve('', null, $acc['name']);
                    break;
                }
            }
        }
        if ($region === '') {
            return json(['code' => -1, 'msg' => 'Region 不能为空，请从实例列表选择或手动填写']);
        }

        try {
            $ip = (new AwsSbService())->getInstancePublicIp($accountId, $region, $instanceId);
            return json(['code' => 0, 'msg' => '连接成功，当前公网 IP：' . $ip, 'ip' => $ip]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function accounts()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        try {
            $list = (new AwsSbService())->getAccounts();
            return json(['code' => 0, 'data' => $list, 'total' => count($list)]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function instances()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $accountId = input('post.account_id', '', 'trim');
        try {
            $aws = new AwsSbService();
            if ($accountId !== '') {
                $accountName = $accountId;
                foreach ($aws->getAccounts() as $account) {
                    if ($account['id'] === $accountId) {
                        $accountName = $account['name'];
                        break;
                    }
                }
                $list = $aws->listInstancesByAccount($accountId, $accountName);
            } else {
                $list = $aws->listAllInstances(null, 40);
            }
            return json(['code' => 0, 'data' => $list, 'total' => count($list)]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function remote()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        if (function_exists('set_time_limit')) {
            @set_time_limit(90);
        }
        $withInstances = input('post.with_instances/d', 1) === 1;
        try {
            $aws = new AwsSbService();
            $accounts = $aws->getAccounts();
            $instances = [];
            $warn = '';
            if ($withInstances) {
                $instances = $aws->listAllInstances($accounts, 40);
                if (empty($instances) && !empty($accounts)) {
                    $warn = '已读取到账号但未发现 EC2 实例，请确认小助理上是否有运行中实例，或手动填写下方测试字段';
                }
            }
            return json([
                'code' => 0,
                'accounts' => $accounts,
                'instances' => $instances,
                'total' => count($instances),
                'warn' => $warn,
            ]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function preflight()
    {
        if (!checkPermission(2)) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        $token = trim((string)config_get('aws_sb_token', ''));
        $tableOk = true;
        $tableMsg = '';
        $taskCount = 0;
        try {
            $taskCount = (int)Db::name('aws_sync')->count();
        } catch (\Throwable $e) {
            $tableOk = false;
            $tableMsg = $e->getMessage();
        }
        $domainCount = 0;
        try {
            $domainCount = (int)Db::name('domain')->count();
        } catch (\Throwable $e) {
            // ignore
        }
        return json([
            'code' => 0,
            'token_ok' => $token !== '',
            'table_ok' => $tableOk,
            'table_msg' => $tableMsg,
            'domain_count' => $domainCount,
            'task_count' => $taskCount,
        ]);
    }

    public function domain_records()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $id = input('post.did/d');
        if (empty($id)) {
            return json(['code' => -1, 'msg' => '请选择域名']);
        }
        try {
            $drow = Db::name('domain')->where('id', $id)->find();
            if (!$drow) {
                return json(['code' => -1, 'msg' => '域名不存在']);
            }
            if (!checkPermission(0, $drow['name'])) {
                return json(['code' => -1, 'msg' => '无权限']);
            }

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            $recordLine = cache('record_line_' . $drow['id']);
            if (empty($recordLine)) {
                $recordLine = $dns->getRecordLine() ?: [];
                if ($recordLine) {
                    cache('record_line_' . $drow['id'], $recordLine, 604800);
                }
            }

            $all = $this->fetchDomainARecords($dns, $drow, $recordLine, 'A');
            if (empty($all)) {
                $all = $this->fetchDomainARecords($dns, $drow, $recordLine, null);
            }
            if (empty($all)) {
                return json(['code' => -1, 'msg' => '该域名下没有 A 记录，请先在 DNS 面板添加 A 记录后再试']);
            }

            return json(['code' => 0, 'data' => $all, 'total' => count($all), 'domain' => $drow['name']]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '读取解析记录失败：' . $e->getMessage()]);
        }
    }

    private function fetchDomainARecords($dns, array $drow, $recordLine, $typeFilter)
    {
        $all = [];
        $page = 1;
        $pageSize = 300;
        $total = 0;
        do {
            $result = $dns->getDomainRecords($page, $pageSize, null, null, null, $typeFilter, null, null);
            if ($result === false) {
                throw new Exception('获取解析记录失败：' . $dns->getError());
            }
            foreach ($result['list'] as $row) {
                if (strtoupper((string)($row['Type'] ?? 'A')) !== 'A') {
                    continue;
                }
                $rr = $this->parseRecordRr($row['Name'] ?? '', $drow['name']);
                $value = $this->normalizeRecordValue($row['Value'] ?? '');
                $lineName = isset($recordLine[$row['Line']]) ? $recordLine[$row['Line']]['name'] : ($row['Line'] ?? '');
                $row['rr'] = $rr;
                $row['LineName'] = $lineName;
                $host = $rr === '@' ? $drow['name'] : $rr . '.' . $drow['name'];
                $row['display'] = $host . ' → ' . $value . '（' . $lineName . '）';
                $all[] = $row;
            }
            $total = (int)($result['total'] ?? count($all));
            $page++;
        } while (count($all) < $total && $page <= 10 && !empty($result['list']));

        return $all;
    }

    private function normalizeRecordValue($value)
    {
        if (is_array($value)) {
            $first = $value[0] ?? '';
            if (is_array($first)) {
                return (string)($first['value'] ?? $first['Value'] ?? reset($first) ?: '');
            }
            return (string)$first;
        }
        return (string)$value;
    }

    private function parseRecordRr($name, $domainName)
    {
        $name = trim((string)$name);
        if ($name === '' || $name === '@') {
            return '@';
        }
        $name = rtrim(strtolower($name), '.');
        $domain = rtrim(strtolower($domainName), '.');
        if ($name === $domain) {
            return '@';
        }
        $suffix = '.' . $domain;
        if (strlen($name) > strlen($suffix) && substr($name, -strlen($suffix)) === $suffix) {
            return substr($name, 0, -strlen($suffix));
        }
        return $name;
    }

    public function task()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return View::fetch();
    }

    public function task_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        try {
            $type = input('post.type/d', 1);
            $kw = input('post.kw', null, 'trim');
            $status = input('post.status', null);
            $offset = input('post.offset/d', 0);
            $limit = input('post.limit/d', 15);
            if ($limit <= 0) {
                $limit = 15;
            }
            $sort = input('post.sortName', null, 'trim');
            $orderDir = strtolower(input('post.sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';

            $select = Db::name('aws_sync')->alias('A')->join('domain B', 'A.did = B.id');
            if (!empty($kw)) {
                if ($type == 1) {
                    $select->whereLike('rr|B.name|A.aws_instance_id', '%' . $kw . '%');
                } elseif ($type == 2) {
                    $select->whereLike('remark', '%' . $kw . '%');
                } elseif ($type == 3) {
                    $select->where('A.aws_account_id', $kw);
                }
            }
            if (!isNullOrEmpty($status)) {
                $select->where('A.status', intval($status));
            }
            $total = (clone $select)->count();
            $allowedSort = [
                'id' => 'A.id',
                'rr' => 'A.rr',
                'aws_instance_id' => 'A.aws_instance_id',
                'last_ip' => 'A.last_ip',
                'frequency' => 'A.frequency',
                'active' => 'A.active',
                'checktime' => 'A.checktime',
                'sync_count' => 'A.sync_count',
            ];
            if ($sort && isset($allowedSort[$sort])) {
                $select->order($allowedSort[$sort], $orderDir);
            } else {
                $select->order('A.id', 'desc');
            }
            $list = $select->limit($offset, $limit)->field('A.*,B.name domain')->select()->toArray();
            foreach ($list as &$row) {
                $row['checktimestr'] = $row['checktime'] > 0 ? date('Y-m-d H:i:s', $row['checktime']) : '未运行';
                $row['addtimestr'] = $row['addtime'] > 0 ? date('Y-m-d H:i:s', $row['addtime']) : '';
            }
            return json(['total' => $total, 'rows' => $list]);
        } catch (\Throwable $e) {
            return json(['total' => 0, 'rows' => [], 'code' => -1, 'msg' => '读取AWS同步列表失败：' . $e->getMessage()]);
        }
    }

    public function task_op()
    {
        if (!checkPermission(2)) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        $action = input('param.action');
        if ($action == 'add') {
            $task = $this->buildTaskFromPost();
            if (is_array($task) && isset($task['code'])) {
                return json($task);
            }
            if (Db::name('aws_sync')->where('aws_instance_id', $task['aws_instance_id'])->find()) {
                return json(['code' => -1, 'msg' => '该 EC2 实例 ID 已存在同步任务']);
            }
            $task['addtime'] = time();
            $task['checknexttime'] = time();
            $task['active'] = 1;
            try {
                Db::name('aws_sync')->insert($task);
            } catch (\Throwable $e) {
                $hint = stripos($e->getMessage(), 'aws_sync') !== false
                    ? '请在服务器执行 bash update.sh 创建 aws_sync 表'
                    : '';
                return json(['code' => -1, 'msg' => '保存失败：' . $e->getMessage() . ($hint ? '（' . $hint . '）' : '')]);
            }
            return json(['code' => 0, 'msg' => '添加成功']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $task = $this->buildTaskFromPost();
            if (is_array($task) && isset($task['code'])) {
                return json($task);
            }
            if (Db::name('aws_sync')->where('aws_instance_id', $task['aws_instance_id'])->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '该 EC2 实例 ID 已存在同步任务']);
            }
            Db::name('aws_sync')->where('id', $id)->update($task);
            return json(['code' => 0, 'msg' => '修改成功']);
        } elseif ($action == 'setactive') {
            $id = input('post.id/d');
            $active = input('post.active/d');
            Db::name('aws_sync')->where('id', $id)->update(['active' => $active]);
            return json(['code' => 0, 'msg' => '设置成功']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            Db::name('aws_sync')->where('id', $id)->delete();
            return json(['code' => 0, 'msg' => '删除成功']);
        } elseif ($action == 'run') {
            $id = input('post.id/d');
            $task = Db::name('aws_sync')->where('id', $id)->find();
            if (empty($task)) return json(['code' => -1, 'msg' => '任务不存在']);
            try {
                $result = (new AwsSyncService())->executeOne($task);
                Db::name('aws_sync')->where('id', $id)->update([
                    'status' => 1,
                    'errmsg' => null,
                    'checktime' => time(),
                ]);
                return json(['code' => 0, 'msg' => $result]);
            } catch (Exception $e) {
                Db::name('aws_sync')->where('id', $id)->update([
                    'status' => 2,
                    'errmsg' => mb_substr($e->getMessage(), 0, 480),
                    'checktime' => time(),
                ]);
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'batch') {
            $action2 = input('post.batch_action', null, 'trim');
            $ids = input('post.ids/a', []);
            if (empty($ids)) return json(['code' => -1, 'msg' => '请选择任务']);
            $success = 0;
            if ($action2 == 'open') {
                Db::name('aws_sync')->whereIn('id', $ids)->update(['active' => 1]);
                $success = count($ids);
            } elseif ($action2 == 'close') {
                Db::name('aws_sync')->whereIn('id', $ids)->update(['active' => 0]);
                $success = count($ids);
            } elseif ($action2 == 'delete') {
                Db::name('aws_sync')->whereIn('id', $ids)->delete();
                $success = count($ids);
            } elseif ($action2 == 'run') {
                foreach ($ids as $id) {
                    $task = Db::name('aws_sync')->where('id', $id)->find();
                    if (!$task) continue;
                    try {
                        (new AwsSyncService())->executeOne($task);
                        Db::name('aws_sync')->where('id', $id)->update(['status' => 1, 'errmsg' => null, 'checktime' => time()]);
                        $success++;
                    } catch (Exception $e) {
                        Db::name('aws_sync')->where('id', $id)->update(['status' => 2, 'errmsg' => mb_substr($e->getMessage(), 0, 480), 'checktime' => time()]);
                    }
                }
            } else {
                return json(['code' => -1, 'msg' => '参数错误']);
            }
            return json(['code' => 0, 'msg' => '成功操作 ' . $success . ' 个任务']);
        }
        return json(['code' => -1, 'msg' => '参数错误']);
    }

    public function taskform()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if (!in_array($action, ['add', 'edit'], true)) {
            return $this->alert('error', '无效操作');
        }
        $task = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            try {
                $task = Db::name('aws_sync')->where('id', $id)->find();
            } catch (\Throwable $e) {
                return $this->alert('error', '读取任务失败：' . $e->getMessage());
            }
            if (empty($task)) return $this->alert('error', '任务不存在');
        }

        $domains = [];
        try {
            $domainList = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')
                ->field('A.id,A.name,A.remark,B.type')
                ->order('A.id', 'desc')
                ->select();
            foreach ($domainList as $row) {
                $meta = DnsHelper::resolveTypeMeta($row['type'] ?? null);
                $label = $row['name'] . '（' . $meta['name'] . '）';
                if (!empty($row['remark'])) {
                    $label .= ' - ' . $row['remark'];
                }
                $domains[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'typename' => $meta['name'],
                    'label' => $label,
                ];
            }
        } catch (\Throwable $e) {
            return $this->alert('error', '读取域名列表失败：' . $e->getMessage());
        }
        View::assign('domains', $domains);
        View::assign('info', $task);
        View::assign('infoJson', json_encode($task, JSON_UNESCAPED_UNICODE) ?: 'null');
        View::assign('domainsJson', json_encode($domains, JSON_UNESCAPED_UNICODE) ?: '[]');
        View::assign('action', $action);
        return View::fetch();
    }

    private function buildTaskFromPost()
    {
        $task = [
            'did' => input('post.did/d'),
            'rr' => input('post.rr', null, 'trim'),
            'recordid' => input('post.recordid', null, 'trim'),
            'recordinfo' => input('post.recordinfo', null, 'trim'),
            'aws_account_id' => input('post.aws_account_id', null, 'trim'),
            'aws_region' => input('post.aws_region', null, 'trim'),
            'aws_instance_id' => input('post.aws_instance_id', null, 'trim'),
            'frequency' => input('post.frequency/d', 3),
            'remark' => input('post.remark', null, 'trim'),
        ];

        if (empty($task['did']) || empty($task['rr']) || empty($task['recordid']) || empty($task['recordinfo'])
            || empty($task['aws_account_id']) || empty($task['aws_region']) || empty($task['aws_instance_id'])) {
            return ['code' => -1, 'msg' => '必填项不能为空'];
        }
        if ($task['frequency'] < 1) {
            return ['code' => -1, 'msg' => '同步间隔不能小于 1 分钟'];
        }
        if ($task['frequency'] > 1440) {
            return ['code' => -1, 'msg' => '同步间隔不能大于 1440 分钟'];
        }
        if (!preg_match('/^i-[0-9a-f]{8,20}$/i', $task['aws_instance_id'])) {
            return ['code' => -1, 'msg' => 'EC2 实例 ID 格式不正确（应以 i- 开头）'];
        }
        return $task;
    }
}
