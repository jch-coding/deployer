<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('composite_group_id')->nullable()->after('deployment_id');
            $table->string('composite_kind')->nullable()->after('composite_group_id');
            $table->unsignedTinyInteger('composite_order')->nullable()->after('composite_kind');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['composite_group_id', 'composite_kind', 'composite_order']);
        });
    }
};
