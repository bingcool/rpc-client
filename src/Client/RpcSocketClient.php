<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
 */

namespace Rpc\Client;

class RpcSocketClient extends AbstractSocket {

    /**
     * __construct 初始化
     * @param array $setting
     */
    public function __construct(
        array $setting = [],
        array $server_header_struct = [],
        array $client_header_struct = [],
        string $pack_length_key = 'length'
    ) {
        $this->client_pack_setting = array_merge($this->client_pack_setting, $setting);
        $this->server_header_struct = array_merge($this->server_header_struct, $server_header_struct);
        $this->client_header_struct = $client_header_struct;
        $this->pack_length_key = $pack_length_key;

        if(isset($this->client_pack_setting['open_length_check']) && isset($this->client_pack_setting['package_length_type'])) {
            $this->is_pack_length_type = true;
        }else {
            // 使用eof方式分包
            $this->is_pack_length_type = false;
            $this->pack_eof = $this->client_pack_setting['package_eof'];
        }
    }

    /**
     * connect 连接
     * @param  syting  $host
     * @param  string  $port
     * @param  float   $tomeout
     * @param  integer $noblock
     * @return mixed
     */
    public function connect($host = null, $port = null , $timeout = 0.5, $noblock = 0) {
        if(!empty($host) && !empty($port)) {
            $this->remote_servers[] = [$host, $port];
            $this->timeout = $timeout;
        }
        if(extension_loaded('sockets')) {
            $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if(!$client) {
                throw new \Exception("Error Creating Socket: ".socket_strerror(socket_last_error()));
            }
            $this->client = $client;
            $this->reConnect();
        }else {
            throw new \Exception("Error Creating Socket: missing socket extension, please install it");
        }
    }

    /**
     * send 数据发送
     * @param   string   $callable
     * @param   mixed    $params数据序列化模式
     * @param   array    $header  数据包头数据，如果要传入该参数，则必须是由buildHeaderRequestId()函数产生返回的数据
     * @return  boolean
     */
    public function waitCall($callable, $params, array $header = []) {
        $this->parseCallable($callable);
        $data = [$callable, $params];
        if(empty($header)) {
            $header = $this->request_header;
        }
        //记录Rpc开始请求时间
        if($this->isEnableRpcTime()) {
            $this->setStartRpcTime();
        }
        // 封装包
        $pack_data = $this->enpack($data, $header, $this->server_header_struct, $this->pack_length_key, $this->server_serialize_type);
        $length = strlen($pack_data);
        $send_lenth = socket_write($this->client, $pack_data, $length);
        // 发送成功
        if($send_lenth == $length) {
            if(isset($header['request_id'])) {
                $this->request_id = $header['request_id'];
            }else {
                $header_values = array_values($header);
                $this->request_id = end($header_values);
            }
            $this->setStatusCode(RpcClientConst::ERROR_CODE_SEND_SUCCESS);
            return $this;
        }else {
            // 重连一次
            $this->reConnect();
            // 重发一次
            $send_lenth = socket_write($this->client, $pack_data, $length);
            if($send_lenth == $length) {
                if(isset($header['request_id'])) {
                    $this->request_id = $header['request_id'];
                }else {
                    $header_values = array_values($header);
                    $this->request_id = end($header_values);
                }
                $this->setStatusCode(RpcClientConst::ERROR_CODE_SECOND_SEND_SUCCESS);
                return $this;
            }else {
                $this->setStatusCode(RpcClientConst::ERROR_CODE_CONNECT_FAIL);
                return $this;
            }
        }
    }

    /**
     * recv 阻塞等待接收数据
     * @param    float $timeout
     * @param    int  $size
     * @param    int  $flags
     * @return   array
     */
    public function waitRecv($timeout = 15, $size = 2048, $flags = 0) {
        if($size > 8192) {
            throw new \Exception( 'params $size must less than 8192');
        }
        // 设置读取超时
        socket_set_option($this->client,SOL_SOCKET,SO_RCVTIMEO, array("sec"=> $timeout, "usec"=> 0));
        socket_set_block($this->client);
        // 等待获取数据
        $data = $this->paresePack($size);
        //记录Rpc结束请求时间
        if($this->isEnableRpcTime()) {
            $this->setEndRpcTime();
        }
        // client获取数据完成后，释放工作的client_services的实例
        RpcClientManager::getInstance()->destroyBusyClient();
        $request_id = $this->getRequestId();
        if(isset($data)) {
            if($this->is_pack_length_type) {
                list($header, $body_data) = $data;
                if(in_array($request_id, array_values($header)) || $this->getRequestId() == 'ping') {
                    $this->setStatusCode(RpcClientConst::ERROR_CODE_SUCCESS);
                    $this->response_pack_data[$request_id] = $data;
                    return $data;
                }
                $this->setStatusCode(RpcClientConst::ERROR_CODE_NO_MATCH);
                return [];

            }else {
                $unseralize_type = $this->client_serialize_type;
                return $this->depackeof($data, $unseralize_type);
            }
        }
        $this->setStatusCode(RpcClientConst::ERROR_CODE_CALL_TIMEOUT);
        return [];
    }

    /**
     * @param int $size
     * @return array
     * @throws \Exception
     */
    private function paresePack($size = 2048) {
        $pack_header_length = $this->client_pack_setting['package_body_offset'];
        $header_buff = socket_read($this->client, (int)$pack_header_length);
        if($header_buff) {
            $client_pack_setting = $this->setUnpackLengthType();
            $header_data = unpack($client_pack_setting, $header_buff);
        }else {
            throw new \Exception("socket_read error: read header_buff return false, mey be timeout!");
        }

        if($header_data) {
            // 包头包含的包体长度值
            $buff_length = $header_data[$this->pack_length_key];
            $request_id = $this->getRequestId();
            $this->client_body_buff[$request_id] = '';
            $i = ceil($buff_length / $size);
            do {
                if($i == 1) {
                    $size = $buff_length;
                }else {
                    $buff_length = $buff_length - $size;
                }
                $body_buff = socket_read($this->client, $size);

                if(strlen($body_buff) != $size) {
                    break;
                }
                $this->client_body_buff[$request_id] .= $body_buff;
                if($i == 1) {
                    $body_data = $this->decode($this->client_body_buff[$request_id], $this->client_serialize_type);
                    $response = [$header_data, $body_data];
                    break;
                }
            }while(--$i);

            if($response) {
                unset($this->client_body_buff[$request_id]);
                return $response;
            }
        }

    }

    /**
     * reConnect  最多尝试重连次数，默认尝试重连1次
     * @param   int  $times
     * @return  void
     */
    public function reConnect($times = 1)
    {
        foreach($this->remote_servers as $k => $servers) {
            list($host, $port) = $servers;
        }
        $err = '';
        // 尝试重连一次
        for($i = 0; $i <= $times; $i++) {
            $ret = socket_connect($this->client, $host, $port);
            if($ret === false) {
                //强制关闭，重连
                socket_close($this->client);
                $err = "Error Connecting Socket: " . socket_strerror(socket_last_error());
                continue;
            }else {
                $err = '';
                break;
            }
        }

        if($err) {
            throw new \Exception($err);
        }

    }

}