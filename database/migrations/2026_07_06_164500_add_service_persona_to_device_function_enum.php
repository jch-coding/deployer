<?php

use App\DeviceFunction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replaceDeviceFunctionConstraint(
            array_map(
                fn (DeviceFunction $case) => $case->name,
                DeviceFunction::cases(),
            ),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->replaceDeviceFunctionConstraint(
            array_map(
                fn (DeviceFunction $case) => $case->name,
                array_filter(
                    DeviceFunction::cases(),
                    fn (DeviceFunction $case) => $case !== DeviceFunction::SERVICE_PERSONA,
                ),
            ),
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    private function replaceDeviceFunctionConstraint(array $allowed): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $quoted = collect($allowed)
                ->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")
                ->implode(', ');

            DB::statement(
                "ALTER TABLE devices MODIFY device_function ENUM({$quoted}) NOT NULL",
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE devices DROP CONSTRAINT IF EXISTS devices_device_function_check');

            $quoted = collect($allowed)
                ->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")
                ->implode(', ');

            DB::statement(
                'ALTER TABLE devices ADD CONSTRAINT devices_device_function_check CHECK (device_function in ('.$quoted.'))',
            );
        }
    }
};
