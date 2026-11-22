<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\RideEvent;
use App\Models\RideEventCategory;
use App\Support\BusinessFacts;
use App\Support\Events\EventAvailability;
use App\Support\Events\EventTerms;
use App\Support\Seo\SchemaFactory;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /events and /events/{slug} (decided 2026-11-22). The list shows published
 * events with a date to come (past ones are hidden), filterable by
 * category; the page follows inspiration 01 with a sticky "Book" box,
 * every date with its availability, the content blocks in admin order and
 * Event structured data.
 */
final class EventController extends Controller
{
    public function index(Request $request, Seo $seo, BusinessFacts $facts): View
    {
        $categories = RideEventCategory::query()->where('is_active', true)->ordered()
            ->whereHas('events', fn ($q) => $q->listed())->get();
        $requested = $request->query('category');
        $active = is_string($requested) ? $categories->firstWhere('slug', $requested) : null;
        $activeId = $active?->id;

        $events = RideEvent::query()->listed()->ordered()->with(['category', 'tiers'])
            ->when($activeId !== null, fn ($q) => $q->where('ride_event_category_id', $activeId))
            ->get()
            ->map(fn (RideEvent $event): EventAvailability => EventAvailability::for($event));

        return view('public.events.index', [
            'events' => $events,
            'categories' => $categories,
            'active' => $active?->slug,
            'facts' => $facts,
            'seo' => $seo->make(
                title: 'Riding events, camps and trips in Islamabad',
                description: 'Day camps, workshops and rides with Ride Pakistan at '.$facts->venueWithLocation().'. Book online in PKR.',
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Events'],
                ],
                image: 'camp.jpg',
            ),
        ]);
    }

    public function show(RideEvent $event, Seo $seo, SchemaFactory $schema, BusinessFacts $facts): View
    {
        abort_unless($event->isPublished(), 404);

        $event->load(['category', 'tiers']);
        $availability = EventAvailability::for($event);
        $url = route('events.show', $event);
        $faq = [];

        foreach ($event->blocksOf('faq') as $block) {
            foreach ((array) ($block['items'] ?? []) as $item) {
                if (is_array($item) && is_string($item['question'] ?? null) && is_string($item['answer'] ?? null)) {
                    $faq[] = ['question' => $item['question'], 'answer' => $item['answer']];
                }
            }
        }

        $nodes = $schema->events($event, $availability);

        if ($faq !== []) {
            $nodes[] = $schema->faqItems($faq, $url);
        }

        return view('public.events.show', [
            'event' => $event,
            'availability' => $availability,
            'terms' => EventTerms::lines($event),
            'facts' => $facts,
            'others' => RideEvent::query()->listed()->ordered()->with(['category', 'tiers'])->whereKeyNot($event->id)->limit(3)->get(),
            'seo' => $seo->make(
                title: $event->meta_title ?: $event->title.' – Ride Pakistan, Islamabad',
                description: $event->meta_description ?: $event->summary,
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Events', 'url' => route('events.index')],
                    ['label' => $event->title],
                ],
                schema: $nodes,
                image: $event->cover_image ?? 'camp.jpg',
                imageAlt: $event->cover_image_alt,
            ),
        ]);
    }

    public static function bookUrl(RideEvent $event, ?int $slotId = null): string
    {
        return route('events.book', array_filter(['event' => $event->slug, 'date' => $slotId]));
    }
}
