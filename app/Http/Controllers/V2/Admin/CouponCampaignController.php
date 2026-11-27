<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CouponCampaign;
use App\Models\CouponClaim;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CouponCampaignController extends Controller
{
    public function options()
    {
        return $this->success([
            'plans' => Plan::query()->select(['id', 'name'])->orderBy('sort')->orderBy('id')->get(),
            'periods' => [
                ['key' => 'monthly', 'label' => '月付'],
                ['key' => 'quarterly', 'label' => '季付'],
                ['key' => 'half_yearly', 'label' => '半年付'],
                ['key' => 'yearly', 'label' => '年付'],
                ['key' => 'two_yearly', 'label' => '两年付'],
                ['key' => 'three_yearly', 'label' => '三年付'],
                ['key' => 'onetime', 'label' => '一次性'],
            ],
        ]);
    }

    public function fetch(Request $request)
    {
        $campaigns = CouponCampaign::withCount('claims')
            ->withCount(['claims as redeemed_count' => fn ($query) => $query->whereNotNull('redeemed_at')])
            ->orderByDesc('created_at')
            ->paginate($request->integer('pageSize', 10), ['*'], 'page', $request->integer('current', 1));
        return $this->paginate($campaigns);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:v2_coupon_campaign,id',
            'name' => 'required|string|max:128',
            'description' => 'nullable|string|max:2000',
            'type' => 'required|integer|in:1,2',
            'value' => 'required|integer|min:1',
            'claim_started_at' => 'required|integer|min:1',
            'claim_ended_at' => 'required|integer|gt:claim_started_at',
            'coupon_valid_days' => 'required|integer|min:1|max:365',
            'limit_claim' => 'nullable|integer|min:1',
            'limit_plan_ids' => 'nullable|array',
            'limit_plan_ids.*' => 'integer|exists:v2_plan,id',
            'limit_period' => 'nullable|array',
            'limit_period.*' => 'string|max:32',
            'enabled' => 'required|boolean',
        ]);
        if ((int) $data['type'] === 2 && (int) $data['value'] > 100) {
            return $this->fail([422, '折扣比例不能超过 100%']);
        }

        $id = $data['id'] ?? null;
        unset($data['id']);
        $campaign = DB::transaction(function () use ($id, $data) {
            $data['updated_at'] = time();
            if (!$id) {
                $data['created_at'] = time();
                return CouponCampaign::create($data);
            }

            $campaign = CouponCampaign::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($campaign->claimed_count > 0) {
                foreach (['type', 'value', 'limit_plan_ids', 'limit_period', 'coupon_valid_days'] as $immutableField) {
                    if (array_key_exists($immutableField, $data) && $data[$immutableField] != $campaign->{$immutableField}) {
                        throw new ApiException('活动已有领取记录，不能修改优惠规则或有效期');
                    }
                }
            }
            $campaign->update($data);
            return $campaign;
        });
        return $this->success($campaign->fresh()->loadCount('claims'));
    }

    public function claims(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_coupon_campaign,id']);
        $claims = CouponClaim::with(['coupon:id,code', 'campaign:id,name', 'user:id,email,telegram_id'])
            ->where('campaign_id', $request->input('id'))
            ->orderByDesc('claimed_at')
            ->paginate($request->integer('pageSize', 20), ['*'], 'page', $request->integer('current', 1));
        return $this->paginate($claims);
    }

    public function drop(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_coupon_campaign,id']);
        $deleted = DB::transaction(function () use ($request) {
            $campaign = CouponCampaign::whereKey($request->input('id'))->lockForUpdate()->firstOrFail();
            if ($campaign->claims()->exists()) {
                return false;
            }
            return $campaign->delete();
        });
        if (!$deleted) {
            return $this->fail([409, '活动已有用户领取，不能删除；请停用活动']);
        }
        return $this->success(true);
    }
}
