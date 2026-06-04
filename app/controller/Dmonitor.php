<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;
use app\service\BackupPoolService;

class Dmonitor extends BaseController
{
    public function overview()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $switch_count = Db::name('dmlog')->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->count();
        $fail_count = Db::name('dmlog')->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->where('action', 1)->count();

        $run_time = config_get('run_time', null, true);
        $run_state = $run_time ? (time() - strtotime($run_time) > 10 ? 0 : 1) : 0;
        View::assign('info', [
            'run_count' => config_get('run_count', null, true) ?? 0,
            'run_time' => $run_time ?? '无',
            'run_state' => $run_state,
            'run_error' => config_get('run_error', null, true),
            'switch_count' => $switch_count,
            'fail_count' => $fail_count,
            'swoole' => extension_loaded('swoole') ? '<font color="green">已安装</font>' : '<font color="red">未安装</font>',
        ]);
        return View::fetch();
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
        $status = input('post.status', null);
        $kw = input('post.kw', null, 'trim');
        $offset = input('post.offset/d', 0);
        $limit = input('post.limit/d', 15);
        if ($limit <= 0) {
            $limit = 15;
        }
        $sort = input('post.sortName', null, 'trim');
        $orderDir = strtolower(input('post.sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';

        $select = Db::name('dmtask')->alias('A')->join('domain B', 'A.did = B.id');
        if (!empty($kw)) {
            if ($type == 1) {
                $select->whereLike('rr|B.name', '%' . $kw . '%');
            } elseif ($type == 2) {
                $select->where('recordid', $kw);
            } elseif ($type == 3) {
                $select->where('main_value', $kw);
            } elseif ($type == 4) {
                $select->where('backup_value', $kw);
            } elseif ($type == 5) {
                $select->whereLike('remark', '%' . $kw . '%');
            }
        }
        if (!isNullOrEmpty($status)) {
            $select->where('status', intval($status));
        }
        $total = (clone $select)->count();
        $allowedSort = ['id' => 'A.id', 'rr' => 'A.rr', 'main_value' => 'A.main_value', 'type' => 'A.type', 'checktype' => 'A.checktype', 'frequency' => 'A.frequency', 'status' => 'A.status', 'active' => 'A.active', 'checktimestr' => 'A.checktime', 'addtimestr' => 'A.addtime', 'remark' => 'A.remark'];
        if ($sort && isset($allowedSort[$sort])) {
            $select->order($allowedSort[$sort], $orderDir);
        } else {
            $select->order('A.id', 'desc');
        }
        $list = $select->limit($offset, $limit)->field('A.*,B.name domain')->select()->toArray();

        foreach ($list as &$row) {
            $row['addtimestr'] = date('Y-m-d H:i:s', $row['addtime']);
            $row['checktimestr'] = $row['checktime'] > 0 ? date('Y-m-d H:i:s', $row['checktime']) : '未运行';
            try {
                $row['pool_count'] = BackupPoolService::count($row['id']);
            } catch (\Throwable $e) {
                $row['pool_count'] = 0;
            }
        }

        return json(['total' => $total, 'rows' => $list]);
        } catch (\Throwable $e) {
            return json(['total' => 0, 'rows' => [], 'code' => -1, 'msg' => '读取切换策略失败：' . $e->getMessage()]);
        }
    }

