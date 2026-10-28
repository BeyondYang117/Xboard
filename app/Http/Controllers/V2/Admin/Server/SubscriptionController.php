<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
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
        ]);
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
                $created = 0;
                $updated = 0;
                foreach ($nodes as $node) {
                    $key = hash('sha256', $payload['source'] . ':' . $node['key']);
                    unset($node['key']);
                    $server = Server::firstOrNew(['external_key' => $key]);
                    $server->exists ? $updated++ : $created++;
                    $server->fill($node + [
                        'external_source' => $payload['source'],
                        'group_ids' => array_map('strval', $params['group_ids']),
                        'show' => (bool) $params['show'],
                        'machine_id' => null,
                        'enabled' => false,
                    ]);
                    if (!$server->exists) {
                        $server->rate = 1;
                        $server->tags = ['外部订阅'];
                    }
                    $server->save();
                }
                return compact('created', 'updated');
            });
        });
        return $this->success($result);
    }
}
