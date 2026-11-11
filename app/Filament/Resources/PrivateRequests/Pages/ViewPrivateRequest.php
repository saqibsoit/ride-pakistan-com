<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrivateRequests\Pages;

use App\Actions\PrivateRequests\ApprovePrivateRequest;
use App\Actions\PrivateRequests\DeclinePrivateRequest;
use App\Actions\PrivateRequests\PrivateRequestRules;
use App\Enums\PrivateRequestStatus;
use App\Exceptions\PrivateRequestRejected;
use App\Filament\Resources\PrivateRequests\PrivateRequestResource;
use App\Filament\Support\TrainerOptions;
use App\Models\PrivateSessionRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * One private request (prompt 15B). Approve and Decline are admin-only;
 * the admin email's buttons open them here with ?action=approve|decline
 * (after the usual panel sign-in and MFA). Approving shows any clash or
 * horse problem first; the action itself checks again under the lock.
 */
class ViewPrivateRequest extends ViewRecord
{
    protected static string $resource = PrivateRequestResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof PrivateSessionRequest ? 'Private request '.$record->reference : 'Private request';
    }

    protected function getHeaderActions(): array
    {
        return [$this->approveAction(), $this->declineAction()];
    }

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (): bool => $this->canDecide())
            ->modalHeading('Approve this private session?')
            ->modalDescription(function (): string {
                $request = $this->request();
                $problem = app(ApprovePrivateRequest::class)->problem($request);
                $hours = app(PrivateRequestRules::class)->payHours();

                return $problem !== null
                    ? 'Can’t approve: '.$problem
                    : sprintf('Creates a private-only slot on %s, holds it and emails %s the payment link. The customer must pay within %d hours, or the slot is released.', $request->label(), $request->user->displayName(), $hours);
            })
            ->schema([
                Select::make('trainer_id')->label('Trainer (optional)')->options(fn (): array => TrainerOptions::all())->placeholder('Nobody yet'),
            ])
            ->modalSubmitActionLabel('Approve')
            ->action(function (array $data, Action $action): void {
                $admin = $this->admin();
                $trainer = filled($data['trainer_id'] ?? null) ? User::query()->find((int) $data['trainer_id']) : null;

                try {
                    $request = app(ApprovePrivateRequest::class)->handle($this->request(), $admin, $trainer);
                } catch (PrivateRequestRejected|RuntimeException $e) {
                    Notification::make()->danger()->title('Not approved')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Approved')->body('Pay by '.PrivateSessionRequest::localTime($request->pay_by).'. The customer has been emailed.')->send();
                $this->refreshFormData(['status']);
            });
    }

    public function declineAction(): Action
    {
        return Action::make('decline')
            ->label('Decline')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->visible(fn (): bool => $this->canDecide())
            ->modalHeading('Decline this request?')
            ->schema([
                Textarea::make('reason')->label('Reason (the customer sees it)')->required()->maxLength(500)->rows(2),
                Textarea::make('suggested_times')->label('Suggest other times (optional)')->maxLength(DeclinePrivateRequest::SUGGESTIONS_MAX_LENGTH)->rows(2)
                    ->helperText('For example: Saturday 15 Nov at 10:00 or 14:00.'),
            ])
            ->modalSubmitActionLabel('Decline')
            ->action(function (array $data, Action $action): void {
                try {
                    app(DeclinePrivateRequest::class)->handle($this->request(), $this->admin(), (string) $data['reason'], (string) ($data['suggested_times'] ?? ''));
                } catch (PrivateRequestRejected $e) {
                    Notification::make()->danger()->title('Not declined')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Declined')->body('The customer has been emailed.')->send();
                $this->refreshFormData(['status']);
            });
    }

    private function canDecide(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('decide', PrivateSessionRequest::class)
            && $this->request()->status === PrivateRequestStatus::Requested;
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->can('decide', PrivateSessionRequest::class), 403);

        return $user;
    }

    private function request(): PrivateSessionRequest
    {
        $record = $this->getRecord();
        abort_unless($record instanceof PrivateSessionRequest, 404);

        return $record->refresh();
    }
}
