<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_coupon_campaign', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->integer('type');
            $table->integer('value');
            $table->integer('claim_started_at');
            $table->integer('claim_ended_at');
            $table->integer('coupon_valid_days')->default(7);
            $table->integer('limit_claim')->nullable();
            $table->integer('claimed_count')->default(0);
            $table->text('limit_plan_ids')->nullable();
            $table->text('limit_period')->nullable();
            $table->boolean('enabled')->default(false);
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->index(['enabled', 'claim_started_at', 'claim_ended_at'], 'coupon_campaign_active_idx');
        });

        Schema::create('v2_coupon_claim', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('campaign_id');
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('telegram_id');
            $table->unsignedInteger('coupon_id');
            $table->integer('claimed_at');
            $table->integer('expires_at');
            $table->unsignedInteger('order_id')->nullable();
            $table->integer('redeemed_at')->nullable();
            $table->unique(['campaign_id', 'user_id'], 'coupon_claim_campaign_user_unique');
            $table->unique('coupon_id', 'coupon_claim_coupon_unique');
            $table->index(['user_id', 'redeemed_at', 'expires_at'], 'coupon_claim_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_coupon_claim');
        Schema::dropIfExists('v2_coupon_campaign');
    }
};
