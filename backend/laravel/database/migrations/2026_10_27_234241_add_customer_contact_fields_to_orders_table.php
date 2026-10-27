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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_name', 120)->nullable()->after('user_id');
            $table->string('customer_phone', 30)->nullable()->after('customer_name');
        });

        DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->whereNull('orders.customer_name')
            ->update([
                'orders.customer_name' => DB::raw('users.name'),
                'orders.customer_phone' => DB::raw('users.whatsapp_number'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['customer_name', 'customer_phone']);
        });
    }
};
