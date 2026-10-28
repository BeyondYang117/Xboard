<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Filters the admin collection, including computed heartbeat and online counts. */
class ServerFilter
{
    public static function rules(): array
    {
        return [
            'node_filter' => 'sometimes|array:keyword,show,source,status,deployment,tag,port,rate_min,rate_max,online_min,online_max,protocols,region,management_group,provider,subscription,transport,tag_exact',
            'node_filter.protocols' => 'nullable|array|max:13',
            'node_filter.protocols.*' => ['string', Rule::in(array_keys(NodeClassification::PROTOCOLS))],
            'node_filter.region' => ['nullable', Rule::in([...array_keys(NodeClassification::regions()), 'ZZ'])],
            'node_filter.management_group' => 'nullable|string|max:64',
            'node_filter.provider' => 'nullable|string|max:64',
            'node_filter.subscription' => 'nullable|regex:/^(local|[a-f0-9]{64})$/',
            'node_filter.transport' => 'nullable|string|max:32',
            'node_filter.tag_exact' => 'nullable|string|max:100',
            'node_filter.keyword' => 'nullable|string|max:200',
            'node_filter.show' => 'nullable|in:0,1',
            'node_filter.source' => 'nullable|in:external,local',
            'node_filter.status' => 'nullable|in:0,1,2,external',
            'node_filter.deployment' => 'nullable|in:standalone,managed,disabled',
            'node_filter.tag' => 'nullable|string|max:100',
            'node_filter.port' => 'nullable|integer|between:1,65535',
            'node_filter.rate_min' => 'nullable|numeric|min:0',
            'node_filter.rate_max' => ['nullable', 'numeric', 'min:0', Rule::when(fn ($input) => is_numeric(data_get($input, 'node_filter.rate_min')), 'gte:node_filter.rate_min')],
            'node_filter.online_min' => 'nullable|integer|min:0',
            'node_filter.online_max' => ['nullable', 'integer', 'min:0', Rule::when(fn ($input) => is_numeric(data_get($input, 'node_filter.online_min')), 'gte:node_filter.online_min')],
        ];
    }

    public static function apply(Collection $servers, array $filters): Collection
    {
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '' && $value !== []);
        return $servers->filter(function ($server) use ($filters) {
            foreach ($filters as $key => $value) {
                $matches = match ($key) {
                    'keyword' => mb_stripos(implode(' ', [$server->id, $server->name, $server->host]), trim($value)) !== false,
                    'protocols' => !$value || in_array(NodeClassification::protocol($server), $value, true),
                    'region', 'management_group', 'provider', 'subscription', 'transport' => NodeClassification::value($server, $key) === ($value === '__unclassified__' ? '' : $value),
                    'tag_exact' => $value === '__unclassified__' ? empty($server->tags) : in_array($value, $server->tags ?? [], true),
                    'show' => (int) $server->show === (int) $value,
                    'source' => $server->is_external === ($value === 'external'),
                    // Upstream subscription nodes never report heartbeat to this panel.
                    'status' => $value === 'external' ? $server->is_external : (!$server->is_external && $server->available_status === (int) $value),
                    'deployment' => match ($value) {
                        'standalone' => !$server->machine_id,
                        'managed' => (bool) $server->machine_id && $server->enabled === true,
                        'disabled' => (bool) $server->machine_id && $server->enabled !== true,
                    },
                    'tag' => collect($server->tags ?? [])->contains(fn ($tag) => mb_stripos((string) $tag, trim($value)) !== false),
                    'port' => self::matchesPort((string) $server->port, (int) $value),
                    'rate_min' => (float) $server->rate >= (float) $value,
                    'rate_max' => (float) $server->rate <= (float) $value,
                    'online_min' => (int) $server->online >= (int) $value,
                    'online_max' => (int) $server->online <= (int) $value,
                    default => true,
                };
                if (!$matches) {
                    return false;
                }
            }
            return true;
        })->values();
    }

    private static function matchesPort(string $ports, int $port): bool
    {
        if (preg_match('/^(\d+)-(\d+)$/', $ports, $range)) {
            return $port >= (int) $range[1] && $port <= (int) $range[2];
        }
        return (int) $ports === $port;
    }
}
