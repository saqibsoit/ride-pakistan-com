<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Support\BusinessFacts;
use App\Support\Seo\SchemaFactory;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;

final class GuideController extends Controller
{
    public function index(BusinessFacts $facts, Seo $seo): View
    {
        $posts = Post::query()->published()->latest('published_at')->get();

        return view('public.guides.index', [
            'facts' => $facts,
            'posts' => $posts,
            'seo' => $seo->make(
                title: 'Horse riding guides',
                description: 'Straight answers for new riders in Islamabad: beginner lessons, what to wear, safety, and choosing between private and group sessions.',
                page: Page::query()->firstWhere('slug', 'guides'),
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Guides'],
                ],
                // An empty index is thin content: keep it out of search until guides exist.
                indexable: $posts->isNotEmpty(),
                image: 'meet.jpg',
            ),
        ]);
    }

    public function show(Post $post, BusinessFacts $facts, SchemaFactory $schema, Seo $seo): View
    {
        abort_unless($post->isPublished(), 404);

        return view('public.guides.show', [
            'post' => $post,
            'facts' => $facts,
            'more' => Post::query()->published()->whereKeyNot($post->id)->latest('published_at')->limit(3)->get(),
            'seo' => $seo->make(
                title: $post->meta_title ?: $post->title,
                description: $post->meta_description ?: $post->excerpt,
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Guides', 'url' => route('guides.index')],
                    ['label' => $post->title],
                ],
                schema: [$schema->article($post)],
                image: $post->cover_image ?? 'meet.jpg',
                imageAlt: $post->cover_image_alt,
                ogType: 'article',
            ),
        ]);
    }
}
