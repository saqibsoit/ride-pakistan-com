<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line per rider (and slot, for single and private sessions).
     * Packages may be bought before riders are known, so both are nullable.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slot_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('rider_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_price_minor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
