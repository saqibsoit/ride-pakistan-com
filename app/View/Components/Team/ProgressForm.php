<?php

declare(strict_types=1);

namespace App\View\Components\Team;

use App\Models\Booking;
use App\Models\RiderSkill;
use App\Models\Skill;
use App\Models\Slot;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;

/**
 * Run sheet rider card: tick skills and write the customer's progress note
 * (milestone 10d). Shown once the session has started, to the people who
 * work it (BookingPolicy::recordProgress).
 *
 * <x-team.progress-form :session="$slot" :booking="$booking" />
 */
final class ProgressForm extends Component
{
    /** @var Collection<int, Skill> */
    public Collection $skills;

    /** @var array<int, RiderSkill> */
    public array $reached = [];

    public bool $allowed;

    public function __construct(public Slot $session, public Booking $booking)
    {
        $this->allowed = Gate::allows('recordProgress', $booking);
        $this->skills = $this->allowed ? Skill::query()->active()->ordered()->get() : new Collection;

        if ($this->allowed) {
            $this->reached = RiderSkill::query()->where('rider_id', $booking->rider_id)->get()->keyBy('skill_id')->all();
        }
    }

    public function render(): View
    {
        return view('components.team.progress-form');
    }
}
