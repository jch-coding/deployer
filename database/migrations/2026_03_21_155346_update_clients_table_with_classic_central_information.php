<?php

use App\ClassicBaseUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->enum('classic_base_url', ClassicBaseUrl::cases())->default(ClassicBaseUrl::US1);
            $table->string('classic_username')->nullable();
            $table->string('classic_password')->nullable();
            $table->string('classic_client_id')->nullable();
            $table->string('classic_client_secret')->nullable();
            $table->string('classic_access_token')->nullable();
            $table->string('classic_refresh_token')->nullable();
            $table->integer('classic_expires_in')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('classic_base_url');
            $table->dropColumn('classic_username');
            $table->dropColumn('classic_password');
            $table->dropColumn('classic_client_id');
            $table->dropColumn('classic_client_secret');
            $table->dropColumn('classic_access_token');
            $table->dropColumn('classic_refresh_token');
            $table->dropColumn('classic_expires_in');
        });
    }
};
