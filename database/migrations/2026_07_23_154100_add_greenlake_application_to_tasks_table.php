<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('greenlake_application_id')->nullable()->after('greenlake_location_name');
            $table->string('greenlake_application_region')->nullable()->after('greenlake_application_id');
            $table->string('greenlake_application_name')->nullable()->after('greenlake_application_region');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'greenlake_application_id',
                'greenlake_application_region',
                'greenlake_application_name',
            ]);
        });
    }
};
