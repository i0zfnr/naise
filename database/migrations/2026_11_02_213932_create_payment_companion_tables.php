<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->string('credential_version', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('device_id')->constrained('payment_devices');
            $table->string('fingerprint', 64)->unique();
            $table->string('reference', 40);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('observed_at');
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('status', 20);
            $table->string('message');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notification_events');
        Schema::dropIfExists('payment_devices');
    }
};
