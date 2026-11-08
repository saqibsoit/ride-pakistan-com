<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Progress\SuggestNextStep;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Rider;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /account/progress (milestone 10d): each of the customer's own riders
 * with the skill checklist, the trainer's progress notes and a next step.
 * Only the signed-in customer's riders are ever loaded; the internal
 * session note is never shown here.
 */
final class ProgressController extends Controller
{
    public const NOTES_SHOWN = 5;

    public function __invoke(Request $request, SuggestNextStep $suggest): View
    {
        /** @var User $user */
        $user = $request->user();

        $skills = Skill::query()->ordered()->get();
        $active = $skills->where('is_active', true);

        $riders = $user->riders()
            ->with(['riderSkills'])
            ->orderBy('name')
            ->get()
            ->map(function (Rider $rider) use ($active, $skills): array {
                $reached = $rider->riderSkills->keyBy('skill_id');

                return [
                    'rider' => $rider,
                    // Active skills, plus switched-off ones the rider already reached.
                    'skills' => $skills->filter(fn (Skill $s): bool => $s->is_active || $reached->has($s->id))->values(),
                    'reached' => $reached,
                    'count' => $active->filter(fn (Skill $s): bool => $reached->has($s->id))->count(),
                    'notes' => Booking::query()
                        ->where('rider_id', $rider->id)
                        ->whereNotNull('progress_note')
                        ->with('slot')
                        ->past()
                        ->limit(self::NOTES_SHOWN)
                        ->get(),
                ];
            });

        $best = (int) $riders->max('count');

        return view('account.progress', [
            'riders' => $riders,
            'total' => $active->count(),
            'next' => $riders->isEmpty() ? null : $suggest->handle($user, $best, $active->count()),
            'timezone' => (string) config('app.display_timezone'),
        ]);
    }
}
