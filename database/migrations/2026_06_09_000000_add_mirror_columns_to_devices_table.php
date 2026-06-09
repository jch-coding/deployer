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
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedTinyInteger('mirror_session_id')->nullable();
            $table->string('mirror_dst_ports')->nullable();
            $table->string('mirror_vlans')->nullable();
            $table->string('mirror_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'mirror_session_id',
                'mirror_dst_ports',
                'mirror_vlans',
                'mirror_name',
            ]);
        });
    }
};
