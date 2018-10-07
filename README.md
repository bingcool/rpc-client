#### swoolefy RpcClient
RpcClient 是为swoolefy框架开发的配套的rpc客户端，可以使用在swoolefy,php-fpm的环境中,而服务端目前只能支持swoolefy的rpc服务。客户端环境需要安装swoole,因为需要使用到swoole的swoole_client。具体可以查看https://www.kancloud.cn/book/bingcoolhuang/php-swoole-swoolefy/edit

#### 安装
```
composer require bingcool/rpc-client
```

#### 注册服务
1、可以在入口文件注册服务
```
            $serviceConfig1 = [
                'servers' => '127.0.0.1:9504',
                'timeout' => 0.5,
                'noblock' => 0
            ];

            $client_setting1 = array(
                'open_length_check'     => 1,
                'package_length_type'   => 'N',
                'package_length_offset' => 0,       //第N个字节是包长度的值
                'package_body_offset'   => 56,       //第几个字节开始计算长度
                'package_max_length'    => 2000000,  //协议最大长度
            );

            $server_header_struct1 = array(
                'length'=>'N', 
                'version'=>'a10', 
                'name'=>'a30', 
                'request_id'=>'a12'
            );

            $client_header_struct1 = array(
                'length'=>'N', 
                'version'=>'a10', 
                'name'=>'a30', 
                'request_id'=>'a12'
            );



            $serviceConfig2 = array(
                'servers' => '127.0.0.1:9506',
                'timeout' => 0.5,
                'noblock' => 0
            );

            
            $client_setting2= array(
                'open_length_check'     => 1,
                'package_length_type'   => 'N',
                'package_length_offset' => 0,       //第N个字节是包长度的值
                'package_body_offset'   => 56,       //第几个字节开始计算长度
                'package_max_length'    => 2000000,  //协议最大长度
            );

            $server_header_struct2 = array(
                'length'=>'N', 
                'version'=>'a10', 
                'name'=>'a30', 
                'request_id'=>'a12'
            );

            $client_header_struct2 = array(
                'length'=>'N', 
                'version'=>'a10', 
                'name'=>'a30', 
                'request_id'=>'a12'
            );

            $heart_header_struct = array(
                'length'=>'N', 
                'version'=>'a10', 
                'name'=>'a30', 
                'request_id'=>'ping'
            );

            // 注册产品服务
            $ser = RpcClientManager::getInstance(true)->registerService('productService', $serviceConfig1, $client_setting1, $server_header_struct1, $client_header_struct1, [
                'swoole_keep' => true
            ]);
            // 注册订单服务 
            RpcClientManager::getInstance(true)->registerService('orderService', $serviceConfig2, $client_setting2, $server_header_struct2, $client_header_struct2, [
                'swoole_keep' => true
            ]);
```

2、在控制器或者其他类中使用
```
public function test4() {
        $callable = ['Service\Coms\Book\BookmanageService', 'test'];
        $params = ['content'=>'test1'];
        $header = ['length'=>'', 'version'=>'1.0.1', 'name'=>'bingcool'];
        $client1 = RpcClientManager::getInstance()->getServices('productService')->buildHeaderRequestId($header)->waitCall($callable, $params);

        $callable = ['Service\Coms\Book\BookmanageService', 'test'];
        $params = ['content'=>'test2'];
        $header = ['length'=>'', 'version'=>'1.0.1', 'name'=>'bingcool'];

        $client2 = RpcClientManager::getInstance()->getServices('productService')->buildHeaderRequestId($header)->waitCall($callable, $params);
        // 并行调用
        $res14 =  RpcClientManager::getInstance()->multiRecv();
        var_dump($res14);

        $callable = ['Service\Coms\Book\BookmanageService', 'test'];
        $params = ['content'=>'test3'];
        $header = ['length'=>'', 'version'=>'1.0.1', 'name'=>'bingcool'];
        $client3 = RpcClientManager::getInstance()->getServices('productService');
        $client3->buildHeaderRequestId($header)->waitCall($callable, $params);
        // 阻塞调用
        $res1 = $client3->waitRecv(20);
        var_dump($client3->code);
        var_dump($client3->getResponsePackHeader());
        var_dump($client3->getResponsePackBody());
        var_dump($res1);
}
```
