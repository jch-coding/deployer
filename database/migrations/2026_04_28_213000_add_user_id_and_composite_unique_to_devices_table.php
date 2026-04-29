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
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('client_id')->constrained()->cascadeOnDelete();
        });

        $clientOwnerById = DB::table('clients')->pluck('user_id', 'id');

        DB::table('devices')
            ->select(['id', 'client_id'])
            ->orderBy('id')
            ->chunkById(100, function ($devices) use ($clientOwnerById): void {
                foreach ($devices as $device) {
                    $userId = $clientOwnerById[$device->client_id] ?? null;
                    if ($userId !== null) {
                        DB::table('devices')
                            ->where('id', $device->id)
                            ->update(['user_id' => $userId]);
                    }
                }
            });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique('devices_serial_unique');
            $table->unique(['serial', 'user_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique('devices_serial_user_id_unique');
            $table->unique('serial');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
