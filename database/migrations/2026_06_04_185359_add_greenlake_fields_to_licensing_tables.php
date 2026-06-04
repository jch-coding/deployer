<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_subscriptions', function (Blueprint $table) {
            $table->string('greenlake_subscription_id')->nullable()->after('subscription_key');
            $table->unsignedInteger('quantity')->default(0)->after('available');
            $table->json('tags')->nullable()->after('acpapp_name');
        });

        Schema::table('licensing_inventory_devices', function (Blueprint $table) {
            $table->string('greenlake_device_id')->nullable()->after('serial');
        });
    }

    public function down(): void
    {
        Schema::table('licensing_inventory_devices', function (Blueprint $table) {
            $table->dropColumn('greenlake_device_id');
        });

        Schema::table('client_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'greenlake_subscription_id',
                'quantity',
                'tags',
            ]);
        });
    }
};
