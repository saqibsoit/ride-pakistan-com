<?php

declare(strict_types=1);

namespace App\Livewire\Manage;

use App\Actions\Booking\BookWithCredit;
use App\Actions\Riders\SaveRider;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Support\Availability;
use App\Support\GuestAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /manage/{reference}/book (guest checkout, decided 2026-11-12): book group
 * sessions with the credits of the package this order bought, without
 * signing in. Only that pack is spent (BookWithCredit's $only), and the
 * riders offered are the ones on this order plus any added here: never the
 * account's other riders. Access is re-checked on every request
 * (GuestAccess), since Livewire updates don't pass the route middleware.
 */
final class PackBooking extends Component
{
    #[Locked]
    public string $reference = '';

    public ?int $slotId = null;

    /** @var list<int> */
    public array $riderIds = [];

    public string $newRiderName = '';

    /** @var list<int> riders added on this page, offered alongside the order's own */
    #[Locked]
    public array $added = [];

    #[Locked]
    public ?string $error = null;

    public function mount(string $reference): void
    {
        $this->reference = $reference;
        $this->pack(); // 404 for anyone else
    }

    public function addRider(SaveRider $save): void
    {
        $this->validate(['newRiderName' => ['required', 'string', 'max:120']], ['newRiderName.required' => 'Enter the rider’s name.']);

        $rider = $save->handle($this->order()->user, ['name' => $this->newRiderName]);
        $this->added[] = $rider->id;
        $this->riderIds[] = $rider->id;
        $this->newRiderName = '';
    }

    public function book(BookWithCredit $book): mixed
    {
        $this->error = null;
        $pack = $this->pack();
        $allowed = $this->riders()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $riderIds = array_values(array_intersect(array_map('intval', $this->riderIds), $allowed));

        if ($this->slotId === null) {
            $this->addError('slotId', 'Choose a session.');

            return null;
        }

        if ($riderIds === []) {
            $this->addError('riderIds', 'Choose at least one rider.');

            return null;
        }

        try {
            $book->handle($this->order()->user, $this->slotId, $riderIds, only: $pack);
        } catch (BookingRejected $e) {
            $this->error = $e->getMessage();

            return null;
        }

        session()->flash('status', count($riderIds) === 1 ? 'Booked. We’ve emailed the ticket.' : 'Booked. We’ve emailed the tickets.');

        return $this->redirectRoute('manage.show', $this->reference);
    }

    public function render(Availability $availability): View
    {
        $pack = $this->pack();

        return view('livewire.manage.pack-booking', [
            'pack' => $pack,
            // Not "slots": Livewire views reserve that name.
            'sessions' => $availability->upcoming(24, Availability::TYPE_GROUP, 8)
                ->filter(fn (Slot $slot): bool => $pack->covers($slot))
                ->values(),
            'riders' => $this->riders(),
        ]);
    }

    private function order(): Order
    {
        $user = Auth::user();
        $order = app(GuestAccess::class)->findOrder($user instanceof User ? $user : null, $this->reference);

        abort_if($order === null, 404);

        return $order;
    }

    private function pack(): CreditPack
    {
        $pack = $this->order()->creditPack;

        abort_if($pack === null || ! $pack->isUsable() || $pack->creditsRemaining() < 1, 404);

        return $pack;
    }

    /**
     * Riders on this order or its package's bookings, plus those added here.
     *
     * @return Collection<int, Rider>
     */
    private function riders(): Collection
    {
        $order = $this->order();
        $packId = $order->creditPack?->id;

        $ids = OrderItem::query()->where('order_id', $order->id)->whereNotNull('rider_id')->pluck('rider_id')
            ->merge(Booking::query()->where(fn ($q) => $q->where('order_id', $order->id)->when($packId !== null, fn ($q) => $q->orWhere('credit_pack_id', $packId)))->pluck('rider_id'))
            ->merge($this->added)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        return Rider::query()->where('user_id', $order->user_id)->whereKey($ids)->orderBy('name')->get();
    }
}
