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
     * connect 连接
     * @param  string  $host
     * @param  string  $port
     * @param  float   $tomeout
     * @param  integer $noblock
     * @throws \Exception
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
     * @return  mixed
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
            $this->disConnect();
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
     * @throws   \Exception
     * @return   array
     */
    public function waitRecv(float $timeout = 10, int $size = 8192, int $flags = 0) {
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
        RpcClientManager::getInstance()->destroyBusyClient($this->getClientId());
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
    public function paresePack(int $size = 8192) {
        $pack_header_length = $this->client_pack_setting['package_body_offset'];
        $left_header_length = (int)$pack_header_length;
        $header_recv_buff = fread($this->client, $left_header_length);
        while(($header_recv_length = strlen($header_recv_buff)) > 0) {
            if($header_recv_length < $pack_header_length) {
                $left_header_length = $pack_header_length - $header_recv_length;
                $header_recv_buff .= fread($this->client, $left_header_length);
                continue;
            }
            break;
        }

        $request_id = $this->getRequestId();
        $client_body_buff = '';
        $this->client_body_buff[$request_id] = '';

        if(strlen($header_recv_buff) > $pack_header_length) {
            $header_buff = mb_strcut($header_recv_buff, 0, $pack_header_length, "UTF-8");
            $client_body_buff .= mb_strcut($header_recv_buff, $pack_header_length, null, "UTF-8");
        }else {
            $header_buff = $header_recv_buff;
        }

        if($header_buff) {
            $client_pack_setting = $this->setUnpackLengthType();
            $header_data = unpack($client_pack_setting, $header_buff);
            unset($header_recv_buff);
        }else {
            throw new \Exception("socket_read error: read header_buff return false, mey be timeout!");
        }

        if($header_data) {
            $body_buff_length = (int)$header_data[$this->pack_length_key];
            do{
                $current_recv_length = strlen($client_body_buff);
                if($current_recv_length >= $body_buff_length) {
                    $this->client_body_buff[$request_id] = mb_strcut($client_body_buff, 0, $body_buff_length,"UTF-8");
                    $client_body_buff = '';
                    break;
                }else {
                    if(($body_buff_length - $current_recv_length) < $size) {
                        $buff = fread($this->client, $body_buff_length - $current_recv_length);
                    }else {
                        $buff = fread($this->client, $size);
                    }
                    $client_body_buff .= $buff;
                }
            }while($client_body_buff);

            $body_data = $this->decode($this->client_body_buff[$request_id], $this->client_serialize_type);
            $response = [$header_data, $body_data];

            if($response) {
                unset($this->client_body_buff[$request_id]);
                return $response;
            }
        }

    }

    /**
     * reConnect  最多尝试重连次数，默认尝试重连1次
     * @param   int  $times
     * @throws  \Exception
     * @return  mixed
     */
    public function reConnect(int $times = 1) {
        list($address, $flags) = $this->tcpStreamInitializer();
        if($this->isSwooleEnv() && $this->isPersistent()) {
            if(is_object($this->client)) {
                return;
            }
        }

        $err = '';
        for($i = 0; $i <= $times; $i++) {
            $client = stream_socket_client($address,$errno, $errstr, $this->timeout, $flags);
            if($client === false) {
                unset($client);
                $err = "Error Creating Stream: errCode= {$errno},errMsg= {$errstr}";
                continue;
            }else {
                if(isset($this->args['tcp_nodelay']) && function_exists('socket_import_stream')) {
                    $socket = socket_import_stream($client);
                    // 开启Nagle算法，tcp_nodelay = 0 | 1
                    $tcp_nodelay = $this->args['tcp_nodelay'];
                    if(isset($tcp_nodelay) && is_int($tcp_nodelay)) {
                        socket_set_option($socket, SOL_TCP, TCP_NODELAY, $tcp_nodelay);
                    }
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
     * @return array
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
                    $address = "{$address}/{$this->args['persistent']}";
                }
            }
        }

        return [$address, $flags];
    }

    /**
     * setReadWriteTimeout
     * @param int $timeout
     */
    protected function setReadWriteTimeout(int $timeout = null) {
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
     * @throws \Exception
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