<?php

declare(strict_types=1);

namespace app\command;

use Exception;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Config;
use app\service\AwsSyncService;

class Awssynctask extends Command
{
    protected function configure()
    {
        $this->setName('awssynctask')
            ->setDescription('AWS IP 同步检测任务（秒级轮询）');
    }

    protected function execute(Input $input, Output $output)
    {
        $res = Db::name('config')->cache('configs', 0)->column('value', 'key');
        Config::set($res, 'sys');

        config_set('aws_sync_error', '');
        $output->writeln('AWS IP 同步进程启动成功.');

        while (true) {
            sleep(1);
            try {
                (new AwsSyncService())->execute();
                config_set('aws_sync_run_time', date('Y-m-d H:i:s'));
            } catch (Exception $e) {
                config_set('aws_sync_error', $e->getMessage());
                $output->writeln('[Error] ' . $e->getMessage());
            }
        }
    }
}
