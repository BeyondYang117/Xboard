<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\ServerFilter;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ServerFilterTest extends TestCase
{
    private function nodes()
    {
        return collect([
            $this->node(['id' => 1, 'name' => '新加坡专线', 'host' => 'sg.example.com', 'show' => true, 'rate' => 0.1, 'port' => '443', 'tags' => ['专线'], 'machine_id' => null, 'is_external' => true, 'available_status' => 0, 'online' => 0]),
            $this->node(['id' => 2, 'name' => '美国 AWS', 'host' => 'us.example.com', 'show' => false, 'rate' => 2, 'port' => '8000-9000', 'tags' => ['AWS'], 'machine_id' => 3, 'enabled' => true, 'is_external' => false, 'available_status' => 2, 'online' => 15]),
            $this->node(['id' => 3, 'name' => '香港', 'host' => 'hk.example.com', 'show' => true, 'rate' => 1, 'port' => '8443', 'tags' => [], 'machine_id' => 3, 'enabled' => false, 'is_external' => false, 'available_status' => 0, 'online' => 0]),
        ]);
    }

    private function node(array $data): object
    {
        return (object) $data;
    }

    public function test_filters_combine_and_preserve_zero_values_and_array_shape(): void
    {
        $result = ServerFilter::apply($this->nodes(), ['show' => '0', 'source' => 'local', 'keyword' => 'aws', 'rate_max' => '2', 'online_min' => '10']);
        $this->assertSame([2], $result->pluck('id')->all());
        $this->assertSame([0], $result->keys()->all());
        $this->assertCount(3, ServerFilter::apply($this->nodes(), []));
        $this->assertSame([1, 3], ServerFilter::apply($this->nodes(), ['online_max' => '0'])->pluck('id')->all());
    }

    public function test_upstream_nodes_are_not_reported_as_offline(): void
    {
        $this->assertSame([3], ServerFilter::apply($this->nodes(), ['status' => '0'])->pluck('id')->all());
        $this->assertSame([1], ServerFilter::apply($this->nodes(), ['status' => 'external'])->pluck('id')->all());
        $this->assertSame([2], ServerFilter::apply($this->nodes(), ['status' => '2'])->pluck('id')->all());
    }

    public function test_deployment_tags_search_and_port_ranges(): void
    {
        foreach (['standalone' => 1, 'managed' => 2, 'disabled' => 3] as $deployment => $id) {
            $this->assertSame([$id], ServerFilter::apply($this->nodes(), ['deployment' => $deployment])->pluck('id')->all());
        }
        $this->assertSame([1], ServerFilter::apply($this->nodes(), ['tag' => '专', 'keyword' => 'SG.EXAMPLE'])->pluck('id')->all());
        $this->assertSame([2, 3], ServerFilter::apply($this->nodes(), ['port' => '8443'])->pluck('id')->all());
        $this->assertSame([2], ServerFilter::apply($this->nodes(), ['keyword' => '2'])->pluck('id')->all());
        $this->assertCount(0, ServerFilter::apply($this->nodes(), ['rate_min' => '3']));
    }

    public function test_validation_rejects_bad_filters_and_inverted_ranges(): void
    {
        foreach ([['source' => 'bad'], ['port' => '0'], ['online_min' => '-1'], ['rate_min' => '2', 'rate_max' => '1'], ['online_min' => '10', 'online_max' => '0'], ['unknown' => 'x']] as $filters) {
            $this->assertTrue(Validator::make(['node_filter' => $filters], ServerFilter::rules())->fails());
        }
        foreach ([[], ['rate_max' => '0'], ['online_max' => '0'], ['show' => '0', 'status' => 'external']] as $filters) {
            $this->assertFalse(Validator::make(['node_filter' => $filters], ServerFilter::rules())->fails());
        }
    }
}
