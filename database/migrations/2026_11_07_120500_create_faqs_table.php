<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table): void {
            $table->id();
            $table->string('question', 300);
            $table->text('answer');
            $table->string('topic', 100)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('page_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['is_published', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
