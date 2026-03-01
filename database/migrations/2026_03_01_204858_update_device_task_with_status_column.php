<?php

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
        Schema::table('device_task', function (Blueprint $table) {
            $table->enum('status', ['PAUSED', 'IN_PROGRESS', 'COMPLETED', 'FAILED','PENDING'])->default('PENDING');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_task', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
