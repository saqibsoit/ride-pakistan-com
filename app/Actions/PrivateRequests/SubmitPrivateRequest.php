<?php

declare(strict_types=1);

namespace App\Actions\PrivateRequests;

use App\Actions\Orders\GenerateOrderReference;
use App\Actions\Slots\SlotOverlap;
use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Exceptions\PrivateRequestRejected;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PrivateRequestReceived;
use App\Notifications\PrivateRequestToReview;
use App\Support\Notifier;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A signed-in customer asks for a private session at their own date and
 * time (prompt 15B). Checks the hours, the notice, the riders (their own,
 * 1 up to the riders-per-slot setting), a WhatsApp number on the account,
 * one open request at a time, and that the time doesn't clash with a
 * session already on the schedule. Then emails the customer and every
 * admin. Nothing is held until an admin approves.
 */
final class SubmitPrivateRequest
{
    public const PREFIX = 'RQ-';

    public function __construct(
        private readonly PrivateRequestRules $rules,
        private readonly SlotOverlap $overlap,
        private readonly Settings $settings,
        private readonly Notifier $notifier,
    ) {}

    /**
     * A guest request (guest checkout, decided 2026-11-12) passes $guest,
     * the name and WhatsApp number typed. It is placed_as_guest, and the
     * one check that depends on the account (an open request already) runs
     * last and throws PrivateRequestRejected::alreadyOpen(), so the guest
     * wrapper can keep it from the guest.
     *
     * @param  list<int>  $riderIds
     * @param  array{name: string, whatsapp: string}|null  $guest
     *
     * @throws PrivateRequestRejected
     */
    public function handle(User $user, Product $product, CarbonImmutable $startsAt, array $riderIds, ?string $note = null, ?array $guest = null): PrivateSessionRequest
    {
        $product = Product::query()->whereKey($product->id)->firstOrFail();

        if ($product->type !== ProductType::Private || ! $product->isOnSale()) {
            throw PrivateRequestRejected::because('Private sessions can’t be requested right now.');
        }

        if ($user->isTeamMember()) {
            throw PrivateRequestRejected::because('Team accounts can’t request private sessions.');
        }

        if ($guest === null && trim((string) $user->whatsapp_phone) === '') {
            throw PrivateRequestRejected::because('Add your WhatsApp number so we can reach you about the session.');
        }

        $startsAt = $startsAt->utc()->startOfMinute();
        $endsAt = $startsAt->addMinutes($this->rules->durationMinutes());

        if (($problem = $this->rules->problem($startsAt)) !== null) {
            throw PrivateRequestRejected::because($problem);
        }

        $riderIds = array_values(array_unique(array_map('intval', $riderIds)));
        sort($riderIds);
        $max = $this->settings->int(SettingKey::RidersPerSlot) ?? 6;

        if ($riderIds === [] || count($riderIds) > $max) {
            throw PrivateRequestRejected::because(sprintf('Choose 1 to %d riders.', $max));
        }

        if ($user->riders()->whereIn('id', $riderIds)->count() !== count($riderIds)) {
            throw PrivateRequestRejected::because('Choose riders from your account.');
        }

        $note = trim((string) $note);
        $note = $note === '' ? null : mb_substr($note, 0, PrivateSessionRequest::NOTE_MAX_LENGTH);

        $request = DB::transaction(function () use ($user, $product, $startsAt, $endsAt, $riderIds, $note, $guest): PrivateSessionRequest {
            // Serialises a customer's own submissions (double clicks, two tabs).
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            // The schedule clash first: it doesn't depend on the account, so
            // a guest sees the same answer whoever the email belongs to.
            $clash = $this->overlap->firstClash($startsAt, $endsAt);

            if ($clash !== null) {
                throw PrivateRequestRejected::because('That time isn’t free: there’s another session close to it. Please choose another time.');
            }

            if (PrivateSessionRequest::query()->where('user_id', $user->id)->open()->exists()) {
                throw PrivateRequestRejected::alreadyOpen();
            }

            $request = PrivateSessionRequest::query()->create([
                'reference' => $this->reference(),
                'user_id' => $user->id,
                'product_id' => $product->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'riders' => count($riderIds),
                'rider_ids' => $riderIds,
                'note' => $note,
                'answer_by' => CarbonImmutable::now()->addHours($this->rules->answerHours()),
                'placed_as_guest' => $guest !== null,
                'contact_name' => $guest['name'] ?? null,
                'contact_whatsapp' => $guest['whatsapp'] ?? null,
            ]);

            activity('private_requests')
                ->performedOn($request)
                ->causedBy($user)
                ->event('requested')
                ->withProperties(['starts_at' => $startsAt->toIso8601String(), 'riders' => count($riderIds), 'answer_by' => $request->answer_by->toIso8601String()])
                ->log('Private request '.$request->reference.' for '.$request->label());

            return $request;
        });

        $this->notifier->send($user, new PrivateRequestReceived($request), Notifier::key(PrivateRequestReceived::type(), $request, $user));

        User::query()
            ->where('role', UserRole::Admin)
            ->whereNotNull('email')
            ->each(fn (User $admin) => $this->notifier->send($admin, new PrivateRequestToReview($request), Notifier::key(PrivateRequestToReview::type(), $request, $admin)));

        return $request;
    }

    private function reference(): string
    {
        $alphabet = GenerateOrderReference::ALPHABET;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = self::PREFIX;

            for ($i = 0; $i < GenerateOrderReference::LENGTH; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            if (! PrivateSessionRequest::query()->where('reference', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique request reference.');
    }
}
