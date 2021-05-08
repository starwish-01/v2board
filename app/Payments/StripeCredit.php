<?php

/**
 * 自己写别抄，抄NMB抄
 */
namespace App\Payments;

use Stripe\Source;
use Stripe\Stripe;

class StripeCredit {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'currency' => [
                'label' => '货币单位',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_sk_live' => [
                'label' => 'SK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_pk_live' => [
                'label' => 'PK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => 'WebHook密钥签名',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        info($order);
        $currency = $this->config['currency'];
        $exchange = $this->exchange('CNY', strtoupper($currency));
        if (!$exchange) {
            abort(500, __('user.order.stripeCard.currency_convert_timeout'));
        }
        Stripe::setApiKey($this->config['stripe_sk_live']);
        try {
            $charge = \Stripe\Charge::create([
                'amount' => floor($order['total_amount'] * $exchange),
                'currency' => $currency,
                'source' => $order['stripe_token'],
                'metadata' => [
                    'user_id' => $order['user_id'],
                    'out_trade_no' => $order['trade_no'],
                    'identifier' => ''
                ]
            ]);
        } catch (\Exception $e) {
            info($e);
            abort(500, __('user.order.stripeCard.was_problem'));
        }
        if (!$charge->paid) {
            abort(500, __('user.order.stripeCard.deduction_failed'));
        }
        return [
            'type' => 2,
            'data' => $charge->paid
        ];
    }

    public function notify(Request $request)
    {
        $params = $request->input();
        \Stripe\Stripe::setApiKey($this->config['stripe_sk_live']);
        try {
            $event = \Stripe\Webhook::constructEvent(
                file_get_contents('php://input'),
                $_SERVER['HTTP_STRIPE_SIGNATURE'],
                $this->config['stripe_webhook_key']
            );
        } catch (\Stripe\Error\SignatureVerification $e) {
            abort(400);
        }
        switch ($event->type) {
            case 'source.chargeable':
                $object = $event->data->object;
                \Stripe\Charge::create([
                    'amount' => $object->amount,
                    'currency' => $object->currency,
                    'source' => $object->id,
                    'metadata' => json_decode($object->metadata, true)
                ]);
                break;
            case 'charge.succeeded':
                $object = $event->data->object;
                if ($object->status === 'succeeded') {
                    $metaData = isset($object->metadata->out_trade_no) ? $object->metadata : $object->source->metadata;
                    $tradeNo = $metaData->out_trade_no;
                    return [
                        'response' => 'success',
                        'trade_no' => $tradeNo,
                        'callback_no' => $object->balance_transaction
                    ];
                }
                break;
            default:
                abort(500, 'event is not support');
        }
    }

    private function exchange($from, $to)
    {
        $result = file_get_contents('https://api.exchangerate.host/latest?symbols=' . $to . '&base=' . $from);
        $result = json_decode($result, true);
        return $result['rates'][$to];
    }
}
