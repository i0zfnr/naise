<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 60);
            $table->string('provider_event_id', 150);
            $table->string('event_type', 80);
            $table->string('order_reference', 100)->index();
            $table->string('payment_reference', 100)->index();
            $table->char('payload_sha256', 64);
            $table->timestamp('received_at');
            $table->unique(['provider', 'provider_event_id'], 'payment_events_provider_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
