<?php

namespace App\Services\Subscription;

use App\Models\ServerSubscription;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubscriptionManager
{
    public static function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:100',
            'enabled' => 'sometimes|required|boolean',
            'delivery_mode' => ['sometimes', 'required', Rule::in(['direct', 'relay'])],
            'rate' => 'sometimes|required|numeric|min:0.01|max:999999.99',
            'max_online' => 'sometimes|required|integer|min:0|max:1000000',
            'online_limit_mode' => ['sometimes', 'required', Rule::in(['ip', 'user', 'connection'])],
            'gateway_host' => ['sometimes', 'nullable', 'string', 'max:253', function ($attribute, $value, $fail) {
                if ($value && !filter_var($value, FILTER_VALIDATE_IP) && !filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    $fail('中转地址必须是域名或 IP，不包含协议、路径和端口');
                }
            }],
            'gateway_port' => 'sometimes|required|integer|min:1|max:65535',
            'transfer_enable' => 'sometimes|required|integer|min:0|max:9007199254740991',
            'expired_at' => 'sometimes|nullable|integer|min:1|max:4294967295',
        ];
    }

    public static function configure(ServerSubscription $subscription, array $params): void
    {
        $subscription->fill(array_intersect_key($params, static::rules()));
        if (isset($params['group_ids'])) $subscription->group_ids = array_map('strval', $params['group_ids']);
        if ($subscription->delivery_mode === 'relay' && config('subscription_gateway.host')) {
            $subscription->gateway_host = config('subscription_gateway.host');
            $subscription->gateway_port = config('subscription_gateway.port', 2443);
        }
        if ($subscription->delivery_mode === 'direct' && ($subscription->max_online > 0 || $subscription->transfer_enable > 0)) {
            throw ValidationException::withMessages(['delivery_mode' => '在线限制和流量统计需要使用中转计费模式']);
        }
        $subscription->save();
    }
}
