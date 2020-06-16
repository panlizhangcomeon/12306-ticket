<?php
return [
    'SERVER_NAME' => "EasySwoole-Self",
    'MAIN_SERVER' => [
        'LISTEN_ADDRESS' => '0.0.0.0',
        'PORT' => 9507,
        'SERVER_TYPE' => EASYSWOOLE_WEB_SERVER, //可选为 EASYSWOOLE_SERVER  EASYSWOOLE_WEB_SERVER EASYSWOOLE_WEB_SOCKET_SERVER,EASYSWOOLE_REDIS_SERVER
        'SOCK_TYPE' => SWOOLE_TCP,
        'RUN_MODEL' => SWOOLE_PROCESS,
        'SETTING' => [
            'worker_num' => 8,
            'reload_async' => true,
            'max_wait_time'=>3
        ],
        'TASK'=>[
            'workerNum'=>4,
            'maxRunningNum'=>128,
            'timeout'=>15
        ]
    ],
    'TEMP_DIR' => '/tmp/swoole/easyswoole-self',
    'LOG_DIR' => null,
    'mysqli' => [
        'host'          => '192.168.10.18',
        'port'          => 3306,
        'user'          => 'root',
        'password'      => '12345678',
        'database'      => 'ticket',
        'timeout'       => 5,
        'charset'       => 'utf8mb4',
    ]
];
