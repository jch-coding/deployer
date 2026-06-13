<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('devices', 'license_tag')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->string('license_tag')->nullable()->after('mirror_name');
                $table->string('license_type')->nullable()->after('license_tag');
            });
        }

        if (! Schema::hasTable('provisioning_workflows')) {
            Schema::create('provisioning_workflows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status')->default('running');
                $table->string('job_queue')->default('q0');
                $table->unsignedInteger('deployment_time')->default(10);
                $table->unsignedInteger('wait_time')->default(1);
                $table->json('licensing_config')->nullable();
                $table->boolean('classic_poller_active')->default(false);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('provisioning_workflow_devices')) {
            Schema::create('provisioning_workflow_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('provisioning_workflow_id')->constrained()->cascadeOnDelete();
                $table->foreignId('device_id')->constrained()->cascadeOnDelete();
                $table->string('overall_status')->default('in_progress');
                $table->string('current_step_key')->nullable();
                $table->string('failed_step_key')->nullable();
                $table->text('status_message')->nullable();
                $table->string('vsx_wait_state')->nullable();
                $table->timestamps();

                $table->unique(['provisioning_workflow_id', 'device_id'], 'pw_devices_workflow_device_unique');
            });
        }

        if (! Schema::hasTable('provisioning_workflow_device_steps')) {
            Schema::create('provisioning_workflow_device_steps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('provisioning_workflow_device_id');
                $table->foreign('provisioning_workflow_device_id', 'pw_device_steps_device_fk')
                    ->references('id')
                    ->on('provisioning_workflow_devices')
                    ->cascadeOnDelete();
                $table->string('step_key');
                $table->unsignedTinyInteger('step_order');
                $table->string('status')->default('pending');
                $table->text('message')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['provisioning_workflow_device_id', 'step_key'], 'pw_device_steps_device_step_unique');
            });
        } else {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS pw_device_steps_device_step_unique '
                .'ON provisioning_workflow_device_steps (provisioning_workflow_device_id, step_key)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_workflow_device_steps');
        Schema::dropIfExists('provisioning_workflow_devices');
        Schema::dropIfExists('provisioning_workflows');

        if (Schema::hasColumn('devices', 'license_tag')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropColumn(['license_tag', 'license_type']);
            });
        }
    }
};
