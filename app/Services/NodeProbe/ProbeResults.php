<?php

namespace App\Services\NodeProbe;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class ProbeResults
{
    public static function fingerprint(Server $node): string
    {
        return hash('sha256', json_encode([$node->type, $node->host, $node->port, $node->protocol_settings, $node->getRawOriginal('external_password'), $node->group_ids, $node->parent_id, $node->created_at]));
    }

    public static function put(Server $node, array $result): void
    {
        Cache::put("node-probe:{$node->id}:{$result['mode']}", ['fingerprint' => self::fingerprint($node), 'result' => $result], config('node_probe.ttl', 1800));
    }

    public static function get(Server $node, string $mode): ?array
    {
        $cached = Cache::get("node-probe:{$node->id}:{$mode}");
        return $cached && hash_equals(self::fingerprint($node), $cached['fingerprint']) ? $cached['result'] : null;
    }
}
