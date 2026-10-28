<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeProbe\NodeProbe;
use App\Services\NodeProbe\ProbeResults;
use App\Services\NodeProbe\ProxyRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class ProbeController extends Controller
{
    public function history(Request $request, ProxyRunner $runner)
    {
        $params = $request->validate(['ids' => 'required|array|max:200', 'ids.*' => 'integer|distinct']);
        $results = Server::whereIn('id', $params['ids'])->get()->mapWithKeys(fn ($node) => [$node->id => [
            'tcp' => ProbeResults::get($node, 'tcp'), 'proxy' => ProbeResults::get($node, 'proxy'),
        ]]);
        return $this->success(['results' => $results, 'proxy_available' => $runner->available(), 'targets' => collect(config('node_probe.targets'))->map(fn ($target, $key) => ['key' => $key, 'name' => $target['name']])->values()]);
    }

    public function run(Request $request, NodeProbe $probe)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server,id', 'mode' => 'required|in:tcp,proxy',
            'target' => ['sometimes', Rule::in(array_keys(config('node_probe.targets')))],
            'user_id' => 'nullable|integer|exists:v2_user,id',
        ]);
        $node = Server::findOrFail($params['id']);
        $nodeLock = Cache::lock("node-probe:running:{$node->id}", 25);
        if (!$nodeLock->get()) return $this->fail([429, '此节点正在测试，请稍后重试']);
        $slot = null;
        try {
            for ($i = 0; $i < 4; $i++) {
                $candidate = Cache::lock("node-probe:slot:{$i}", 25);
                if ($candidate->get()) { $slot = $candidate; break; }
            }
            if (!$slot) return $this->fail([429, '测试任务较多，请稍后重试']);
            $user = !empty($params['user_id']) ? User::find($params['user_id']) : null;
            $result = $probe->probe($node, $params['mode'], $params['target'] ?? 'cloudflare', $user);
            // Do not replace a useful previous result with a missing-user/engine/config attempt.
            if (in_array($result['state'], ['ok', 'failed'], true)) ProbeResults::put($node, $result);
            return $this->success($result);
        } finally {
            $slot?->release(); $nodeLock->release();
        }
    }
}
