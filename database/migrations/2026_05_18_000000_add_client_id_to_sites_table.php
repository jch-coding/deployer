<?php

use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignIdFor(Client::class)->nullable()->after('name')->constrained()->cascadeOnDelete();
        });

        $siteIds = DB::table('sites')->pluck('id');

        foreach ($siteIds as $siteId) {
            $clientIds = DB::table('devices')
                ->where('site_id', $siteId)
                ->whereNotNull('client_id')
                ->distinct()
                ->pluck('client_id')
                ->values()
                ->all();

            if ($clientIds === []) {
                DB::table('sites')->where('id', $siteId)->delete();

                continue;
            }

            $site = DB::table('sites')->where('id', $siteId)->first();

            if ($site === null) {
                continue;
            }

            $primaryClientId = $clientIds[0];
            DB::table('sites')->where('id', $siteId)->update(['client_id' => $primaryClientId]);

            foreach (array_slice($clientIds, 1) as $additionalClientId) {
                $newSiteId = DB::table('sites')->insertGetId([
                    'name' => $site->name,
                    'client_id' => $additionalClientId,
                    'scope_id' => null,
                    'classic_id' => null,
                    'created_at' => $site->created_at ?? now(),
                    'updated_at' => now(),
                ]);

                DB::table('devices')
                    ->where('site_id', $siteId)
                    ->where('client_id', $additionalClientId)
                    ->update(['site_id' => $newSiteId]);
            }
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
            $table->unique(['client_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'name']);
            $table->dropConstrainedForeignIdFor(Client::class);
        });
    }
};
