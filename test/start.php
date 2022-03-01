#!/usr/bin/env php
<?php
/**
 *
 * Created by PhpStorm.
 * author ZhengYiBin
 * date   2022-03-01 09:13
 */
require_once __DIR__ . '/../vendor/autoload.php';

use Zyb\PhpSocketIo\SocketIOService;
use Workerman\Worker;

$config = [
    //是否启动
    'enable' => true,
    //socket.io端口
    'port' => 2120,
    //socket.io内部监听地址
    'inner_host' => 'http://0.0.0.0:2121',
    //注册事件
    'events' => [
        'todoEvent' => 0,   //0=单播，1=广播
    ],
    //回调事件获取数据地址
    'callback_url' => 'http://172.22.11.46:8099/admin/statement/exportUrl?type=2',
];
$socketIOService = new SocketIOService($config);
Worker::runAll();
