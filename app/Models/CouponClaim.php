<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponClaim extends Model
{
    protected $table = 'v2_coupon_claim';
    public $timestamps = false;
    protected $guarded = ['id'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CouponCampaign::class, 'campaign_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
