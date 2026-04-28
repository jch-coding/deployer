<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        foreach ([
            'default' => 'q0',
            'first' => 'q1',
            'second' => 'q2',
            'third' => 'q3',
            'fourth' => 'q4',
        ] as $legacy => $shard) {
            DB::table('tasks')->where('job_queue', $legacy)->update(['job_queue' => $shard]);
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE tasks ALTER COLUMN job_queue SET DEFAULT 'q0'");
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE tasks MODIFY job_queue VARCHAR(32) NOT NULL DEFAULT 'q0'");
        }
        // SQLite: column default change is limited; new rows should set job_queue in application code.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'q0' => 'default',
            'q1' => 'first',
            'q2' => 'second',
            'q3' => 'third',
            'q4' => 'fourth',
        ] as $shard => $legacy) {
            DB::table('tasks')->where('job_queue', $shard)->update(['job_queue' => $legacy]);
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE tasks ALTER COLUMN job_queue SET DEFAULT 'default'");
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE tasks MODIFY job_queue VARCHAR(32) NOT NULL DEFAULT 'default'");
        }
    }
};
