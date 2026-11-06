<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerSubscription;
use App\Services\Subscription\SubscriptionManager;
use App\Services\Subscription\SubscriptionGateway;
use App\Services\Subscription\SubscriptionFetcher;
use App\Services\Subscription\SubscriptionParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function preview(Request $request, SubscriptionFetcher $fetcher, SubscriptionParser $parser)
    {
        $params = $request->validate(['subscription_url' => 'required|string|url:http,https|max:4096']);
        $url = trim($params['subscription_url']);
        $parsed = $parser->parse($fetcher->fetch($url));
        $token = Str::random(64);
        $payload = [
            'admin_id' => $request->user()->id,
            'source' => hash_hmac('sha256', $url, config('app.key')),
            'nodes' => $parsed['nodes'],
        ];
        Cache::put('subscription-preview:' . $token, Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 600);

        return $this->success([
            'preview_token' => $token,
            'expires_in' => 600,
            'nodes' => array_map(fn ($node) => array_intersect_key($node, array_flip(['key', 'name', 'type', 'host', 'port'])), $parsed['nodes']),
            'skipped' => $parsed['skipped'],
            'subscription' => ServerSubscription::where('source', $payload['source'])->first(),
        ]);
    }

    public function import(Request $request)
    {
        $params = $request->validate([
            'preview_token' => 'required|string|size:64|alpha_num:ascii',
            'node_keys' => 'required|array|min:1|max:1000',
            'node_keys.*' => 'required|string|size:64|distinct',
            'group_ids' => 'required|array|min:1',
            'group_ids.*' => 'required|integer|distinct|exists:v2_server_group,id',
            'show' => 'required|boolean',
        ] + SubscriptionManager::rules());
        $cached = Cache::get('subscription-preview:' . $params['preview_token']);
        if (!$cached) {
            throw new ApiException('预览已过期，请重新获取订阅', 422);
        }
        $payload = json_decode(Crypt::decryptString($cached), true, 512, JSON_THROW_ON_ERROR);
        if ($payload['admin_id'] !== $request->user()->id) {
            throw new ApiException('无权使用此预览', 403);
        }
        $request->validate(['node_keys.*' => [Rule::in(array_column($payload['nodes'], 'key'))]]);
        $nodes = array_filter($payload['nodes'], fn ($node) => in_array($node['key'], $params['node_keys'], true));

        $result = Cache::lock('subscription-import:' . $payload['source'], 30)->block(5, function () use ($payload, $nodes, $params) {
            return DB::transaction(function () use ($payload, $nodes, $params) {
                $subscription = ServerSubscription::where('source', $payload['source'])->lockForUpdate()->first();
                if (!$subscription) {
                    $subscription = new ServerSubscription([
                        'source' => $payload['source'], 'name' => '机场 ' . substr($payload['source'], 0, 8),
                        'delivery_mode' => 'relay', 'rate' => 1, 'max_online' => 0,
                        'online_limit_mode' => 'ip', 'gateway_port' => 2443, 'enabled' => true,
                    ]);
                }
                SubscriptionManager::configure($subscription, $params);
                $subscription->last_import_at = time();
                $subscription->save();
                $created = 0;
                $updated = 0;
                foreach ($nodes as $node) {
                    $key = hash('sha256', $payload['source'] . ':' . $node['key']);
                    unset($node['key']);
                    $server = Server::firstOrNew(['external_key' => $key]);
                    $server->exists ? $updated++ : $created++;
                    $server->fill($node + [
                        'external_source' => $payload['source'],
                        'subscription_id' => $subscription->id,
                        'group_ids' => array_map('strval', $params['group_ids']),
                        'show' => (bool) $params['show'],
                        'machine_id' => null,
                        'enabled' => false,
                    ]);
                    if (!$server->exists) {
                        $server->rate = $subscription->rate;
                        $server->tags = ['外部订阅'];
                    }
                    if (array_key_exists('rate', $params)) $server->rate = $subscription->rate;
                    $server->save();
                }
                return compact('created', 'updated') + ['subscription_id' => $subscription->id];
            });
        });
        return $this->success($result);
    }

    public function subscriptions(SubscriptionGateway $gateway)
    {
        return $this->success(ServerSubscription::withCount('nodes')->orderBy('id')->get()->map(function ($subscription) use ($gateway) {
            return $subscription->toArray() + $gateway->online($subscription->id) + [
                'available' => $subscription->isAvailable(),
                'gateway_online' => (bool) Cache::get('subscription-gateway:' . $subscription->id),
            ];
        }));
    }

    public function gatewayConfig()
    {
        return $this->success([
            'host' => config('subscription_gateway.host'),
            'port' => (int) config('subscription_gateway.port', 2443),
            'online' => (bool) Cache::get('subscription-gateway:shared'),
        ]);
    }

    public function saveSubscription(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_subscription,id',
            'group_ids' => 'sometimes|required|array|min:1',
            'group_ids.*' => 'required|integer|distinct|exists:v2_server_group,id',
            'show' => 'sometimes|required|boolean',
            'reset_usage' => 'sometimes|required|boolean',
        ] + SubscriptionManager::rules());
        DB::transaction(function () use ($params) {
            $subscription = ServerSubscription::lockForUpdate()->findOrFail($params['id']);
            SubscriptionManager::configure($subscription, $params);
            if (!empty($params['reset_usage'])) $subscription->update(['u' => 0, 'd' => 0]);
            foreach ($subscription->nodes()->get() as $node) {
                $changes = array_intersect_key($params, array_flip(['group_ids', 'show', 'rate']));
                if (isset($changes['group_ids'])) $changes['group_ids'] = array_map('strval', $changes['group_ids']);
                if ($changes) $node->update($changes);
            }
        });
        return $this->success(true);
    }

    public function deleteSubscription(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|exists:v2_server_subscription,id']);
        $deleted = DB::transaction(function () use ($params) {
            $subscription = ServerSubscription::lockForUpdate()->findOrFail($params['id']);
            $nodes = $subscription->nodes()->get();
            foreach ($nodes as $node) $node->delete();
            $subscription->delete();
            // Retain traffic statistics and session accounting for final flush/retries.
            return $nodes->count();
        });
        return $this->success(['deleted' => $deleted]);
    }
}
