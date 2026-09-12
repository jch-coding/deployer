<?php

use App\Models\Client;
use App\Models\User;
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
        Schema::create('device_interface_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Client::class)->constrained()->cascadeOnDelete();
            $table->string('serial', 16);
            $table->string('device_name')->default('');
            $table->string('device_type')->default('');
            $table->string('device_function')->default('');
            $table->json('interfaces');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['user_id', 'client_id', 'serial']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_interface_snapshots');
    }
};
