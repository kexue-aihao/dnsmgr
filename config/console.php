<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'dmtask' => 'app\command\Dmtask',
        'awssynctask' => 'app\command\Awssynctask',
        'certtask' => 'app\command\Certtask',
        'reset' => 'app\command\Reset',
    ],
];
