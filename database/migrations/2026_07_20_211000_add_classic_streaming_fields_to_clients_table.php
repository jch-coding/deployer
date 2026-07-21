<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('classic_streaming_hostname')->nullable()->after('classic_webhook_wid');
            $table->text('classic_streaming_key')->nullable()->after('classic_streaming_hostname');
            $table->string('classic_streaming_username')->nullable()->after('classic_streaming_key');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'classic_streaming_hostname',
                'classic_streaming_key',
                'classic_streaming_username',
            ]);
        });
    }
};
