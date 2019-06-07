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

class RpcTextClient extends RpcStreamClient {

    public function waitCall($callable, $params, array $header = [])
    {
        $this->parseCallable($callable);
        $data = [$callable, $params];

        //记录Rpc开始请求时间
        if($this->isEnableRpcTime()) {
            $this->setStartRpcTime();
        }

        $text = $this->enpackeof($data);
        $send_result = $this->write($text);
        // 发送成功
        if($send_result) {
            $this->setStatusCode(RpcClientConst::ERROR_CODE_SEND_SUCCESS);
            return $this;
        }else {
            $this->disConnect();
            // 重连一次
            $this->reConnect();
            // 重发一次
            $send_result = $this->write($text);
            if($send_result) {
                $this->setStatusCode(RpcClientConst::ERROR_CODE_SECOND_SEND_SUCCESS);
                return $this;
            }else {
                $this->setStatusCode(RpcClientConst::ERROR_CODE_CONNECT_FAIL);
                return $this;
            }
        }
    }

    public function waitRecv(float $timeout = 10, int $size = 8192, int $flags = 0) {
        // 设置读取超时
        $this->setReadWriteTimeout($timeout);
        // 等待获取数据
        
    }

    /**
     * @param int $size
     * @return array
     * @throws \Exception
     */
    public function paresePack(int $size = 8192) {
        
    }

}