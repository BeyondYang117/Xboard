<?php

namespace Plugin\Bepusdt;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Http;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const TRADE_TYPES = [
        'usdt.trc20' => 'USDT (TRON / TRC20)',
        'usdt.polygon' => 'USDT (Polygon PoS)',
        'usdt.arbitrum' => 'USDT (Arbitrum One)',
        'usdt.erc20' => 'USDT (Ethereum / ERC20)',
        'usdt.bep20' => 'USDT (BNB Smart Chain / BEP20)',
        'usdt.solana' => 'USDT (Solana)',
        'usdt.aptos' => 'USDT (Aptos)',
        'usdt.xlayer' => 'USDT (X-Layer)',
        'usdt.plasma' => 'USDT (Plasma)',
        'usdt.ton' => 'USDT (TON)',
    ];

    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['BEpusdt'] = [
                    'name' => $this->getConfig('display_name', 'USDT (BEpusdt)'),
                    'icon' => $this->getConfig('icon', '₮'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin',
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'bepusdt_url' => [
                'label' => 'BEpusdt API 地址',
                'type' => 'string',
                'default' => 'https://ut.abusz.com',
                'required' => true,
                'description' => '网关根地址，不要附加 /api/v1/order/create-transaction',
            ],
            'bepusdt_token' => [
                'label' => 'API Token',
                'type' => 'string',
                'required' => true,
                'description' => 'BEpusdt 后台「系统管理 - 基本设置 - API 设置」中的对接令牌',
            ],
            'trade_type' => [
                'label' => 'USDT 网络',
                'type' => 'select',
                'default' => 'usdt.trc20',
                'required' => true,
                'select_options' => self::TRADE_TYPES,
            ],
            'fiat' => [
                'label' => '计价法币',
                'type' => 'select',
                'default' => 'CNY',
                'select_options' => [
                    'CNY' => 'CNY - 人民币',
                    'USD' => 'USD - 美元',
                    'EUR' => 'EUR - 欧元',
                    'GBP' => 'GBP - 英镑',
                    'JPY' => 'JPY - 日元',
                ],
                'description' => '应与 BEpusdt 网关中配置的汇率币种一致',
            ],
        ];
    }

    public function pay($order): array
    {
        $baseUrl = rtrim((string) $this->getConfig('bepusdt_url', ''), '/');
        $token = trim((string) $this->getConfig('bepusdt_token', ''));
        $tradeType = (string) $this->getConfig('trade_type', 'usdt.trc20');
        $fiat = (string) $this->getConfig('fiat', 'CNY');

        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            || $token === '' || !isset(self::TRADE_TYPES[$tradeType])) {
            throw new ApiException('BEpusdt 配置无效');
        }

        $params = [
            'order_id' => (string) $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'redirect_url' => $order['return_url'],
            'amount' => number_format($order['total_amount'] / 100, 2, '.', ''),
            'trade_type' => $tradeType,
            'fiat' => $fiat,
            'name' => (string) $order['trade_no'],
        ];
        $params['signature'] = $this->sign($params, $token);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(15)
                ->withOptions(['allow_redirects' => false])
                ->post($baseUrl . '/api/v1/order/create-transaction', $params);
        } catch (\Throwable $e) {
            throw new ApiException('无法连接 BEpusdt 网关');
        }

        $payload = $response->json();
        $paymentUrl = is_array($payload) ? ($payload['data']['payment_url'] ?? null) : null;
        if (!$response->successful() || !is_array($payload) || ($payload['status_code'] ?? null) != 200
            || !is_string($paymentUrl) || !filter_var($paymentUrl, FILTER_VALIDATE_URL)
            || !in_array(parse_url($paymentUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new ApiException('BEpusdt 创建支付订单失败');
        }

        return [
            'type' => 1,
            'data' => $paymentUrl,
        ];
    }

    public function notify($params): array|bool
    {
        $token = trim((string) $this->getConfig('bepusdt_token', ''));
        if ($token === '' || !is_array($params)) {
            return false;
        }

        $signature = $params['signature'] ?? null;
        if (!is_string($signature) || preg_match('/\A[0-9a-f]{32}\z/D', $signature) !== 1
            || array_filter($params, static fn ($value) => !is_scalar($value) && $value !== null) !== []
            || !hash_equals($this->sign($params, $token), $signature)) {
            return false;
        }

        if ((string) ($params['status'] ?? '') !== '2'
            || !is_scalar($params['order_id'] ?? null) || trim((string) $params['order_id']) === ''
            || !is_scalar($params['trade_id'] ?? null) || trim((string) $params['trade_id']) === '') {
            return false;
        }

        return [
            'trade_no' => (string) $params['order_id'],
            'callback_no' => (string) $params['trade_id'],
            'custom_result' => 'ok',
        ];
    }

    private function sign(array $params, string $token): string
    {
        unset($params['signature']);
        $params = array_filter($params, static fn ($value) => $value !== null && $value !== '');
        foreach ($params as $key => $value) {
            if (!is_scalar($value)) {
                throw new ApiException('BEpusdt 参数格式无效');
            }
            $params[$key] = (string) $value;
        }
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return md5(implode('&', $pairs) . $token);
    }
}
