<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Utils\Dict;
use Illuminate\Http\Request;

class CommController extends Controller
{
    public function config()
    {
        $data = [
            'is_telegram' => (int)admin_setting('telegram_bot_enable', 0),
            'telegram_discuss_link' => admin_setting('telegram_discuss_link'),
            'stripe_pk' => admin_setting('stripe_pk_live'),
            'withdraw_methods' => admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT),
            'withdraw_close' => (int)admin_setting('withdraw_close_enable', 0),
            'currency' => admin_setting('currency', 'CNY'),
            'currency_symbol' => admin_setting('currency_symbol', '¥'),
            'reset_traffic_method' => (int)admin_setting('reset_traffic_method', 1),
            'windows_version' => admin_setting('windows_version', ''),
            'windows_download_url' => admin_setting('windows_download_url', ''),
            'macos_version' => admin_setting('macos_version', ''),
            'macos_download_url' => admin_setting('macos_download_url', ''),
            'android_version' => admin_setting('android_version', ''),
            'android_download_url' => admin_setting('android_download_url', ''),
            'commission_distribution_enable' => (int)admin_setting('commission_distribution_enable', 0),
            'commission_distribution_l1' => admin_setting('commission_distribution_l1'),
            'commission_distribution_l2' => admin_setting('commission_distribution_l2'),
            'commission_distribution_l3' => admin_setting('commission_distribution_l3')
        ];
        return $this->success($data);
    }

    public function getStripePublicKey(Request $request)
    {
        $payment = Payment::where('id', $request->input('id'))
            ->where('payment', 'StripeCredit')
            ->first();
        if (!$payment) throw new ApiException('payment is not found');
        return $this->success($payment->config['stripe_pk_live']);
    }
}
