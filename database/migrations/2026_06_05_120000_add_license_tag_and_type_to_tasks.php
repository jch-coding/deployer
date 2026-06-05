<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('license_tag')->nullable()->after('licensing_subscription_key');
            $table->string('license_type')->nullable()->after('license_tag');
        });

        Schema::table('device_task', function (Blueprint $table) {
            $table->string('license_tag')->nullable()->after('licensing_service_name');
            $table->string('license_type')->nullable()->after('license_tag');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['license_tag', 'license_type']);
        });

        Schema::table('device_task', function (Blueprint $table) {
            $table->dropColumn(['license_tag', 'license_type']);
        });
    }
};
