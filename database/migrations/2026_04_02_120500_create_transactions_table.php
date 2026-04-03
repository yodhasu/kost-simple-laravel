<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('kost_id', 36)->nullable();
            $table->char('tenant_id', 36)->nullable();
            $table->string('category', 100)->nullable();
            $table->bigInteger('amount');
            $table->date('transaction_date');
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->char('region_id', 36)->nullable();
            $table->string('financial_class', 50);
            $table->boolean('is_frozen')->default(false);
            $table->char('reference_id', 36)->nullable();

            $table->index(['region_id', 'transaction_date']);
            $table->index(['kost_id', 'transaction_date']);
            $table->index(['tenant_id', 'transaction_date']);
            $table->foreign('kost_id')->references('id')->on('kosts')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('region_id')->references('id')->on('regions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
