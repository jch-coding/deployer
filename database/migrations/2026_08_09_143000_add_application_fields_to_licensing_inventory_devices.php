<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licensing_inventory_devices', function (Blueprint $table) {
            $table->string('application_id')->nullable()->after('greenlake_device_id');
            $table->string('application_region')->nullable()->after('application_id');
        });
    }

    public function down(): void
    {
        Schema::table('licensing_inventory_devices', function (Blueprint $table) {
            $table->dropColumn(['application_id', 'application_region']);
        });
    }
};
