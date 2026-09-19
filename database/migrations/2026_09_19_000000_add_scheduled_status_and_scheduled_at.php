<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('expires_at');
        });

        Schema::table('provisioning_workflows', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('started_at');
        });

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE tasks MODIFY status ENUM('IN_PROGRESS','FAILED','TIMED_OUT','CANCELLED','COMPLETED','SCHEDULED') NOT NULL DEFAULT 'IN_PROGRESS'");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('IN_PROGRESS','FAILED','TIMED_OUT','CANCELLED','COMPLETED','SCHEDULED'))");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE tasks MODIFY status ENUM('IN_PROGRESS','FAILED','TIMED_OUT','CANCELLED','COMPLETED') NOT NULL DEFAULT 'IN_PROGRESS'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('IN_PROGRESS','FAILED','TIMED_OUT','CANCELLED','COMPLETED'))");
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });

        Schema::table('provisioning_workflows', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });
    }
};
