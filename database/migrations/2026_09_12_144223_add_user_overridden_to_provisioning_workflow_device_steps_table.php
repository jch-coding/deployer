<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_workflow_device_steps', function (Blueprint $table) {
            $table->boolean('user_overridden')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_workflow_device_steps', function (Blueprint $table) {
            $table->dropColumn('user_overridden');
        });
    }
};
