<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->bigInteger('prepaid_balance')->default(0)->after('rent_price');
            $table->date('paid_until')->nullable()->after('prepaid_balance');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['prepaid_balance', 'paid_until']);
        });
    }
};
