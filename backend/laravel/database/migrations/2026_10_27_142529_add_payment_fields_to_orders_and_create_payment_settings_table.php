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
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_method')->default('cash')->after('status');
            $table->string('payment_status')->default('cash_on_pickup')->after('payment_method')->index();
            $table->string('receipt_path')->nullable()->after('payment_status');
            $table->string('payment_reference')->nullable()->after('receipt_path')->index();
        });

        Schema::create('payment_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('merchant_name')->nullable();
            $table->string('qr_code_path')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_settings');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['payment_method', 'payment_status', 'receipt_path', 'payment_reference']);
        });
    }
};
