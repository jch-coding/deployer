<?php
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
        Schema::create('lacp_profiles', function (Blueprint $table) {
            $table->id();
            $table->enum('mode', ['ACTIVE', 'PASSIVE', 'AUTO'])->default('ACTIVE');
            $table->integer('port_id')->unsigned()->nullable();
            $table->enum('rate', ['FAST', 'SLOW'])->default('SLOW');
            $table->string('port_list');
            $table->enum('trunk_type', ['LACP', 'TRUNK', 'DT_TRUNK', 'MULTI_CHASSIS', 'MULTI_CHASSIS_STATIC'])->default('LACP');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lacp_profiles');
    }
};
