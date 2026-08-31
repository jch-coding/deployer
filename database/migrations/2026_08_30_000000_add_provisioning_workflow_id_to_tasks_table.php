<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'provisioning_workflow_id')) {
                $table->foreignId('provisioning_workflow_id')
                    ->nullable()
                    ->after('deployment_id')
                    ->constrained('provisioning_workflows')
                    ->nullOnDelete();
                $table->unique('provisioning_workflow_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'provisioning_workflow_id')) {
                $table->dropConstrainedForeignId('provisioning_workflow_id');
            }
        });
    }
};
