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

class RpcCoroutineClient extends AbstractSocket {
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
        $this->haveSwoole = extension_loaded('swoole');
        $this->haveSockets = extension_loaded('sockets');

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
        //优先使用swoole扩展
        if($this->haveSwoole) {
            // 创建协程
            $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
            $client->set($this->client_pack_setting);
            $this->client = $client;
            // 重连一次
            $this->reConnect();
            return $client;
        }else {
            return false;
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
        $this->callParams = [$callable, $params, $header];
        if(!$this->client->isConnected()) {
            // swoole_keep的状态下不要关闭
            if($this->is_swoole_env && !$this->swoole_keep) {
                $this->client->close(true);
            }
            $this->reConnect();
        }

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
        $res = $this->client->send($pack_data);
        // 发送成功
        if($res) {
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
            $res = $this->client->send($pack_data);
            if($res) {
                if(isset($header['request_id'])) {
                    $this->request_id = $header['request_id'];
                }else {
                    $header_values = array_values($header);
                    $this->request_id = end($header_values);
                }
                $this->setStatusCode(RpcClientConst::ERROR_CODE_SECOND_SEND_SUCCESS);
                return $this;
            }
            $this->setStatusCode(RpcClientConst::ERROR_CODE_CONNECT_FAIL);
            return $this;
        }
    }

    /**
     * recv 阻塞等待接收数据
     * @param    float $timeout
     * @param    int  $size
     * @param    int  $flags
     * @return   array
     */
    public function waitRecv($timeout = 5, $size = 2048, $flags = 0) {
        if($this->client instanceof \Swoole\Coroutine\Client) {
            $data = $this->client->recv($timeout);
        }
        //记录Rpc结束请求时间
        if($this->isEnableRpcTime()) {
            $this->setEndRpcTime();
        }
        // client获取数据完成后，释放工作的client_services的实例
        RpcClientManager::getInstance()->destroyBusyClient();
        $request_id = $this->getRequestId();
        if(isset($data)) {
            if($this->is_pack_length_type) {
                $response = $this->depack($data);
                list($header, $body_data) = $response;
                if(in_array($request_id, array_values($header)) || $this->getRequestId() == 'ping') {
                    $this->setStatusCode(RpcClientConst::ERROR_CODE_SUCCESS);
                    $this->response_pack_data[$request_id] = $response;
                    return $response;
                }
                $this->setStatusCode(RpcClientConst::ERROR_CODE_NO_MATCH);
                $this->client->close(true);
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
     * reConnect  最多尝试重连次数，默认尝试重连1次
     * @param   int  $times
     * @return  void
     */
    public function reConnect($times = 1) {
        foreach($this->remote_servers as $k=>$servers) {
            list($host, $port) = $servers;
        }
        $err = '';
        // 尝试重连一次
        for($i = 0; $i <= $times; $i++) {
            $ret = $this->client->connect($host, $port, $this->timeout, 0);
            if($ret === false) {
                //强制关闭，重连
                $this->client->close(true);
                $err = "Error Connecting Socket: " . socket_strerror($this->client->errCode);
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