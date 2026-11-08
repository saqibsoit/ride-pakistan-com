<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer bought, frozen when the order is placed (milestone
 * 10a): later price, session or validity changes to the product never
 * change an existing order or the credit pack it grants. The amount was
 * already stored (amount_minor, order_items.unit_price_minor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('product_name', 120)->nullable()->after('product_id');
            $table->string('product_type', 20)->nullable()->after('product_name');
            $table->unsignedSmallInteger('sessions_count')->nullable()->after('product_type');
            $table->unsignedSmallInteger('validity_days')->nullable()->after('sessions_count');
        });

        // Existing orders: today's product values are the best record there is.
        DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->update([
                'orders.product_name' => DB::raw('products.name'),
                'orders.product_type' => DB::raw('products.type'),
                'orders.sessions_count' => DB::raw('products.sessions_count'),
                'orders.validity_days' => DB::raw('products.validity_days'),
            ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['product_name', 'product_type', 'sessions_count', 'validity_days']);
        });
    }
};
