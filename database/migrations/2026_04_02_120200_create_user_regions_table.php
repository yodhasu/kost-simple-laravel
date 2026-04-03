<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_regions', function (Blueprint $table) {
            $table->char('user_id', 36);
            $table->char('region_id', 36);
            $table->timestamp('assigned_at')->useCurrent();

            $table->primary(['user_id', 'region_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('region_id')->references('id')->on('regions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_regions');
    }
};
