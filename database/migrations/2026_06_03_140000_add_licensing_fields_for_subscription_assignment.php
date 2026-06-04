<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('licensing_subscription_key')->nullable()->after('licensing_service_name');
        });

        Schema::table('device_task', function (Blueprint $table) {
            $table->string('licensing_service_name')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('licensing_subscription_key');
        });

        Schema::table('device_task', function (Blueprint $table) {
            $table->dropColumn('licensing_service_name');
        });
    }
};
