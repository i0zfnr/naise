<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $hasDuplicateReferences = DB::table('orders')
            ->whereNotNull('payment_reference')
            ->select('payment_reference')
            ->groupBy('payment_reference')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateReferences) {
            throw new RuntimeException('Cannot enforce unique payment references because duplicate references already exist. Review the affected orders before retrying.');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->unique('payment_reference', 'orders_payment_reference_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_payment_reference_unique');
        });
    }
};
