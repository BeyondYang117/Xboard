<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerSubscription extends Model
{
    protected $table = 'v2_server_subscription';
    protected $guarded = ['id'];
    protected $hidden = ['source'];
    protected $casts = [
        'group_ids' => 'array', 'enabled' => 'boolean', 'rate' => 'float',
        'max_online' => 'integer', 'gateway_port' => 'integer',
        'transfer_enable' => 'integer', 'u' => 'integer', 'd' => 'integer',
        'expired_at' => 'integer', 'last_import_at' => 'integer',
    ];

    public function nodes()
    {
        return $this->hasMany(Server::class, 'subscription_id');
    }

    public function gatewayEndpoint(): array
    {
        if ($host = config('subscription_gateway.host')) {
            return ['host' => $host, 'port' => (int) config('subscription_gateway.port', 2443)];
        }
        return ['host' => $this->gateway_host, 'port' => $this->gateway_port];
    }

    public function isAvailable(): bool
    {
        return $this->enabled && (!$this->expired_at || $this->expired_at > time())
            && (!$this->transfer_enable || $this->u + $this->d < $this->transfer_enable);
    }
}
