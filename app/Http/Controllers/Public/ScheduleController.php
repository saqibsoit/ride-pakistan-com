<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\Availability;
use App\Support\BusinessFacts;
use App\Support\Seo\Seo;
use App\Support\SessionTimes;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /schedule: week calendar (inspiration 02). ?week=YYYY-MM-DD pages through
 * the current week and the next few; anything else redirects to the
 * current week so crawlers and caches only ever see a bounded set of URLs.
 * Live seats arrive with slots in milestones 3 and 5.
 */
final class ScheduleController extends Controller
{
    public function __invoke(Request $request, BusinessFacts $facts, Availability $availability, Seo $seo): View|RedirectResponse
    {
        $current = SessionTimes::weekStart(SessionTimes::now());
        $last = $current->addWeeks((int) config('site.schedule_weeks_ahead'));
        $monday = $current;

        // ?type=group|private filters the slots; anything else redirects so the
        // set of cacheable URLs stays small.
        $type = null;

        if ($request->query->has('type')) {
            $type = $request->query('type');

            if (! in_array($type, [Availability::TYPE_GROUP, Availability::TYPE_PRIVATE], true)) {
                return redirect()->route('schedule', array_filter(['week' => $request->query('week')], 'is_string'));
            }
        }

        if ($request->query->has('week')) {
            $requested = $request->query('week');
            // Carbon throws on malformed dates; treat that like any other bad value.
            $parsed = is_string($requested)
                ? rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $requested, $current->getTimezone()), null, false)
                : null;

            if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $requested) {
                return redirect()->route('schedule');
            }

            $monday = SessionTimes::weekStart($parsed);

            if ($monday->lessThan($current) || $monday->greaterThan($last) || $monday->format('Y-m-d') !== $requested) {
                return redirect()->route('schedule');
            }
        }

        return view('public.schedule', [
            'facts' => $facts,
            'days' => $availability->week($monday, $type),
            'type' => $type,
            'monday' => $monday,
            'previous' => $monday->greaterThan($current) ? $monday->subWeek() : null,
            'next' => $monday->lessThan($last) ? $monday->addWeek() : null,
            'isCurrent' => $monday->equalTo($current),
            'seo' => $seo->make(
                title: 'Weekly riding schedule',
                description: 'Horse riding sessions in Islamabad '.$facts->scheduleText().'. See this week’s times and book a slot.',
                page: Page::query()->firstWhere('slug', 'schedule'),
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Schedule'],
                ],
                image: 'gallop.jpg',
            ),
        ]);
    }
}
