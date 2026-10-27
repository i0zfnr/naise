<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('paid_at')->nullable()->after('payment_reference')->index();
        });

        DB::table('orders')
            ->whereIn('status', ['paid', 'completed', 'picked_up'])
            ->update([
                'payment_status' => 'paid',
                'paid_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['paid_at']);
            $table->dropColumn('paid_at');
        });
    }
};
