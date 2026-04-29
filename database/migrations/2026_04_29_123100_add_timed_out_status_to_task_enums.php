<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE tasks MODIFY status ENUM('IN_PROGRESS','FAILED','TIMED_OUT','CANCELLED','COMPLETED') NOT NULL DEFAULT 'IN_PROGRESS'");
            DB::statement("ALTER TABLE device_task MODIFY status ENUM('PAUSED','IN_PROGRESS','COMPLETED','FAILED','TIMED_OUT','PENDING','CANCELLED') NOT NULL DEFAULT 'PENDING'");
            DB::statement("ALTER TABLE device_interface_task MODIFY status ENUM('PAUSED','IN_PROGRESS','COMPLETED','FAILED','TIMED_OUT','PENDING') NOT NULL DEFAULT 'PENDING'");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check");
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('IN_PROGRESS','FAILED','TIMED_OUT','CANCELLED','COMPLETED'))");

            DB::statement("ALTER TABLE device_task DROP CONSTRAINT IF EXISTS device_task_status_check");
            DB::statement("ALTER TABLE device_task ADD CONSTRAINT device_task_status_check CHECK (status IN ('PAUSED','IN_PROGRESS','COMPLETED','FAILED','TIMED_OUT','PENDING','CANCELLED'))");

            DB::statement("ALTER TABLE device_interface_task DROP CONSTRAINT IF EXISTS device_interface_task_status_check");
            DB::statement("ALTER TABLE device_interface_task ADD CONSTRAINT device_interface_task_status_check CHECK (status IN ('PAUSED','IN_PROGRESS','COMPLETED','FAILED','TIMED_OUT','PENDING'))");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE tasks MODIFY status ENUM('IN_PROGRESS','FAILED','CANCELLED','COMPLETED') NOT NULL DEFAULT 'IN_PROGRESS'");
            DB::statement("ALTER TABLE device_task MODIFY status ENUM('PAUSED','IN_PROGRESS','COMPLETED','FAILED','PENDING','CANCELLED') NOT NULL DEFAULT 'PENDING'");
            DB::statement("ALTER TABLE device_interface_task MODIFY status ENUM('PAUSED','IN_PROGRESS','COMPLETED','FAILED','PENDING') NOT NULL DEFAULT 'PENDING'");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check");
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('IN_PROGRESS','FAILED','CANCELLED','COMPLETED'))");

            DB::statement("ALTER TABLE device_task DROP CONSTRAINT IF EXISTS device_task_status_check");
            DB::statement("ALTER TABLE device_task ADD CONSTRAINT device_task_status_check CHECK (status IN ('PAUSED','IN_PROGRESS','COMPLETED','FAILED','PENDING','CANCELLED'))");

            DB::statement("ALTER TABLE device_interface_task DROP CONSTRAINT IF EXISTS device_interface_task_status_check");
            DB::statement("ALTER TABLE device_interface_task ADD CONSTRAINT device_interface_task_status_check CHECK (status IN ('PAUSED','IN_PROGRESS','COMPLETED','FAILED','PENDING'))");
        }
    }
};
