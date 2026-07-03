<?php

use App\ClassicBaseUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceClassicBaseUrlCheckConstraint(
            array_map(
                fn (ClassicBaseUrl $case) => $case->value,
                ClassicBaseUrl::cases(),
            ),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceClassicBaseUrlCheckConstraint(
            array_map(
                fn (ClassicBaseUrl $case) => $case->value,
                array_filter(
                    ClassicBaseUrl::cases(),
                    fn (ClassicBaseUrl $case) => $case !== ClassicBaseUrl::UK_WEST2,
                ),
            ),
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    private function replaceClassicBaseUrlCheckConstraint(array $allowed): void
    {
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_classic_base_url_check');

        $quoted = collect($allowed)
            ->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")
            ->implode(', ');

        DB::statement(
            'ALTER TABLE clients ADD CONSTRAINT clients_classic_base_url_check CHECK ("classic_base_url" in ('.$quoted.'))',
        );
    }
};
