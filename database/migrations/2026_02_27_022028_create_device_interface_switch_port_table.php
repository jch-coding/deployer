<?php

use App\Models\DeviceInterface;
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
        Schema::create('device_interface_switch_port', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(DeviceInterface::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(SwitchPort::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_interface_switch_port');
    }
};
