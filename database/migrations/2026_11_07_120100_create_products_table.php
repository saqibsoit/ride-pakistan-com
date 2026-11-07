<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('type', 20)->index();
            $table->string('category', 20)->index();
            $table->string('name');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedSmallInteger('sessions_count')->default(1);
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);

            // Content
            $table->string('short_description', 300);
            $table->text('long_description')->nullable();
            $table->text('who_for')->nullable();
            $table->json('what_to_bring')->nullable();
            $table->json('whats_included')->nullable();
            $table->json('good_to_know')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(120);
            $table->string('group_size')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('cover_image_alt')->nullable();
            $table->json('gallery')->nullable();
            $table->string('video_url')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();

            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
