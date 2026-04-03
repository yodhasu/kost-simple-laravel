<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('kost_id', 36);
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->bigInteger('rent_price')->nullable();
            $table->string('status', 50)->default('aktif');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->integer('trash_fee')->nullable();
            $table->integer('security_fee')->nullable();
            $table->integer('admin_fee')->nullable();

            $table->foreign('kost_id')->references('id')->on('kosts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
