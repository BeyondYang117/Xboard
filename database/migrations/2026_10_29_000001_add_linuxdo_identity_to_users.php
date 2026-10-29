<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->string('linuxdo_id', 20)->nullable()->unique();
            $table->string('linuxdo_username', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropUnique(['linuxdo_id']);
            $table->dropColumn(['linuxdo_id', 'linuxdo_username']);
        });
    }
};
