<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->string('external_source', 64)->nullable()->index();
            $table->string('external_key', 64)->nullable()->unique();
            $table->text('external_password')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropUnique(['external_key']);
            $table->dropIndex(['external_source']);
            $table->dropColumn(['external_source', 'external_key', 'external_password']);
        });
    }
};
