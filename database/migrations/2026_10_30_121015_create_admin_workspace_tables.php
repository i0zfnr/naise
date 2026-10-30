<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_documents', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->longText('value');
            $table->timestamps();
        });
        DB::table('admin_documents')->insert(['key' => 'shifts', 'value' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('preparation_status')->default('queued');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('preparation_status');
        });
        Schema::dropIfExists('admin_documents');
    }
};
