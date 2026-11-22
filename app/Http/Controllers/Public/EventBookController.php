<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\RideEvent;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** /events/{slug}/book: the event booking form (Livewire). Not cached, noindex. */
final class EventBookController extends Controller
{
    public function __invoke(Request $request, RideEvent $event, Seo $seo): View
    {
        abort_unless($event->isPublished(), 404);

        return view('public.events.book', [
            'event' => $event,
            'resume' => $request->boolean('resume'),
            'seo' => $seo->make(
                title: 'Book '.$event->title,
                description: (string) $event->summary,
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Events', 'url' => route('events.index')],
                    ['label' => $event->title, 'url' => route('events.show', $event)],
                    ['label' => 'Book'],
                ],
                indexable: false,
            ),
        ]);
    }
}
