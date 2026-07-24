<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('provisioning_workflow_templates')) {
            Schema::create('provisioning_workflow_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->json('steps');
                $table->timestamps();

                $table->unique(['client_id', 'name'], 'pw_templates_client_name_unique');
            });
        }

        Schema::table('provisioning_workflows', function (Blueprint $table) {
            if (! Schema::hasColumn('provisioning_workflows', 'name')) {
                $table->string('name')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('provisioning_workflows', 'steps')) {
                $table->json('steps')->nullable()->after('licensing_config');
            }
            if (! Schema::hasColumn('provisioning_workflows', 'provisioning_workflow_template_id')) {
                $table->foreignId('provisioning_workflow_template_id')
                    ->nullable()
                    ->after('steps')
                    ->constrained('provisioning_workflow_templates')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_workflows', function (Blueprint $table) {
            if (Schema::hasColumn('provisioning_workflows', 'provisioning_workflow_template_id')) {
                $table->dropConstrainedForeignId('provisioning_workflow_template_id');
            }
            if (Schema::hasColumn('provisioning_workflows', 'steps')) {
                $table->dropColumn('steps');
            }
            if (Schema::hasColumn('provisioning_workflows', 'name')) {
                $table->dropColumn('name');
            }
        });

        Schema::dropIfExists('provisioning_workflow_templates');
    }
};
