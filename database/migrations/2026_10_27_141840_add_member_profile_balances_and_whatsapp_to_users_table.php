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
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('total_points')->default(0)->after('points');
            $table->unsignedInteger('redeemed_points')->default(0)->after('total_points');
            $table->string('whatsapp_number', 30)->nullable()->after('email');
        });

        DB::table('users')->update(['total_points' => DB::raw('points')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['total_points', 'redeemed_points', 'whatsapp_number']);
        });
    }
};
