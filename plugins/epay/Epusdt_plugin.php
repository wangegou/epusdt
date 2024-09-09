<?php


class Epusdt_plugin {
    // 插件的基本信息
    static public $info = [
        'name'        => 'Epusdt',
        'showname'    => 'Epusdt',
        'author'      => 'Epusdt',
        'link'        => 'https://example.com',
        'types'       => ['USDT'],
        'inputs' => [
            'appurl' => [
                'name' => 'API接口地址',
                'type' => 'input',
                'note' => '以http://或https://开头，末尾不要有斜线/',
            ],
            'appid' => [
                'name' => 'API Key',
                'type' => 'input',
                'note' => 'Epusdt API Key',
            ],
        ],
        'select' => null,
        'note' => '',
        'bindwxmp' => false,
        'bindwxa' => false,
    ];

    // 处理提交操作
    static public function submit() {
        global $siteurl, $channel, $order, $sitename;
        
        // 如果订单类型匹配，则返回跳转地址
        if (in_array($order['typename'], self::$info['types'])) {
            return ['type' => 'jump', 'url' => '/pay/Epusdt/' . TRADE_NO . '/?sitename=' . $sitename];
        }
    }

    // 处理 API 请求
    static public function mapi() {
        global $siteurl, $channel, $order;

        // 如果订单类型匹配，则调用 Epusdt 方法
        if (in_array($order['typename'], self::$info['types'])) {
            return self::Epusdt($order['typename']);
        }
    }

    // 获取 API 地址，并去掉末尾的斜杠
    static private function getApiUrl() {
        global $channel;
        $apiurl = $channel['appurl'];
        if (substr($apiurl, -1) == '/') $apiurl = substr($apiurl, 0, -1);
        return $apiurl;
    }

    // 发送请求并记录日志
    static private function sendRequest($url, $param) {
        $url = self::getApiUrl() . $url;
        $post = json_encode($param, JSON_UNESCAPED_UNICODE); // 以JSON格式发送
        self::log('Sending request to URL: ' . $url);
        self::log('Request body: ' . $post);

        $response = get_curl($url, $post, 0, 0, 0, 0, 0, ['Content-Type: application/json']);
        self::log('Response: ' . $response); // 打印响应内容，便于调试

        return json_decode($response, true);
    }

    // 记录日志到 PHP 默认错误日志
    static private function log($message) {
        error_log($message);
    }

    // 生成签名
    static public function Sign($params, $apiKey) {
        if (!empty($params)) {
            ksort($params); // 按照键名进行排序
            $str = '';
            foreach ($params as $k => $val) {
                if ($val !== '') {
                    $str .= $k . '=' . $val . '&';
                }
            }
            $str = rtrim($str, '&') . $apiKey; // 拼接 API Key
            self::log('Signature base string: ' . $str); // 打印待签名字符串
            $sign = strtolower(md5($str)); // 生成小写的 MD5 签名
            self::log('Generated signature: ' . $sign); // 打印生成的签名
            return $sign;
        }
        return null;
    }

    // 创建订单
    static private function CreateOrder() {
        global $siteurl, $channel, $order, $conf;

        $param = [
            'order_id' => TRADE_NO, // 外部订单号
            'amount' => floatval(number_format($order['realmoney'], 2, '.', '')), // 订单实际支付金额，保留两位小数
            'notify_url' => $conf['localurl'] . 'pay/notify/' . TRADE_NO . '/', // 异步通知URL
            'redirect_url' => $siteurl . 'pay/return/' . TRADE_NO . '/', // 订单支付或过期后跳转的URL
        ];

        // 确保所有必填参数有值，并去除空参数
        foreach ($param as $key => $value) {
            if (empty($value)) {
                unset($param[$key]);
            }
        }

        // 生成签名
        $param['signature'] = self::Sign($param, $channel['appid']); // 使用 API Key 作为签名密钥

        // 打印生成的参数和签名
        self::log('Parameters for CreateOrder: ' . print_r($param, true));

        // 调用API创建订单
        $result = self::sendRequest('/api/v1/order/create-transaction', $param);

        if (isset($result["status_code"]) && $result["status_code"] == 200) {
            \lib\Payment::updateOrder(TRADE_NO, $result['data']);
            $code_url = $result['data']['payment_url'];
        } else {
            throw new Exception($result["message"] ? $result["message"] : '返回数据解析失败');
        }
        return $code_url;
    }

    // 处理 Epusdt 订单
    static public function Epusdt() {
        try {
            $code_url = self::CreateOrder();
        } catch (Exception $ex) {
            return ['type' => 'error', 'msg' => 'Epusdt创建订单失败！' . $ex->getMessage()];
        }

        return ['type' => 'jump', 'url' => $code_url];
    }

    // 处理异步回调
    static public function notify() {
        global $channel, $order;

        $resultJson = file_get_contents("php://input");
        $resultArr = json_decode($resultJson, true);
        $Signature = $resultArr["signature"];
        
        // 生成签名时取出 Signature 字段
        unset($resultArr['signature']);
        
        $sign = self::Sign($resultArr, $channel['appid']); // 使用 API Key 生成签名

        if ($sign === $Signature) {
            $out_trade_no = $resultArr['order_id'];

            if ($out_trade_no == TRADE_NO) {
                processNotify($order, $out_trade_no);
            } else {
                return ['type' => 'html', 'data' => 'fail'];
            }
            return ['type' => 'html', 'data' => 'ok'];
        } else {
            return ['type' => 'html', 'data' => 'fail'];
        }
    }

    // 处理同步返回
    static public function return() {
        return ['type' => 'page', 'page' => 'return'];
    }
}
