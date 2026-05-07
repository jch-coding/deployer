<?php

use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-persist bearer_token and classic_refresh_token so values stored as plaintext
     * are encrypted with the same mechanism as the Client model's `encrypted` cast.
     *
     * Rows that are already encrypted are left unchanged (decrypt succeeds).
     */
    public function up(): void
    {
        $encrypter = Client::currentEncrypter();

        DB::table('clients')->orderBy('id')->chunkById(100, function ($rows) use ($encrypter): void {
            foreach ($rows as $row) {
                $updates = [];

                foreach (['bearer_token', 'classic_refresh_token'] as $column) {
                    $raw = $row->{$column} ?? null;
                    if (! is_string($raw) || $raw === '') {
                        continue;
                    }

                    try {
                        $encrypter->decrypt($raw, false);
                    } catch (\Throwable) {
                        $updates[$column] = $encrypter->encrypt($raw, false);
                    }
                }

                if ($updates !== []) {
                    DB::table('clients')->where('id', $row->id)->update($updates);
                }
            }
        });
    }

    /**
     * Reverse migrations cannot safely restore plaintext tokens.
     */
    public function down(): void
    {
        //
    }
};
