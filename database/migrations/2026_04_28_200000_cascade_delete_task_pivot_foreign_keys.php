<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('device_task', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropForeign(['task_id']);
        });
        Schema::table('device_task', function (Blueprint $table) {
            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });

        Schema::table('device_interface_task', function (Blueprint $table) {
            $table->dropForeign(['device_interface_id']);
            $table->dropForeign(['task_id']);
        });
        Schema::table('device_interface_task', function (Blueprint $table) {
            $table->foreign('device_interface_id')->references('id')->on('device_interfaces')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });

        Schema::table('task_user', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
        });
        Schema::table('task_user', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_user', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
        });
        Schema::table('task_user', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks');
        });

        Schema::table('device_interface_task', function (Blueprint $table) {
            $table->dropForeign(['device_interface_id']);
            $table->dropForeign(['task_id']);
        });
        Schema::table('device_interface_task', function (Blueprint $table) {
            $table->foreign('device_interface_id')->references('id')->on('device_interfaces');
            $table->foreign('task_id')->references('id')->on('tasks');
        });

        Schema::table('device_task', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropForeign(['task_id']);
        });
        Schema::table('device_task', function (Blueprint $table) {
            $table->foreign('device_id')->references('id')->on('devices');
            $table->foreign('task_id')->references('id')->on('tasks');
        });
    }
};
