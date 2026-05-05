<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->string('interface_kind')->nullable()->after('interface');
        });

        DB::table('device_interfaces')->where('interface', 'like', '%/%')->update(['interface_kind' => 'ETHERNET']);

        DB::table('device_interfaces')
            ->whereNull('interface_kind')
            ->whereNotNull('lacp_profile_id')
            ->update(['interface_kind' => 'LAG']);

        DB::table('device_interfaces')
            ->whereNull('interface_kind')
            ->whereNotNull('ip_address')
            ->where('ip_address', '!=', '')
            ->update(['interface_kind' => 'VLAN']);

        DB::table('device_interfaces')->whereNull('interface_kind')->update(['interface_kind' => 'ETHERNET']);

        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropUnique(['device_id', 'interface']);
            $table->unique(['device_id', 'interface', 'interface_kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropUnique(['device_id', 'interface', 'interface_kind']);
        });

        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->unique(['device_id', 'interface']);
        });

        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropColumn('interface_kind');
        });
    }
};
