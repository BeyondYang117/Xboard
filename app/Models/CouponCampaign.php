<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CouponCampaign extends Model
{
    protected $table = 'v2_coupon_campaign';
    protected $dateFormat = 'U';
    protected $guarded = ['id', 'claimed_count'];
    protected $casts = [
        'enabled' => 'boolean',
        'limit_plan_ids' => 'array',
        'limit_period' => 'array',
    ];

    public function claims(): HasMany
    {
        return $this->hasMany(CouponClaim::class, 'campaign_id');
    }
}
