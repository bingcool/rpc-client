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

class RpcClientManager {

    /**
     * $instance 单例实例
     * @var [type]
     */
    protected static $instance;

    /**
     * $client_pack_setting client的包设置
     * @var array
     */
    protected static $client_pack_setting = [];

    /**
     * $client_services 客户端所有注册的服务实例
     * @var array
     */
    protected static $client_services = [];

    /**
     * $busy_client_services 正在工作的服务实例
     * @var array
     */
    protected static $busy_client_services = [];

    /**
     * 长连接服务实例
     * @var array
     */
    protected static $persistent_client_services = [];

    /**
     * $is_swoole_env 是在swoole环境中使用，或者在apache|php-fpm中使用
     * @var boolean
     */
    protected $is_swoole_env = false;

    /**
     * @var array
     */
    protected $response_pack_data = [];

    /**
     * __construct
     * @param  array $setting
     */
    protected function __construct(bool $is_swoole_env = false) {
        $this->is_swoole_env = $is_swoole_env;
    }

    /**
     * getInstance  创建单例实例
     * @param  mixed $args
     * @return mixed
     */
    public static function getInstance(...$args) {
        if(!isset(self::$instance)){
            self::$instance = new static(...$args);
        }
        return self::$instance;
    }

    /**
     * registerService 注册服务
     * @param    string    $serviceName
     * @param    array     $serviceConfig
     * @param    array     $setting
     * @param    array     $header_struct
     * @param    string    $pack_length_key
     * @return   object
     */
    public function registerService(
        string $serviceName,
        array $serviceConfig = [],
        array $client_pack_setting = [],
        array $server_header_struct = [],
        array $client_header_struct = [],
        array $args = []
    ) {
        $servers = $serviceConfig['servers'];
        $timeout = $serviceConfig['timeout'];
        $noblock = isset($serviceConfig['noblock']) ? $serviceConfig['noblock'] : 0;
        $server_serialize_type = isset($serviceConfig['serialize_type']) ? $serviceConfig['serialize_type'] : 'json';
        $key = md5($serviceName);

        if(!isset(self::$client_services[$key])) {
            self::$client_pack_setting[$key] = $client_pack_setting;
            $pack_length_key = isset($args['pack_length_key']) ? $args['pack_length_key'] : 'length';
            $client_serialize_type = isset($args['client_serialize_type']) ? $args['client_serialize_type'] : 'json';
            $swoole_keep = false;
            $persistent = false;

            if(isset($args['swoole_keep'])) {
                $swoole_keep = filter_var($args['persistent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $swoole_keep = (boolean)$swoole_keep;
            }
            if(isset($args['persistent'])) {
                $persistent = filter_var($args['persistent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $persistent = (boolean)$persistent;
            }
            $client_service = new RpcStreamClient($client_pack_setting, $server_header_struct, $client_header_struct, $pack_length_key);
            $client_service->addServer($servers, $timeout, $noblock);
            $client_service->setClientServiceName($serviceName);
            $client_service->setClientSerializeType($client_serialize_type);
            $client_service->setServerSerializeType($server_serialize_type);
            $client_service->setSwooleKeep($swoole_keep);
            $client_service->setSwooleEnv($this->is_swoole_env);
            $client_service->setPersistent($persistent);
            $client_service->setArgs($args);
            self::$client_services[$key] = serialize($client_service);
        }
        return $this;
    }

    /**
     * getService 获取某个服务实例|所有正在工作的服务
     * @param    String   $serviceName
     * @return   object|array
     */
    public function getServices(string $serviceName = '', bool $persistent = false) {
        if($serviceName) {
            $key = md5($serviceName);
            // 是否存在长连接
            if($persistent) {
                if(isset(self::$persistent_client_services[$key])) {
                    return self::$persistent_client_services[$key];
                }
            }
            if(isset(self::$client_services[$key])) {
                $client_service = unserialize(self::$client_services[$key]);
                $client_service->connect();
                $us = strstr(microtime(), ' ', true);
                $client_id = intval(strval($us * 1000 * 1000) . $this->string(12));
                $client_service->setClientId($client_id);
                if(!$persistent) {
                    if(!isset(self::$busy_client_services[$client_id])) {
                        self::$busy_client_services[$client_id] = $client_service;
                    }
                }else {
                    self::$persistent_client_services[$key] = $client_service;
                }
                return $client_service;
            }
        }
        return self::$busy_client_services;
    }


    /**
     * getSwooleClient 获取swoole_client实例
     * @param    string   $serviceName
     * @return   mixed
     */
    public function getSwooleClient(AbstractSocket $client_service) {
        return $client_service->getSocketClient();
    }

    /**
     * @param string $serviceName
     * @param string $persistent_client_name //强制产生一个新的长连接实例，默认获取心跳长连接实例
     */
    public function getPersistentServices(string $serviceName = '', string $persistent_client_name = null) {
        $client_key = md5($serviceName.$persistent_client_name);
        if(isset(self::$persistent_client_services[$client_key])) {
            return self::$persistent_client_services[$client_key];
        }
        //长连接，则该client_service强制长连接
        $client_service = $this->getServices($serviceName.$persistent_client_name, true);
        $client_service->setPersistent(true);
        $args = array_merge($client_service->getArgs(), ['persistent' => true]);
        $client_service->setArgs($args);
        if(!isset(self::$persistent_client_services[$client_key])) {
            self::$persistent_client_services[$client_key] = $client_service;
        }
        $this->destroyBusyClient($client_service->getClientId());
        return $client_service;
    }

    /**
     * multiRecv 规定时间内并行接受多个I/O的返回数据（需要swoole扩展支持）
     * @param    int   $timeout
     * @param    int   $size
     * @param    int   $flags
     * @throws   \Exception
     * @return   array
     */
    public function multiRecv(array $client_services = [], int $timeout = 30, int $size = 2048, int $flags = 0) {
        if(!$client_services) {
            throw new \Exception("client_services params must be setted client", 1);
        }
        $start_time = time();
        $group_multi_id = $this->createGroupMultiId($client_services);
        $this->response_pack_data[$group_multi_id] = [];
        if(extension_loaded('swoole') && function_exists('defer') && defined('SWOOLEFY_VERSION') && class_exists('Swoolefy\\MPHP')) {
            if(\co::getCid() > 0) {
                defer(function() use($group_multi_id) {
                    if(isset($this->response_pack_data[$group_multi_id])) {
                        unset($this->response_pack_data[$group_multi_id]);
                    }
                });
            }
        }
        while(!empty($client_services)) {
            $read = $write = $error = $client_ids = [];
            foreach($client_services as $client_id=>$client_service) {
                $read[] = $client_service->getSocketClient();
                $client_ids[] = $client_id;
                $client_service->setRecvWay(RpcClientConst::MULTI_RECV);
                $client_service->setGroupMultiId($group_multi_id);
            }
            $ret = stream_select($read, $write, $error, 0.50);
            if($ret) {
                foreach($read as $k=>$socket) {
                    $client_id = $client_ids[$k];
                    $client_service = $client_services[$client_id];

                    //记录Rpc结束请求时间,这里由于是并行的，可能时间并不会很准确的
                    if($client_service->isEnableRpcTime()) {
                        $client_service->setEndRpcTime();
                    }

                    if($client_service->isPackLengthCheck()) {
                        $response = $client_service->paresePack($size);
                        list($header, $body_data) = $response;
                        $request_id = $client_service->getRequestId();
                        if(in_array($request_id, array_values($header))) {
                            $client_service->setStatusCode(RpcClientConst::ERROR_CODE_SUCCESS);
                            $this->response_pack_data[$group_multi_id][$request_id] = $response;
                        }else {
                            $client_service->setStatusCode(RpcClientConst::ERROR_CODE_NO_MATCH);
                            $this->response_pack_data[$group_multi_id][$request_id] = [];
                        }
                    }else {
                        // eof分包时
                        $serviceName = $client_service->getClientServiceName();
                        $unseralize_type = $client_service->getClientSerializeType();
                        //$this->response_pack_data[$serviceName] = $client_service->depackeof($data, $unseralize_type);
                    }

                    unset($client_services[$client_id]);
                }
            }

            $end_time = time();
            if(($end_time - $start_time) > $timeout) {
                // 超时的client_service实例
                foreach($client_services as $client_id=>$timeout_client_service) {
                    $request_id = $timeout_client_service->getRequestId();
                    $timeout_client_service->setStatusCode(RpcClientConst::ERROR_CODE_CALL_TIMEOUT);
                    $this->response_pack_data[$group_multi_id][$request_id] = [];
                    unset($client_services[$client_id]);
                }
                break;
            }
        }
        // client获取数据完成后，释放工作的client_services的实例
        foreach ($client_ids as $k => $client_id) {
            $this->destroyBusyClient($client_id);
        }
        return $this->response_pack_data[$group_multi_id];
    }

    /**
     * 
     * @param array $client_services
     */ 
    protected function createGroupMultiId($client_services = []) {
        $group_multi_id = '';
        foreach($client_services as $client_service) {
            $group_multi_id .= $client_service->getClientId();
        }
        return md5($group_multi_id);
    }

    /**
     * @param string $group_multi_id
     */
    public function destroyClientServicePackData(string $group_multi_id = null) {
        if($group_multi_id) {
            if(isset($this->response_pack_data[$group_multi_id])) {
                unset($this->response_pack_data[$group_multi_id]);
            }
        }
    }

    /**
     * getAllResponseData 获取所有调用请求的swoole_client_select的I/O响应包数据
     * @throws   \Exception
     * @return   array
     */
    public function getAllResponsePackData($group_multi_id = null) {
        if(is_array($group_multi_id) && !empty($group_multi_id)) {
            $client_service = $group_multi_id[0];
            if($client_service instanceof AbstractSocket) {
                $group_multi_id = $this->createGroupMultiId($group_multi_id);
            }else {
                throw new \Exception("client_service must instanceof AbstractSocket class");
            }
        }

        if(is_string($group_multi_id) && isset($this->response_pack_data[$group_multi_id])) {
            return $this->response_pack_data[$group_multi_id];
        }
        return $this->response_pack_data;
    }

    /**
     * getResponsePackData 获取某个服务请求服务返回的数据
     * @param   object  $client_service
     * @return  array
     */
    public function getResponsePackData(AbstractSocket $client_service) {
        return $client_service->getResponsePackData();
    }

    /**
     * getResponseBody 获取服务响应的包体数据
     * @param   object  $client_service
     * @return  array
     */
    public function getResponsePackBody(AbstractSocket $client_service) {
        return $client_service->getResponsePackBody();
    }

    /**
     * getResponseBody 获取服务响应的包头数据
     * @param   object  $client_service
     * @return  array
     */
    public function getResponsePackHeader(AbstractSocket $client_service) {
        return $client_service->getResponsePackHeader();
    }

    /**
     * destroyBusyClient client获取数据完成后，清空这些实例对象，实际上对象并没有真正销毁，因为在业务中还返回给一个变量引用着，只是清空self::$busy_client_services数组
     * eg $client = RpcClientManager::getInstance()->getServices('productService'); $client这个变量引用实例
     * @return  void
     */
    public function destroyBusyClient($client_id = null) {
        if($client_id) {
            if(isset(self::$busy_client_services[$client_id])) {
                unset(self::$busy_client_services[$client_id]);
            }
        }else {
            self::$busy_client_services = [];
        }
    }

    /**
     * getSetting 通过服务名获取客户端服务配置
     * @param    string   $serviceName
     * @return   array
     */
    public function getClientPackSetting(string $serviceName) {
        $key = md5($serviceName);
        $client_service = self::$client_pack_setting[$key];
        if(is_object($client_service)) {
            return $client_service->getClientPackSetting();
        }
        return null;
    }

    /**
     * string 随机生成一个字符串
     * @param   int  $length
     * @param   bool  $number 只添加数字
     * @param   array  $ignore 忽略某些字符串
     * @return string
     */
    public function string($length = 12, $number = true, $ignore = []) {
        $strings = 'ABCDEFGHIJKLOMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        if($ignore && is_array($ignore)) {
            $strings = str_replace($ignore, '', $strings);
            $numbers = str_replace($ignore, '', $numbers);
        }
        $pattern = $strings . $numbers;
        $max = strlen($pattern) - 1;
        $key = '';
        for($i = 0; $i < $length; $i++) {
            $key .= $pattern[mt_rand(0, $max)];
        }
        return $key;
    }

    /**
     * buildHeader  重建header的数据，产生一个唯一的请求串号id
     * @param    array   $header_data
     * @param    string  $request_id_key
     * @param    string  $length     默认12字节
     * @throws   \Exception
     * @return   array
     */
    public function buildHeaderRequestId(array $header_data, string $request_id_key = 'request_id', int $length = 26) {
        if($length < 26 || $length > 32 ) {
            throw new \Exception("parmams length only in [26, 32]");
        }
        $time = time();
        $time_str = date("YmdHis", $time);
        $key = $this->string(12);
        $request_id = (string)$time.$key;
        $request_id = $time_str.substr(md5($request_id), 0, $length - mb_strlen($time_str,'UTF8'));
        $header = array_merge($header_data, [$request_id_key => $request_id]);
        return $header;
    }

}