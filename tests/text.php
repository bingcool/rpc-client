<?php
error_reporting(-1);
include "../vendor/autoload.php";

use Rpc\Client\RpcClientManager;

$serviceConfig1 = [
	'servers' => '192.168.99.103:9504',
	'timeout' => 0.5,
	'noblock' => 0
];

$client_setting1 = array(
    // 'package_eof' => "\r\n\r\n", //设置EOF
);