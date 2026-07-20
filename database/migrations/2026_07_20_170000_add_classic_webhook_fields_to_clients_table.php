<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('classic_webhook_secret')->nullable()->after('classic_expires_in');
            $table->string('classic_webhook_wid')->nullable()->after('classic_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['classic_webhook_secret', 'classic_webhook_wid']);
        });
    }
};
