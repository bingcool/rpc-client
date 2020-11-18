<?php
error_reporting(-1);
include "../vendor/autoload.php";

use Rpc\Client\RpcClientManager;

$serviceConfig1 = [
    'host' => '127.0.0.1',
    'port' => '9504',
    'timeout' => 3,
    'noblock' => true,
    'swoole_keep' => false,
    'persistent' => false
];

$clientSetting1 = array(
    'open_length_check'     => 1,
    'package_length_type'   => 'N',
    'package_length_offset' => 0,       //第N个字节是包长度的值
    'package_body_offset'   => 40,       //第几个字节开始计算长度
    'package_max_length'    => 2000000,  //协议最大长度
);

$packetConfig1 = [
    // 服务端设置,客户端需要
    'server' => [
        // 定义包头字段和对应的所占字节大小
        'pack_header_struct' => ['length'=>'N', 'version'=>'a10', 'request_id'=>'a26'],
        'pack_length_key' => 'length',
        'serialize_type' => 'json',
        'pack_check_type' => 'N',
    ],
    // 客户端设置
    'client' => [
        'pack_header_struct' => ['length'=>'N', 'version'=>'a10', 'request_id'=>'a26'],
        'pack_length_key' => 'length',
        'serialize_type' => 'json',
        'pack_check_type' => 'N',
    ]
];

$serviceConfig2 = [
    'host' => '127.0.0.1',
    'port' => '9505',
    'timeout' => 3,
    'noblock' => true
];

$clientSetting2 = array(
    'open_length_check'     => 1,
    'package_length_type'   => 'N',
    'package_length_offset' => 0,       //第N个字节是包长度的值
    'package_body_offset'   => 40,       //第几个字节开始计算长度
    'package_max_length'    => 2000000,  //协议最大长度
);

$packetConfig2 = [
    // 服务端设置,客户端需要
    'server' => [
        // 定义包头字段和对应的所占字节大小
        'pack_header_struct' => ['length'=>'N', 'version'=>'a10', 'request_id'=>'a26'],
        'pack_length_key' => 'length',
        'serialize_type' => 'json',
        'pack_check_type' => 'N',
    ],
    // 客户端设置
    'client' => [
        'pack_header_struct' => ['length'=>'N', 'version'=>'a10', 'request_id'=>'a26'],
        'pack_length_key' => 'length',
        'serialize_type' => 'json',
        'pack_check_type' => 'N',
    ]
];

// 注册产品服务
$ser = RpcClientManager::getInstance(true)->registerService('productService', $serviceConfig1, $clientSetting1, $packetConfig1);
// 注册订单服务 
RpcClientManager::getInstance(true)->registerService('orderService', $serviceConfig2, $clientSetting2, $packetConfig2);

while(true) {
    $obj = new \Rpc\Tests\controller();

    $obj->test();

    sleep(3);
}



