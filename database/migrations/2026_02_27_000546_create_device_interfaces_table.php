<?php

use App\Models\Device;
use App\Models\LacpProfile;
use App\Models\SwitchPort;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('device_interfaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('enable')->default(true);
            $table->boolean('jumbo_frames')->default(false);
            $table->boolean('routing')->default(false);
            $table->string('vrf_forwarding')->default('default');
            $table->foreignIdFor(Device::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(LacpProfile::class, 'lacp_profile_id')->nullable()->constrained();
            $table->timestamps();

            $table->unique(['device_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_interfaces');
    }
};
