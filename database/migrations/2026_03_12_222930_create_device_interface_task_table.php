<?php

use App\Models\DeviceInterface;
use App\Models\Task;
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
        Schema::create('device_interface_task', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(DeviceInterface::class)->constrained();
            $table->foreignIdFor(Task::class)->constrained();
            $table->enum('status', ['PAUSED', 'IN_PROGRESS', 'COMPLETED', 'FAILED', 'PENDING'])->default('PENDING');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_interface_task');
    }
};
