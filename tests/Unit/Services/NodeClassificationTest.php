<?php

namespace Tests\Unit\Services;

use App\Services\NodeClassification;
use App\Services\ServerFilter;
use Tests\TestCase;

class NodeClassificationTest extends TestCase
{
    private function node(array $values): object
    {
        return (object) array_merge(['id' => 1, 'name' => 'Node', 'type' => 'vless', 'protocol_settings' => [], 'region' => null, 'tags' => [], 'external_source' => null, 'management_group' => null, 'provider' => null], $values);
    }

    public function test_protocol_versions_and_transports_remain_distinct(): void
    {
        foreach ([['shadowsocks', ['cipher' => '2022-blake3-aes-128-gcm'], 'shadowsocks2022'], ['shadowsocks', ['cipher' => 'aes-128-gcm'], 'shadowsocks'], ['hysteria', ['version' => 2], 'hysteria2'], ['hysteria', ['version' => 1], 'hysteria']] as [$type, $settings, $expected]) {
            $this->assertSame($expected, NodeClassification::protocol($this->node(['type' => $type, 'protocol_settings' => $settings])));
        }
        $this->assertSame('quic', NodeClassification::transport($this->node(['type' => 'hysteria'])));
        $this->assertSame('ws', NodeClassification::transport($this->node(['protocol_settings' => ['network' => 'websocket']])));
        $this->assertSame('tcp', NodeClassification::transport($this->node([])));
    }

    public function test_region_manual_override_flags_aliases_and_unknown(): void
    {
        foreach ([['🇯🇵 Tokyo', null, 'JP'], ['美国圣何塞', null, 'US'], ['马来西亚 MA01', null, 'MY'], ['Hong Kong Premium', null, 'HK'], ['印度尼西亚', null, 'ID'], ['AWS mysterious', null, 'ZZ'], ['Australia', null, 'AU'], ['russian', null, 'ZZ'], ['🇯🇵 日本', 'US', 'US']] as [$name, $region, $expected]) {
            $this->assertSame($expected, NodeClassification::region($this->node(compact('name', 'region')))['code']);
        }
        $this->assertSame('manual', NodeClassification::region($this->node(['region' => 'JP']))['origin']);
        $this->assertCount(249, NodeClassification::regions());
    }

    public function test_multidimensional_filters_and_unclassified_groups(): void
    {
        $nodes = collect([
            $this->node(['id' => 1, 'name' => '🇯🇵 日本', 'management_group' => '亚洲', 'provider' => 'AWS', 'tags' => ['流媒体', '流媒体']]),
            $this->node(['id' => 2, 'name' => '美国', 'type' => 'trojan', 'external_source' => 'private-hmac']),
        ]);
        $this->assertSame([1], ServerFilter::apply($nodes, ['protocols' => ['vless', 'shadowsocks2022'], 'region' => 'JP', 'management_group' => '亚洲', 'provider' => 'AWS', 'tag_exact' => '流媒体', 'transport' => 'tcp'])->pluck('id')->all());
        $this->assertSame([2], ServerFilter::apply($nodes, ['management_group' => '__unclassified__', 'tag_exact' => '__unclassified__'])->pluck('id')->all());
        $source = NodeClassification::subscription($nodes[1]);
        $this->assertNotSame('private-hmac', $source);
        $this->assertSame([2], ServerFilter::apply($nodes, ['subscription' => $source])->pluck('id')->all());
        $groups = NodeClassification::groups($nodes, 'tag');
        $this->assertSame(1, $groups[0]['count']);
        $this->assertContains('__unclassified__', array_column($groups, 'key'));
    }
}
