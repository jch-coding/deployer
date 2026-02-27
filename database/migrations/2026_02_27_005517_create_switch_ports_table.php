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
        Schema::create('switch_ports', function (Blueprint $table) {
            $table->id();
            $table->integer('access_vlan')->nullable()->unsigned();
            $table->enum('interface_mode', ['ACCESS', 'TRUNK'])->default('ACCESS');
            $table->boolean('is_profile')->default(false);
            $table->integer('native_vlan')->nullable()->unsigned();
            $table->boolean('trunk_vlan_all')->default(false);
            $table->string('trunk_vlan_ranges')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('switch_ports');
    }
};
