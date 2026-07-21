<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('greenlake_location_id')->nullable()->after('greenlake_tags');
            $table->string('greenlake_location_name')->nullable()->after('greenlake_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['greenlake_location_id', 'greenlake_location_name']);
        });
    }
};
