<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\NodeClassification;
use App\Services\ServerFilter;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClassificationController extends Controller
{
    public function dimensions(Request $request)
    {
        $params = $request->validate(array_merge(ServerFilter::rules(), ['dimension' => ['sometimes', Rule::in(NodeClassification::DIMENSIONS)]]));
        $filters = $params['node_filter'] ?? [];
        $dimension = $params['dimension'] ?? 'protocol';
        $nodes = ServerService::getAllServers();
        $matched = ServerFilter::apply($nodes, $filters);
        $facetFilters = $filters;
        unset($facetFilters[NodeClassification::FILTER_KEYS[$dimension]]);
        $options = [];
        foreach (['region', 'management_group', 'provider', 'subscription', 'transport', 'tag'] as $key) $options[$key] = NodeClassification::groups($nodes, $key);
        return $this->success([
            'total' => $nodes->count(), 'matched' => $matched->count(),
            'groups' => NodeClassification::groups(ServerFilter::apply($nodes, $facetFilters), $dimension),
            'options' => $options, 'protocols' => NodeClassification::PROTOCOLS,
            'regions' => collect(NodeClassification::regions())->map(fn ($region, $code) => ['key' => $code, 'label' => $region['name']])->values(),
            // No credentials, protocol settings or subscription URLs in management metadata.
            'nodes' => $matched->take(5000)->map(fn ($node) => [
                'id' => $node->id, 'name' => $node->name,
                'protocol' => NodeClassification::label('protocol', NodeClassification::protocol($node)),
                'region' => NodeClassification::label('region', NodeClassification::region($node)['code']),
                'region_origin' => NodeClassification::region($node)['origin'],
                'management_group' => $node->management_group, 'provider' => $node->provider,
            ])->values(),
            'selection_limit' => 5000,
        ]);
    }

    public function batchClassify(Request $request)
    {
        $params = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000', Rule::exists('v2_server', 'id')],
            'ids.*' => 'required|integer|distinct',
            'region' => ['sometimes', 'nullable', Rule::in([...array_keys(NodeClassification::regions()), 'ZZ'])],
            'management_group' => 'sometimes|nullable|string|max:64|not_in:__unclassified__',
            'provider' => 'sometimes|nullable|string|max:64|not_in:__unclassified__',
            'add_tags' => 'sometimes|array|max:20', 'add_tags.*' => 'required|string|max:100|not_in:__unclassified__',
            'remove_tags' => 'sometimes|array|max:20', 'remove_tags.*' => 'required|string|max:100',
        ]);
        $fields = array_intersect_key($params, array_flip(['region', 'management_group', 'provider']));
        if (!$fields && empty($params['add_tags']) && empty($params['remove_tags'])) return $this->fail([422, '请至少选择一个要修改的分类字段或标签']);
        $count = DB::transaction(function () use ($params, $fields) {
            $nodes = Server::whereIn('id', $params['ids'])->lockForUpdate()->get();
            // Recheck inside transaction so concurrent deletes never yield partial classification.
            if ($nodes->count() !== count($params['ids'])) abort(409, '节点已变化，请刷新后重试');
            foreach ($nodes as $node) {
                $node->fill($fields);
                if (isset($params['add_tags']) || isset($params['remove_tags'])) {
                    $tags = array_unique(array_merge($node->tags ?? [], array_map('trim', $params['add_tags'] ?? [])));
                    $node->tags = array_values(array_diff($tags, array_map('trim', $params['remove_tags'] ?? []), ['']));
                }
                $node->save();
            }
            return $nodes->count();
        });
        return $this->success(['updated' => $count]);
    }
}
