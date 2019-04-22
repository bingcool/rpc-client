<?php
namespace Rpc\Tests;

use Rpc\Client\RpcClientManager;

class controller {
	public function test() {
		$callable = ['Service\Coms\Book\BookmanageService', 'test'];
		$params = ['content'=>'hhhhhhhhhhhhhhhh'];
		$header = ['length'=>'', 'version'=>'1.0.1', 'name'=>'bingcool'];

		$client1 = RpcClientManager::getInstance()->getServices('productService')->buildHeaderRequestId($header)->waitCall($callable, $params);

		$callable = ['Service\Coms\Book\BookmanageService', 'test'];
		$params = ['content'=>'hhhhhhhhhhhhhhhh'];
		$header = ['length'=>'', 'version'=>'1.0.1', 'name'=>'bingcool'];

		$client2 = RpcClientManager::getInstance()->getServices('productService')->buildHeaderRequestId($header)->waitCall($callable, $params);

		$res =  RpcClientManager::getInstance()->multiRecv([$client1, $client2]);

		var_dump($res);


		$callable = ['Service\Coms\Book\BookmanageService', 'test'];
		$params = ['content'=>'hhhhhhhhhhhhhhhh'];
		$header = ['length'=>'', 'version'=>'1.0.1', 'name'=>'bingcool'];

		$client3 = RpcClientManager::getInstance()->getServices('productService');
		$client3->buildHeaderRequestId($header)->waitCall($callable, $params);
		$res1 = $client3->waitRecv(20);
		var_dump($res1);

		var_dump($client3->code);
		var_dump($client3->getResponsePackHeader());
		var_dump($client3->getResponsePackBody());
	}
}