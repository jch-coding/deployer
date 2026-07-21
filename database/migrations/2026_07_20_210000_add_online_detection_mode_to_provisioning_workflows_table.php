<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_workflows', function (Blueprint $table) {
            $table->string('online_detection_mode')->default('poll')->after('wait_time');
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_workflows', function (Blueprint $table) {
            $table->dropColumn('online_detection_mode');
        });
    }
};
