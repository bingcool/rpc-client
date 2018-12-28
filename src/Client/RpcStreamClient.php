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

class RpcStreamClient extends AbstractSocket {

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
            $this->reConnect();
        }else {
            throw new \Exception("Error Creating Stream: missing socket extension, please install it");
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

        $send_result = $this->write($pack_data);
        // 发送成功
        if($send_result) {
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
            $send_result = $this->write($pack_data);
            if($send_result) {
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
        $this->setReadWriteTimeout($timeout);
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
        $header_buff = fread($this->client, (int)$pack_header_length);

        if($header_buff) {
            $client_pack_setting = $this->setUnpackLengthType();
            $header_data = unpack($client_pack_setting, $header_buff);
        }else {
            throw new \Exception("socket_read error: read header_buff return false, mey be timeout!");
        }

        if($header_data) {
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
                $body_buff = fread($this->client, $size);

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
    public function reConnect($times = 1) {
        $address = $this->tcpStreamInitializer();

        $err = '';
        for($i = 0; $i <= $times; $i++) {
            $client = stream_socket_client($address,$errno, $errstr, $this->timeout);
            if($client === false) {
                fclose($client);
                unset($client);
                $err = "Error Creating Stream: errCode= {$errno},errMsg= {$errstr}";
                continue;
            }else {
                if(isset($this->agrs['tcp_nodelay']) && function_exists('socket_import_stream')) {
                    $socket = socket_import_stream($client);
                    socket_set_option($socket, SOL_TCP, TCP_NODELAY, (int) $this->agrs['tcp_nodelay']);
                }

                $this->client = $client;
                $err = '';
                break;
            }
        }

        if($err) {
            throw new \Exception($err);
        }

    }

    /**
     * tcpStreamInitializer  Initializes a TCP stream resource.
     * @param ParametersInterface $parameters Initialization parameters for the connection.
     * @return resource
     */
    protected function tcpStreamInitializer() {
        foreach($this->remote_servers as $k => $servers) {
            list($host, $port) = $servers;
        }

        if(!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $address = "tcp://$host:$port";
        } else {
            $address = "tcp://[$host]:$port";
        }

        $flags = STREAM_CLIENT_CONNECT;

        if(isset($this->args['async_connect']) && $this->args['async_connect']) {
            $flags |= STREAM_CLIENT_ASYNC_CONNECT;
        }

        if(isset($this->args['persistent'])) {
            if(false !== $persistent = filter_var($this->args['persistent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
                $flags |= STREAM_CLIENT_PERSISTENT;
                if ($persistent === null) {
                    $address = "{$address}/{$parameters->persistent}";
                }
            }
        }

        return $address;
    }

    /**
     * setReadWriteTimeout
     * @param $timeout
     */
    protected function setReadWriteTimeout($timeout) {
        if(isset($timeout)) {
            $rwtimeout = (float) $timeout;
            $rwtimeout = $rwtimeout > 0 ? $rwtimeout : -1;
            $timeoutSeconds = floor($rwtimeout);
            $timeoutUSeconds = ($rwtimeout - $timeoutSeconds) * 1000000;
            stream_set_timeout($this->client, $timeoutSeconds, $timeoutUSeconds);
        }
    }

    /**
     * write Performs a write operation over the stream of the buffer containing a
     * command serialized with the Redis wire protocol.
     * @param string $buffer Representation of a command in the Redis wire protocol.
     */
    protected function write($buffer) {
        while (($length = strlen($buffer)) > 0) {
            $written = @fwrite($this->client, $buffer);

            if ($length === $written) {
                return true;
            }

            if ($written === false || $written === 0) {
                throw new \Exception('Error while writing bytes to the server.');
            }

            $buffer = substr($buffer, $written);
        }
        return true;
    }

}