<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CMS pages. Also holds the SEO overrides and the opening answer block
     * for templated routes (home, prices, faq ...), keyed by slug.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 150)->unique();
            $table->string('title');
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->string('og_image')->nullable();
            $table->text('answer')->nullable();
            $table->json('body')->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_indexable')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
