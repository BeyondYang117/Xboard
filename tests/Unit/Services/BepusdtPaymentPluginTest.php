<?php

namespace Tests\Unit\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugin\Bepusdt\Plugin;
use Tests\TestCase;

class BepusdtPaymentPluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('plugins-core/Bepusdt/Plugin.php');
    }

    public function test_payment_creation_uses_configured_network_and_signed_amount(): void
    {
        Http::fake([
            'https://ut.abusz.com/*' => Http::response([
                'status_code' => 200,
                'data' => ['payment_url' => 'https://ut.abusz.com/pay/example'],
            ]),
        ]);

        $plugin = new Plugin('bepusdt');
        $plugin->setConfig([
            'bepusdt_url' => 'https://ut.abusz.com/',
            'bepusdt_token' => 'secret-token',
            'trade_type' => 'usdt.polygon',
            'fiat' => 'CNY',
        ]);

        $result = $plugin->pay([
            'trade_no' => 'ORDER-123',
            'total_amount' => 1234,
            'notify_url' => 'https://merchant.example/notify',
            'return_url' => 'https://merchant.example/order/ORDER-123',
        ]);

        $this->assertSame(['type' => 1, 'data' => 'https://ut.abusz.com/pay/example'], $result);
        Http::assertSent(function (Request $request) {
            $data = $request->data();
            $signature = $data['signature'];
            unset($data['signature']);
            ksort($data, SORT_STRING);
            $pairs = [];
            foreach ($data as $key => $value) {
                $pairs[] = $key . '=' . $value;
            }

            return $request->url() === 'https://ut.abusz.com/api/v1/order/create-transaction'
                && $data['trade_type'] === 'usdt.polygon'
                && $data['amount'] === '12.34'
                && hash_equals(md5(implode('&', $pairs) . 'secret-token'), $signature);
        });
    }

    public function test_callback_requires_valid_signature_and_success_status(): void
    {
        $plugin = new Plugin('bepusdt');
        $plugin->setConfig(['bepusdt_token' => 'secret-token']);
        $params = [
            'order_id' => 'ORDER-123',
            'trade_id' => 'gateway-trade-456',
            'amount' => '12.34',
            'status' => 2,
        ];
        $params['signature'] = $this->sign($params, 'secret-token');

        $this->assertSame([
            'trade_no' => 'ORDER-123',
            'callback_no' => 'gateway-trade-456',
            'custom_result' => 'ok',
        ], $plugin->notify($params));

        $params['status'] = 1;
        $params['signature'] = $this->sign($params, 'secret-token');
        $this->assertFalse($plugin->notify($params));

        $params['status'] = 2;
        $params['signature'] = str_repeat('0', 32);
        $this->assertFalse($plugin->notify($params));
    }

    private function sign(array $params, string $token): string
    {
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $pairs[] = $key . '=' . $value;
            }
        }
        return md5(implode('&', $pairs) . $token);
    }
}
