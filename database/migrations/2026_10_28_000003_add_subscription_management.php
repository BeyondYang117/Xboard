<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_server_subscription', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64)->unique();
            $table->string('name', 100);
            $table->json('group_ids');
            $table->boolean('enabled')->default(true);
            $table->string('delivery_mode', 16)->default('relay');
            $table->decimal('rate', 8, 2)->default(1);
            $table->unsignedInteger('max_online')->default(0);
            $table->string('online_limit_mode', 16)->default('ip');
            $table->string('gateway_host')->nullable();
            $table->unsignedSmallInteger('gateway_port')->default(2443);
            $table->unsignedBigInteger('transfer_enable')->default(0);
            $table->unsignedBigInteger('u')->default(0);
            $table->unsignedBigInteger('d')->default(0);
            $table->unsignedInteger('expired_at')->nullable();
            $table->unsignedInteger('last_import_at')->nullable();
            $table->timestamps();
        });
        Schema::table('v2_server', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
        });
        // Existing direct subscriptions retain their delivery behavior on upgrade.
        foreach (DB::table('v2_server')->whereNotNull('external_source')->select('external_source')->distinct()->pluck('external_source') as $source) {
            $nodes = DB::table('v2_server')->where('external_source', $source)->get();
            $groups = $nodes->flatMap(fn ($node) => json_decode($node->group_ids ?: '[]', true) ?: [])->map(fn ($id) => (string) $id)->unique()->values()->all();
            $id = DB::table('v2_server_subscription')->insertGetId([
                'source' => $source, 'name' => '机场 ' . substr($source, 0, 8),
                'group_ids' => json_encode($groups), 'delivery_mode' => 'direct',
                'rate' => $nodes->first()->rate, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('v2_server')->where('external_source', $source)->update(['subscription_id' => $id]);
        }
        Schema::create('v2_subscription_session', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('subscription_id')->index();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('ip', 45);
            $table->string('protocol', 32);
            $table->decimal('rate', 8, 2);
            $table->unsignedBigInteger('u')->default(0);
            $table->unsignedBigInteger('d')->default(0);
            $table->unsignedSmallInteger('upload_remainder')->default(0);
            $table->unsignedSmallInteger('download_remainder')->default(0);
            $table->unsignedInteger('expires_at')->index();
            $table->unsignedInteger('closed_at')->nullable();
            $table->index(['subscription_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_session');
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropIndex(['subscription_id']);
            $table->dropColumn('subscription_id');
        });
        Schema::dropIfExists('v2_server_subscription');
    }
};
