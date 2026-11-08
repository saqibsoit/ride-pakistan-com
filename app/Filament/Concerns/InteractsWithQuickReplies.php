<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Leads\LogQuickReplyCopy;
use App\Actions\Leads\RenderQuickReply;
use App\Models\Lead;
use App\Models\QuickReply;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Quick replies on a Filament page (milestone 10c): the slide-over with
 * every active template filled in for a lead, and the call that logs a
 * copy on the lead's timeline. Used by the lead page, the lead board and
 * the "Quick replies" page. The copy itself happens in the browser.
 */
trait InteractsWithQuickReplies
{
    /** Called by the Copy button after the text is on the clipboard. */
    public function quickReplyCopied(int $replyId, ?int $leadId = null, bool $tracked = true): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->can('viewAny', QuickReply::class), 403);

        if ($leadId === null) {
            return;
        }

        $lead = Lead::query()->find($leadId);
        $reply = QuickReply::query()->find($replyId);

        if ($lead === null || $reply === null) {
            return;
        }

        try {
            app(LogQuickReplyCopy::class)->handle($lead, $reply, $user, $tracked);
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    /** Slide-over for one lead: `['lead' => id]` argument or the page's record. */
    public function quickRepliesAction(): Action
    {
        return Action::make('quickReplies')
            ->label('Quick replies')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('gray')
            ->slideOver()
            ->visible(fn (): bool => auth()->user()?->can('viewAny', QuickReply::class) === true)
            ->modalHeading(fn (array $arguments): string => 'Reply to '.($this->quickReplyLead($arguments)->name ?? 'lead'))
            ->modalDescription('Copy a reply into Instagram. Prices, times and links are today\'s. Booking links carry this lead\'s tracking reference, so a booking from it is credited to the lead.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (array $arguments): View => self::quickReplyList($this->quickReplyLead($arguments)));
    }

    /** @param  array<string, mixed>  $arguments */
    protected function quickReplyLead(array $arguments): ?Lead
    {
        if (isset($arguments['lead'])) {
            return Lead::query()->find((int) $arguments['lead']);
        }

        $record = property_exists($this, 'record') ? $this->record : null;

        return $record instanceof Lead ? $record : null;
    }

    public static function quickReplyList(?Lead $lead): View
    {
        return view('filament.quick-replies.list', [
            'lead' => $lead,
            'groups' => self::renderedReplies($lead),
        ]);
    }

    /** @return Collection<string, Collection<int, array{id: int, title: string, tracked: string, plain: string}>> */
    public static function renderedReplies(?Lead $lead): Collection
    {
        $render = app(RenderQuickReply::class);

        return QuickReply::query()->active()->orderBy('sort')->orderBy('id')->get()
            ->map(fn (QuickReply $reply): array => [
                'id' => $reply->id,
                'title' => $reply->title,
                'category' => $reply->category->label(),
                'tracked' => $render->handle($reply, $lead, tracked: true),
                'plain' => $render->handle($reply, $lead, tracked: false),
            ])
            ->groupBy('category')
            ->map(fn (Collection $items): Collection => $items->map(fn (array $item): array => [
                'id' => $item['id'],
                'title' => $item['title'],
                'tracked' => $item['tracked'],
                'plain' => $item['plain'],
            ])->values());
    }
}
