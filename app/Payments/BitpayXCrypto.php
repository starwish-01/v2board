<?php

namespace App\Payments;

use \Curl\Curl;

class BitpayXCrypto {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'bitpayx_app_secret' => [
                'label' => 'AppSecret',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'merchant_order_id' => $order['trade_no'],
            'price_amount' => $order['total_amount'] / 100,
            'price_currency' => 'CNY',
            'pay_currency' => 'ALIPAY',
            'title' => '支付单号：' . $order['trade_no'],
            'description' => '充值：' . $order['total_amount'] / 100 . ' 元',
            'callback_url' => $order['notify_url'],
            'success_url' => $order['return_url'],
            'cancel_url' => $order['return_url'],
        ];
        $data_sign = array();
        $data_sign['merchant_order_id'] = $order['trade_no'];
        $data_sign['secret'] = $this->config['bitpayx_app_secret'];
        $data_sign['type'] = 'FIAT';
        ksort($data_sign);
        $strToSign = http_build_query($data_sign);
        $params['token'] = strtolower(md5(md5($strToSign) . $this->config['bitpayx_app_secret']));
        $curl = new Curl();
        $curl->setHeader('content-type', 'application/json');
        $curl->setHeader('token', $this->config['bitpayx_app_secret']);
        $curl->post('https://api.mugglepay.com/v1/orders', json_encode($params));
        $result = $curl->response;
        if (!$result) {
            abort(500, '网络异常');
        }
        if ($curl->error) {
            if (isset($result->error_code)) {
                abort(500, $result->error);
            }
            abort(500, '未知错误');
        }
        $curl->close();
        if (!isset($result->order->order_id)) {
            abort(500, '订单创建失败');
        } else {
            if (!isset($result->invoice) || $result->invoice->pay_currency !== 'USDT') {
                $query = [
                    'order_id' => $result->order->order_id,
                    'pay_currency' => 'USDT'
                ];
                $curl = new Curl();
                $curl->setHeader('content-type', 'application/json');
                $curl->setHeader('token', $this->config['bitpayx_app_secret']);
                $curl->post('https://api.mugglepay.com/v1/orders/' . $result->order->order_id . '/checkout', json_encode($query));
                $curl->close();
            }
        }
        if (!isset($result->payment_url)) {
            abort(500, '未知错误');
        }
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $result->payment_url
        ];
    }

    public function notify($params)
    {
        $data_sign = array();
        $data_sign['merchant_order_id'] = $params['merchant_order_id'];
        $data_sign['secret'] = $this->config['bitpayx_app_secret'];
        $data_sign['type'] = 'FIAT';
        ksort($data_sign);
        $strToSign = http_build_query($data_sign);
        $mySign = strtolower(md5(md5($strToSign) . $this->config['bitpayx_app_secret']));
        if ($mySign !== $params['token']) {
            return false;
        }
        if ($params['status'] !== 'PAID') {
            return false;
        }
        return [
            'response' => json_encode(['status' => 200]),
            'trade_no' => $params['merchant_order_id'],
            'callback_no' => $params['order_id']
        ];
    }
}
