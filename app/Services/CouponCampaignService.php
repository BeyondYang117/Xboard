<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\CouponCampaign;
use App\Models\CouponClaim;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

class CouponCampaignService
{
    public function availableCampaigns()
    {
        $now = time();
        return CouponCampaign::query()
            ->where('enabled', true)
            ->where('claim_started_at', '<=', $now)
            ->where('claim_ended_at', '>=', $now)
            ->where(function ($query) {
                $query->whereNull('limit_claim')->orWhereColumn('claimed_count', '<', 'limit_claim');
            })
            ->orderBy('claim_ended_at')
            ->get();
    }

    public function claim(int $campaignId, User $user): CouponClaim
    {
        if (!$user->telegram_id) {
            throw new ApiException('请先绑定 Telegram 账号后再领取优惠券');
        }

        return DB::transaction(function () use ($campaignId, $user) {
            $campaign = CouponCampaign::whereKey($campaignId)->lockForUpdate()->first();
            if (!$campaign) {
                throw new ApiException('该优惠活动不存在或已结束');
            }
            $existing = CouponClaim::where('campaign_id', $campaign->id)
                ->where('user_id', $user->id)
                ->first();
            if ($existing) {
                return $existing->load('coupon');
            }
            if (!$campaign->enabled) {
                throw new ApiException('该优惠活动不存在或已结束');
            }
            $now = time();
            if ($now < $campaign->claim_started_at || $now > $campaign->claim_ended_at) {
                throw new ApiException('当前不在活动领取时间内');
            }
            if ($campaign->limit_claim !== null && $campaign->claimed_count >= $campaign->limit_claim) {
                throw new ApiException('优惠券已领完');
            }

            $expiresAt = $now + max(1, $campaign->coupon_valid_days) * 86400;
            $code = $this->uniqueCode();
            $coupon = Coupon::create([
                'code' => $code,
                'name' => $campaign->name,
                'type' => $campaign->type,
                'value' => $campaign->value,
                'show' => true,
                'limit_use' => 1,
                'limit_use_with_user' => 1,
                'limit_plan_ids' => $campaign->limit_plan_ids,
                'limit_period' => $campaign->limit_period,
                'started_at' => $now,
                'ended_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $claim = CouponClaim::create([
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'coupon_id' => $coupon->id,
                'claimed_at' => $now,
                'expires_at' => $expiresAt,
            ]);
            $campaign->increment('claimed_count');

            return $claim->setRelation('coupon', $coupon);
        });
    }

    public function claimableCoupons(User $user)
    {
        return CouponClaim::with(['campaign:id,name', 'coupon:id,code,type,value,ended_at'])
            ->where('user_id', $user->id)
            ->orderByDesc('claimed_at')
            ->get();
    }

    protected function uniqueCode(): string
    {
        do {
            $code = 'TG' . strtoupper(Helper::randomChar(10));
        } while (Coupon::where('code', $code)->exists());

        return $code;
    }
}
