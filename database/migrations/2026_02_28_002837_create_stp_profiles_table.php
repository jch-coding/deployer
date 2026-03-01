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
        Schema::create('stp_profiles', function (Blueprint $table) {
            $table->id();
            $table->boolean('admin_edge_port')->nullable()->default(false);
            $table->boolean('admin_edge_port_trunk')->nullable()->default(false);
            $table->boolean('bpdu_guard')->nullable()->default(false);
            $table->boolean('loop_guard')->nullable()->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stp_profiles');
    }
};
