<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_task_type_check');
            DB::statement('ALTER TABLE tasks ALTER COLUMN task_type TYPE VARCHAR(255) USING task_type::text');
        }
    }

    public function down(): void
    {
        // Reverting to enum would require restoring original enum values; omitted.
    }
};
