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
        Schema::table('lacp_profiles', function (Blueprint $table) {
            $table->json('port_list')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lacp_profiles', function (Blueprint $table) {
            $table->dropColumn('port_list');
        });
    }
};