    public function task_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if ($action == 'add') {
            $task = [
                'did' => input('post.did/d'),
                'rr' => input('post.rr', null, 'trim'),
                'recordid' => input('post.recordid', null, 'trim'),
                'type' => input('post.type/d'),
                'main_value' => input('post.main_value', null, 'trim'),
                'backup_value' => input('post.backup_value', null, 'trim'),
                'backup_mode' => input('post.backup_mode/d', 0),
                'checktype' => input('post.checktype/d'),
                'checkurl' => input('post.checkurl', null, 'trim'),
                'tcpport' => !empty(input('post.tcpport')) ? input('post.tcpport/d') : null,
                'frequency' => input('post.frequency/d'),
                'cycle' => input('post.cycle/d'),
                'timeout' => input('post.timeout/d'),
                'proxy' => input('post.proxy/d'),
                'cdn' => input('post.cdn') == 'true' || input('post.cdn') == '1' ? 1 : 0,
                'remark' => input('post.remark', null, 'trim'),
                'recordinfo' => input('post.recordinfo', null, 'trim'),
                'addtime' => time(),
                'active' => 1
            ];

            if (empty($task['did']) || empty($task['rr']) || empty($task['recordid']) || empty($task['main_value']) || empty($task['frequency']) || empty($task['cycle'])) {
                return json(['code' => -1, 'msg' => '必填项不能为空']);
            }
            if ($task['checktype'] > 0 && $task['timeout'] > $task['frequency']) {
                return json(['code' => -1, 'msg' => '为保障容灾切换任务正常运行，最大超时时间不能大于检测间隔']);
            }
            $poolIps = BackupPoolService::parseIps(input('post.backup_pool', '', 'trim'));
            if ($task['type'] == 2) {
                $err = $this->validateBackupSwitch($task, $poolIps);
                if ($err) return json(['code' => -1, 'msg' => $err]);
            }
            if (Db::name('dmtask')->where('recordid', $task['recordid'])->find()) {
                return json(['code' => -1, 'msg' => '当前容灾切换策略已存在']);
            }
            $taskId = Db::name('dmtask')->insertGetId($task);
            if ($task['type'] == 2 && $task['backup_mode'] == 1 && !empty($poolIps)) {
                BackupPoolService::addIps($taskId, $poolIps, [$task['main_value']]);
            }
            return json(['code' => 0, 'msg' => '添加成功']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $task = [
                'did' => input('post.did/d'),
                'rr' => input('post.rr', null, 'trim'),
                'recordid' => input('post.recordid', null, 'trim'),
                'type' => input('post.type/d'),
                'main_value' => input('post.main_value', null, 'trim'),
                'backup_value' => input('post.backup_value', null, 'trim'),
                'backup_mode' => input('post.backup_mode/d', 0),
                'checktype' => input('post.checktype/d'),
                'checkurl' => input('post.checkurl', null, 'trim'),
                'tcpport' => !empty(input('post.tcpport')) ? input('post.tcpport/d') : null,
                'frequency' => input('post.frequency/d'),
                'cycle' => input('post.cycle/d'),
                'timeout' => input('post.timeout/d'),
                'proxy' => input('post.proxy/d'),
                'cdn' => input('post.cdn') == 'true' || input('post.cdn') == '1' ? 1 : 0,
                'remark' => input('post.remark', null, 'trim'),
                'recordinfo' => input('post.recordinfo', null, 'trim'),
            ];

            if (empty($task['did']) || empty($task['rr']) || empty($task['recordid']) || empty($task['main_value']) || empty($task['frequency']) || empty($task['cycle'])) {
                return json(['code' => -1, 'msg' => '必填项不能为空']);
            }
            if ($task['checktype'] > 0 && $task['timeout'] > $task['frequency']) {
                return json(['code' => -1, 'msg' => '为保障容灾切换任务正常运行，最大超时时间不能大于检测间隔']);
            }
            $poolIps = BackupPoolService::parseIps(input('post.backup_pool', '', 'trim'));
            if ($task['type'] == 2) {
                $err = $this->validateBackupSwitch($task, $poolIps, $id);
                if ($err) return json(['code' => -1, 'msg' => $err]);
            }
            if (Db::name('dmtask')->where('recordid', $task['recordid'])->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '当前容灾切换策略已存在']);
            }
            Db::name('dmtask')->where('id', $id)->update($task);
            if ($task['type'] == 2 && $task['backup_mode'] == 1 && !empty($poolIps)) {
                BackupPoolService::addIps($id, $poolIps, [$task['main_value']]);
            }
            return json(['code' => 0, 'msg' => '修改成功']);
        } elseif ($action == 'setactive') {
            $id = input('post.id/d');
            $active = input('post.active/d');
            Db::name('dmtask')->where('id', $id)->update(['active' => $active]);
            return json(['code' => 0, 'msg' => '设置成功']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            Db::name('dmtask')->where('id', $id)->delete();
            Db::name('dmlog')->where('taskid', $id)->delete();
            BackupPoolService::deleteByTask($id);
            return json(['code' => 0, 'msg' => '删除成功']);
        } elseif ($action == 'operation') {
            $ids = input('post.ids');
            $success = 0;
            foreach ($ids as $id) {
                if (input('post.act') == 'delete') {
                    Db::name('dmtask')->where('id', $id)->delete();
                    Db::name('dmlog')->where('taskid', $id)->delete();
                    BackupPoolService::deleteByTask($id);
                    $success++;
                } elseif (input('post.act') == 'retry') {
                    Db::name('dmtask')->where('id', $id)->update(['checknexttime' => time()]);
                    $success++;
                } elseif (input('post.act') == 'open' || input('post.act') == 'close') {
                    $isauto = input('post.act') == 'open' ? 1 : 0;
                    Db::name('dmtask')->where('id', $id)->update(['active' => $isauto]);
                    $success++;
                }
            }
            return json(['code' => 0, 'msg' => '成功操作' . $success . '个容灾切换策略']);
        } else {
            return json(['code' => -1, 'msg' => '参数错误']);
        }
    }

    public function taskform()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        $task = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $task = Db::name('dmtask')->where('id', $id)->find();
            if (empty($task)) return $this->alert('error', '切换策略不存在');
            if (!isset($task['backup_mode'])) $task['backup_mode'] = 0;
        }

        $domains = [];
        $domainList = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')->field('A.id,A.name,B.type')->select();
        foreach ($domainList as $row) {
            $domains[] = ['id'=>$row['id'], 'name'=>$row['name'], 'type'=>$row['type']];
        }
        View::assign('domains', $domains);

        View::assign('info', $task);
        View::assign('action', $action);
        View::assign('support_ping', function_exists('exec') ? '1' : '0');
        return View::fetch();
    }

    public function taskinfo()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $id = input('param.id/d');
        $task = Db::name('dmtask')->where('id', $id)->find();
        if (empty($task)) return $this->alert('error', '切换策略不存在');
        if (!isset($task['backup_mode'])) $task['backup_mode'] = 0;

        $switch_count = Db::name('dmlog')->where('taskid', $id)->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->count();
        $fail_count = Db::name('dmlog')->where('taskid', $id)->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->where('action', 1)->count();

        $task['switch_count'] = $switch_count;
        $task['fail_count'] = $fail_count;
        $task['pool_count'] = BackupPoolService::count($id);
        if ($task['type'] == 3) {
            $task['action_name'] = ['未知', '<font color="red">开启解析</font>', '<font color="green">暂停解析</font>'];
        } elseif ($task['type'] == 2) {
            $task['action_name'] = ['未知', '<font color="red">切换备用解析记录</font>', '<font color="green">恢复主解析记录</font>'];
        } else {
            $task['action_name'] = ['未知', '<font color="red">暂停解析</font>', '<font color="green">启用解析</font>'];
        }
        View::assign('info', $task);
        return View::fetch();
    }

    public function tasklog_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $taskid = input('param.id/d');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        $action = input('post.action/d', 0);

        $select = Db::name('dmlog')->where('taskid', $taskid);
        if ($action > 0) {
            $select->where('action', $action);
        }
        $total = $select->count();
        $list = $select->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $list]);
    }

    public function clean()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if ($this->request->isPost()) {
            $days = input('post.days/d');
            if (!$days || $days < 0) return json(['code' => -1, 'msg' => '参数错误']);
            Db::execute("DELETE FROM `" . config('database.connections.mysql.prefix') . "dmlog` WHERE `date`<'" . date("Y-m-d H:i:s", strtotime("-" . $days . " days")) . "'");
            Db::execute("OPTIMIZE TABLE `" . config('database.connections.mysql.prefix') . "dmlog`");
            return json(['code' => 0, 'msg' => '清理成功']);
        }
    }

    public function status()
    {
        $run_time = config_get('run_time', null, true);
        $run_state = $run_time ? (time() - strtotime($run_time) > 10 ? 0 : 1) : 0;
        return $run_state == 1 ? 'ok' : 'error';
    }

    public function pool_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $taskId = input('param.id/d');
        $list = BackupPoolService::list($taskId);
        foreach ($list as &$row) {
            $row['addtimestr'] = date('Y-m-d H:i:s', $row['addtime']);
        }
        return json(['total' => count($list), 'rows' => $list]);
    }

    public function pool_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        $taskId = input('post.task_id/d');
        $task = Db::name('dmtask')->alias('A')->join('domain B', 'A.did = B.id')->where('A.id', $taskId)->field('A.*,B.name domain')->find();
        if (!$task) {
            return json(['code' => -1, 'msg' => '切换策略不存在']);
        }
        if ($action == 'add') {
            $ips = BackupPoolService::parseIps(input('post.ips', '', 'trim'));
            if (empty($ips)) {
                return json(['code' => -1, 'msg' => '请填写有效的IP地址']);
            }
            $added = BackupPoolService::addIps($taskId, $ips, [$task['main_value']]);
            return json(['code' => 0, 'msg' => '成功添加'.$added.'个备用IP', 'added' => $added]);
        } elseif ($action == 'delete') {
            $ip = input('post.ip', null, 'trim');
            if (empty($ip)) {
                return json(['code' => -1, 'msg' => 'IP不能为空']);
            }
            if (!BackupPoolService::deleteIp($taskId, $ip)) {
                return json(['code' => -1, 'msg' => 'IP不存在或已删除']);
            }
            return json(['code' => 0, 'msg' => '删除成功']);
        } elseif ($action == 'clear') {
            BackupPoolService::deleteByTask($taskId);
            return json(['code' => 0, 'msg' => '已清空备用IP池']);
        }
        return json(['code' => -1, 'msg' => '参数错误']);
    }

    public function api_pool_add()
    {
        $task = BackupPoolService::resolveTaskId(input('post.task_id/d'), input('post.domain_id/d'), input('post.rr', null, 'trim'));
        if (!$task) {
            return json(['code' => -1, 'msg' => '容灾切换策略不存在']);
        }
        if (!checkPermission(0, $task['domain'])) {
            return json(['code' => -1, 'msg' => '无权限'])->code(403);
        }
        if ((int)$task['type'] !== 2 || (int)$task['backup_mode'] !== 1) {
            return json(['code' => -1, 'msg' => '该策略未启用备用IP池模式']);
        }
        $ips = BackupPoolService::parseIps(input('post.ips'));
        if (empty($ips) && input('post.ip')) {
            $ips = BackupPoolService::parseIps(input('post.ip', null, 'trim'));
        }
        if (empty($ips)) {
            return json(['code' => -1, 'msg' => '请提供有效的IP地址']);
        }
        $added = BackupPoolService::addIps($task['id'], $ips, [$task['main_value']]);
        return json(['code' => 0, 'msg' => '成功添加'.$added.'个备用IP', 'added' => $added, 'pool_count' => BackupPoolService::count($task['id'])]);
    }

    public function api_pool_list()
    {
        $task = BackupPoolService::resolveTaskId(input('post.task_id/d'), input('post.domain_id/d'), input('post.rr', null, 'trim'));
        if (!$task) {
            return json(['code' => -1, 'msg' => '容灾切换策略不存在']);
        }
        if (!checkPermission(0, $task['domain'])) {
            return json(['code' => -1, 'msg' => '无权限'])->code(403);
        }
        return json(['code' => 0, 'data' => BackupPoolService::list($task['id']), 'pool_count' => BackupPoolService::count($task['id'])]);
    }

    public function api_pool_delete()
    {
        $task = BackupPoolService::resolveTaskId(input('post.task_id/d'), input('post.domain_id/d'), input('post.rr', null, 'trim'));
        if (!$task) {
            return json(['code' => -1, 'msg' => '容灾切换策略不存在']);
        }
        if (!checkPermission(0, $task['domain'])) {
            return json(['code' => -1, 'msg' => '无权限'])->code(403);
        }
        $ip = input('post.ip', null, 'trim');
        if (empty($ip)) {
            return json(['code' => -1, 'msg' => 'IP不能为空']);
        }
        if (!BackupPoolService::deleteIp($task['id'], $ip)) {
            return json(['code' => -1, 'msg' => 'IP不存在或已删除']);
        }
        return json(['code' => 0, 'msg' => '删除成功', 'pool_count' => BackupPoolService::count($task['id'])]);
    }

    private function validateBackupSwitch(array $task, array $poolIps, $taskId = null): ?string
    {
        if ($task['backup_mode'] == 1) {
            $poolCount = $taskId ? BackupPoolService::count($taskId) : 0;
            if (empty($poolIps) && $poolCount == 0 && empty($task['backup_value'])) {
                return '备用IP池模式下请至少添加一个备用IP';
            }
            if (!empty($task['backup_value']) && $task['backup_value'] == $task['main_value']) {
                return '主备地址不能相同';
            }
            foreach ($poolIps as $ip) {
                if ($ip == $task['main_value']) {
                    return '备用IP不能与当前解析IP相同：'.$ip;
                }
            }
            return null;
        }
        if (empty($task['backup_value'])) {
            return '请填写备用解析记录';
        }
        if ($task['backup_value'] == $task['main_value']) {
            return '主备地址不能相同';
        }
        return null;
    }
}
