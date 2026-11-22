<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Exports\RunExport;
use App\Enums\BookingStatus;
use App\Filament\Pages\Exports;
use App\Models\Booking;
use App\Models\Slot;
use App\Models\User;
use App\Support\Events\EventAnswers;
use App\Support\Money;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An event date's attendee list as CSV (decided 2026-11-22): each person
 * with their tier, check-in, custom answers and the booking's contact,
 * deposit and balance. Admins only, sharing the exports limit (10 an hour)
 * and audit log ("exports"); cells that would run as a formula are
 * neutralised (RunExport::cell).
 */
final class ExportRunSheet
{
    public function handle(Slot $slot, User $admin): StreamedResponse
    {
        $key = 'exports:'.$admin->id;

        if (RateLimiter::tooManyAttempts($key, Exports::PER_HOUR)) {
            throw new ThrottleRequestsException('Too many exports. Try again in an hour.');
        }

        RateLimiter::hit($key, 3600);

        $slot->loadMissing('rideEvent');
        $bookings = Booking::query()
            ->where('slot_id', $slot->id)
            ->where('status', '!=', BookingStatus::Cancelled)
            ->with(['rider', 'user', 'order.balance', 'orderItem.tier'])
            ->orderBy('order_id')
            ->orderBy('id')
            ->get();

        $questions = (array) ($slot->rideEvent->questions ?? []);
        $headers = ['Order reference', 'Person', 'Tier', 'Status', 'Booked by', 'Email', 'WhatsApp', 'Paid now (PKR)', 'Balance (PKR)', 'Balance status'];

        foreach ($questions as $question) {
            $headers[] = (string) ($question['label'] ?? 'Question');
        }

        activity('exports')
            ->causedBy($admin)
            ->performedOn($slot)
            ->event('run_sheet_exported')
            ->withProperties(['rows' => $bookings->count()])
            ->log('Run sheet exported for '.$slot->dateRangeLabel());

        $filename = 'run-sheet-'.Str::slug(($slot->rideEvent->title ?? 'session').'-'.$slot->localStartsAt()->format('Y-m-d')).'.csv';

        return response()->streamDownload(function () use ($bookings, $headers, $questions): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fputcsv($out, $headers, escape: '');

            foreach ($bookings as $booking) {
                $order = $booking->order;
                $answers = [...($order->answers ?? []), ...($booking->orderItem->answers ?? [])];
                $row = [
                    $order->reference ?? '',
                    $booking->rider->name,
                    $booking->orderItem->tier->name ?? '',
                    $booking->status->label(),
                    $order->contact_name ?? $booking->user->name ?? '',
                    $booking->user->email,
                    $booking->contactWhatsApp() ?? '',
                    $order === null ? '' : Money::decimal($order->amount_minor),
                    $order?->balance === null ? '' : Money::decimal($order->balance->amount_minor),
                    $order?->balance?->status->getLabel() ?? '',
                ];

                foreach ($questions as $question) {
                    $row[] = $answers[(string) ($question['key'] ?? '')] ?? '';
                }

                fputcsv($out, array_map(RunExport::cell(...), $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * Labelled answers of one booking (the order's and the person's), for the run sheet.
     *
     * @return list<array{label: string, answer: string}>
     */
    public static function answersFor(Booking $booking): array
    {
        $questions = (array) ($booking->order->meta['event_questions'] ?? $booking->slot->rideEvent->questions ?? []);

        return EventAnswers::labelled($questions, [...($booking->order->answers ?? []), ...($booking->orderItem->answers ?? [])]);
    }
}
