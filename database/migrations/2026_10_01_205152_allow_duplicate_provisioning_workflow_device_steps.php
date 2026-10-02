<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_workflow_device_steps', function (Blueprint $table) {
            $table->dropUnique('pw_device_steps_device_step_unique');
        });

        Schema::table('provisioning_workflow_device_steps', function (Blueprint $table) {
            $table->unique(
                ['provisioning_workflow_device_id', 'step_order'],
                'pw_device_steps_device_order_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_workflow_device_steps', function (Blueprint $table) {
            $table->dropUnique('pw_device_steps_device_order_unique');
        });

        // Recreate the prior uniqueness constraint only when no duplicate step keys exist.
        $hasDuplicates = DB::table('provisioning_workflow_device_steps')
            ->select('provisioning_workflow_device_id', 'step_key')
            ->groupBy('provisioning_workflow_device_id', 'step_key')
            ->havingRaw('count(*) > 1')
            ->exists();

        if (! $hasDuplicates) {
            Schema::table('provisioning_workflow_device_steps', function (Blueprint $table) {
                $table->unique(
                    ['provisioning_workflow_device_id', 'step_key'],
                    'pw_device_steps_device_step_unique',
                );
            });
        }
    }
};
