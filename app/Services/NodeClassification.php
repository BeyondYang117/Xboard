<?php

namespace App\Services;

use Illuminate\Support\Collection;

class NodeClassification
{
    public const PROTOCOLS = [
        'shadowsocks' => 'Shadowsocks', 'shadowsocks2022' => 'Shadowsocks 2022',
        'vmess' => 'VMess', 'vless' => 'VLESS', 'trojan' => 'Trojan',
        'hysteria' => 'Hysteria 1', 'hysteria2' => 'Hysteria 2', 'tuic' => 'TUIC',
        'anytls' => 'AnyTLS', 'socks' => 'SOCKS', 'naive' => 'Naive', 'http' => 'HTTP', 'mieru' => 'Mieru',
    ];
    public const DIMENSIONS = ['protocol', 'region', 'management_group', 'provider', 'subscription', 'transport', 'tag'];
    public const FILTER_KEYS = [
        'protocol' => 'protocols', 'region' => 'region', 'management_group' => 'management_group',
        'provider' => 'provider', 'subscription' => 'subscription', 'transport' => 'transport', 'tag' => 'tag_exact',
    ];
    // Conservative aliases: unknown names remain unclassified. No host/IP geolocation.
    private const ALIASES = [
        'HK' => ['香港', 'hong kong'], 'TW' => ['台湾', '台灣', 'taiwan'], 'MO' => ['澳门', '澳門', 'macau', 'macao'],
        'US' => ['美国', '美國', '洛杉矶', '硅谷', '圣何塞', '西雅图', '纽约', '芝加哥', 'united states', 'los angeles', 'san jose', 'seattle', 'usa'],
        'SG' => ['新加坡', '狮城', 'singapore'], 'JP' => ['日本', '东京', '大阪', 'japan', 'tokyo', 'osaka'],
        'KR' => ['韩国', '韓國', '首尔', '南韩', 'south korea', 'seoul'], 'GB' => ['英国', '英國', '伦敦', 'united kingdom', 'london', 'uk'],
        'DE' => ['德国', '德國', '法兰克福', 'germany', 'frankfurt'], 'FR' => ['法国', '法國', '巴黎', 'france', 'paris'],
        'CA' => ['加拿大', '多伦多', '温哥华', 'canada', 'toronto', 'vancouver'], 'AU' => ['澳大利亚', '澳洲', '悉尼', 'australia', 'sydney'],
        'MY' => ['马来西亚', '馬來西亞', '吉隆坡', 'malaysia'], 'TH' => ['泰国', '泰國', '曼谷', 'thailand', 'bangkok'],
        'VN' => ['越南', 'vietnam'], 'PH' => ['菲律宾', '菲律賓', 'philippines'], 'ID' => ['印度尼西亚', '印尼', 'indonesia'],
        'IN' => ['印度', 'india'], 'RU' => ['俄罗斯', '俄羅斯', '莫斯科', 'russia', 'moscow'], 'TR' => ['土耳其', 'türkiye', 'turkey'],
        'NL' => ['荷兰', '荷蘭', '阿姆斯特丹', 'netherlands', 'amsterdam'], 'CH' => ['瑞士', 'switzerland'], 'SE' => ['瑞典', 'sweden'],
        'FI' => ['芬兰', '芬蘭', 'finland'], 'NO' => ['挪威', 'norway'], 'IT' => ['意大利', 'italy'], 'ES' => ['西班牙', 'spain'],
        'BR' => ['巴西', 'brazil'], 'AR' => ['阿根廷', 'argentina'], 'ZA' => ['南非', 'south africa'], 'AE' => ['阿联酋', '迪拜', 'dubai'],
        'CN' => ['中国', '中國', '大陆', '大陸', 'china'],
    ];

    public static function regions(): array
    {
        static $regions;
        return $regions ??= json_decode(file_get_contents(resource_path('data/node-regions.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function protocol($node): string
    {
        $type = $node->type;
        if ($type === 'shadowsocks' && str_starts_with(data_get($node->protocol_settings ?? [], 'cipher', ''), '2022-')) return 'shadowsocks2022';
        if ($type === 'hysteria' && (int) data_get($node->protocol_settings ?? [], 'version', 1) === 2) return 'hysteria2';
        return $type;
    }

    public static function region($node): array
    {
        if ($node->region ?? null) return ['code' => $node->region, 'origin' => 'manual'];
        $name = $node->name ?? '';
        if (preg_match('/[\x{1F1E6}-\x{1F1FF}]{2}/u', $name, $flag)) {
            $code = '';
            foreach (mb_str_split($flag[0]) as $char) $code .= chr(mb_ord($char) - 0x1F1E6 + 65);
            if (isset(self::regions()[$code])) return ['code' => $code, 'origin' => 'name'];
        }
        $text = implode(' ', [$name, ...($node->tags ?? [])]);
        foreach (self::ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                $pattern = preg_match('/^[a-z]/i', $alias) ? '/(?<![a-z])' . preg_quote($alias, '/') . '(?![a-z])/iu' : '/' . preg_quote($alias, '/') . '/u';
                if (preg_match($pattern, $text)) return ['code' => $code, 'origin' => 'name'];
            }
        }
        return ['code' => 'ZZ', 'origin' => 'unknown'];
    }

    public static function transport($node): string
    {
        $settings = $node->protocol_settings ?? [];
        if (in_array($node->type, ['hysteria', 'tuic'], true)) return 'quic';
        if ($node->type === 'mieru') return strtolower(data_get($settings, 'transport', 'TCP'));
        $network = strtolower(data_get($settings, 'network') ?: 'tcp');
        return match ($network) { 'websocket' => 'ws', 'http', 'http2' => 'h2', default => $network };
    }

    public static function subscription($node): string
    {
        return ($node->external_source ?? null) ? hash('sha256', $node->external_source) : 'local';
    }

    public static function value($node, string $dimension): string
    {
        return match ($dimension) {
            'protocol' => self::protocol($node), 'region' => self::region($node)['code'],
            'transport' => self::transport($node), 'subscription' => self::subscription($node),
            default => $node->{$dimension} ?? '',
        };
    }

    public static function label(string $dimension, string $key): string
    {
        return match ($dimension) {
            'protocol' => self::PROTOCOLS[$key] ?? $key,
            'region' => $key === 'ZZ' ? '未识别地区' : (self::regions()[$key]['name'] ?? $key),
            'subscription' => $key === 'local' ? '自建节点' : '订阅 ' . substr($key, 0, 8),
            'transport' => strtoupper($key),
            default => $key === '' ? '未分类' : $key,
        };
    }

    public static function groups(Collection $nodes, string $dimension): array
    {
        $counts = [];
        foreach ($nodes as $node) {
            $values = $dimension === 'tag' ? array_values(array_unique($node->tags ?? [])) : [self::value($node, $dimension)];
            foreach ($values ?: [''] as $value) $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        $groups = [];
        foreach ($counts as $key => $count) $groups[] = ['key' => $key === '' ? '__unclassified__' : (string) $key, 'label' => self::label($dimension, (string) $key), 'count' => $count];
        usort($groups, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
        return $groups;
    }
}
