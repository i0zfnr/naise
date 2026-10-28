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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_name', 120)->nullable()->after('user_id');
            $table->string('customer_phone', 30)->nullable()->after('customer_name');
        });

        DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->whereNull('orders.customer_name')
            ->select('orders.id', 'users.name', 'users.whatsapp_number')
            ->orderBy('orders.id')
            ->get()
            ->each(static function (object $customer): void {
                DB::table('orders')->where('id', $customer->id)->update([
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->whatsapp_number,
                ]);
            });
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
