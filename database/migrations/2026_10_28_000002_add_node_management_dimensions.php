<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->string('region', 2)->nullable()->index();
            $table->string('management_group', 64)->nullable()->index();
            $table->string('provider', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropIndex(['region']);
            $table->dropIndex(['management_group']);
            $table->dropIndex(['provider']);
            $table->dropColumn(['region', 'management_group', 'provider']);
        });
    }
};
