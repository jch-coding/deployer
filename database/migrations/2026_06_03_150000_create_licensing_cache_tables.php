<?php

use App\Models\Client;
use App\Models\Device;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->json('licensing_enabled_services')->nullable()->after('current');
            $table->timestamp('licensing_synced_at')->nullable()->after('licensing_enabled_services');
            $table->text('licensing_sync_error')->nullable()->after('licensing_synced_at');
        });

        Schema::create('client_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Client::class)->constrained()->cascadeOnDelete();
            $table->string('subscription_key');
            $table->string('subscription_sku')->default('');
            $table->string('license_type')->default('');
            $table->unsignedBigInteger('start_date')->nullable();
            $table->unsignedBigInteger('end_date')->nullable();
            $table->string('status')->default('');
            $table->string('subscription_type')->default('');
            $table->unsignedInteger('available')->default(0);
            $table->string('acpapp_name')->default('');
            $table->timestamps();

            $table->unique(['client_id', 'subscription_key']);
        });

        Schema::create('licensing_inventory_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Client::class)->constrained()->cascadeOnDelete();
            $table->string('serial');
            $table->string('model')->default('');
            $table->string('mac')->default('');
            $table->string('device_type')->default('');
            $table->string('name')->default('');
            $table->boolean('licensed')->default(false);
            $table->json('assigned_services')->nullable();
            $table->string('subscription_key')->default('');
            $table->foreignIdFor(Device::class, 'deployer_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'serial']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->string('licensing_subscription_key')->nullable()->after('stack_id');
            $table->json('licensing_assigned_services')->nullable()->after('licensing_subscription_key');
            $table->boolean('licensing_licensed')->nullable()->after('licensing_assigned_services');
            $table->string('licensing_subscription_sku')->nullable()->after('licensing_licensed');
            $table->string('licensing_license_type')->nullable()->after('licensing_subscription_sku');
            $table->unsignedBigInteger('licensing_start_date')->nullable()->after('licensing_license_type');
            $table->unsignedBigInteger('licensing_end_date')->nullable()->after('licensing_start_date');
            $table->string('licensing_subscription_status')->nullable()->after('licensing_end_date');
            $table->timestamp('licensing_synced_at')->nullable()->after('licensing_subscription_status');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'licensing_subscription_key',
                'licensing_assigned_services',
                'licensing_licensed',
                'licensing_subscription_sku',
                'licensing_license_type',
                'licensing_start_date',
                'licensing_end_date',
                'licensing_subscription_status',
                'licensing_synced_at',
            ]);
        });

        Schema::dropIfExists('licensing_inventory_devices');
        Schema::dropIfExists('client_subscriptions');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'licensing_enabled_services',
                'licensing_synced_at',
                'licensing_sync_error',
            ]);
        });
    }
};
