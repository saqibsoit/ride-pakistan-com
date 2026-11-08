<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Actions\Account\UpdateProfile;
use App\Actions\Riders\DeleteRider;
use App\Actions\Riders\SaveRider;
use App\Enums\AgeBracket;
use App\Enums\ExperienceLevel;
use App\Exceptions\AccountActionBlocked;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * /account/riders: add, edit and remove the people a customer books for.
 * Every lookup goes through the signed-in user's own riders, so another
 * customer's rider id is a 404.
 */
final class Riders extends Component
{
    #[Locked]
    public ?int $editingId = null;

    public bool $showForm = false;

    public ?int $confirmingDeleteId = null;

    public string $name = '';

    public string $age_bracket = '';

    public string $experience_level = '';

    public string $emergency_contact_name = '';

    public string $emergency_contact_phone = '';

    public string $notes = '';

    #[Locked]
    public ?string $status = null;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'age_bracket' => ['nullable', Rule::enum(AgeBracket::class)],
            'experience_level' => ['nullable', Rule::enum(ExperienceLevel::class)],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32', (new Phone)->international()->country(UpdateProfile::DEFAULT_PHONE_COUNTRY)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'Enter the rider’s name.',
            'emergency_contact_phone' => 'Enter a valid phone number, for example 0300 1234567 or +44 7700 900123.',
        ];
    }

    public function create(): void
    {
        Gate::authorize('create', Rider::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $rider = $this->ownRider($id);
        Gate::authorize('update', $rider);

        $this->resetForm();
        $this->editingId = $rider->id;
        $this->name = $rider->name;
        $this->age_bracket = $rider->age_bracket->value ?? '';
        $this->experience_level = $rider->experience_level->value ?? '';
        $this->emergency_contact_name = $rider->emergency_contact_name ?? '';
        $this->emergency_contact_phone = $rider->emergency_contact_phone ?? '';
        $this->notes = $rider->notes ?? '';
        $this->showForm = true;
    }

    public function save(SaveRider $save): void
    {
        $rider = $this->editingId !== null ? $this->ownRider($this->editingId) : null;
        Gate::authorize($rider === null ? 'create' : 'update', $rider ?? Rider::class);

        /** @var array{name: string, age_bracket?: string|null, experience_level?: string|null, emergency_contact_name?: string|null, emergency_contact_phone?: string|null, notes?: string|null} $data */
        $data = $this->validate();

        $saved = $save->handle($this->user(), $data, $rider);

        $this->status = $rider === null ? $saved->name.' added.' : $saved->name.' updated.';
        $this->resetForm();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $this->ownRider($id)->id;
    }

    public function delete(int $id, DeleteRider $delete): void
    {
        $rider = $this->ownRider($id);

        if (! Gate::allows('delete', $rider)) {
            $this->status = $rider->name.' has bookings or orders, so they stay on your account.';
            $this->confirmingDeleteId = null;

            return;
        }

        try {
            $delete->handle($rider);
            $this->status = $rider->name.' removed.';
        } catch (AccountActionBlocked $e) {
            $this->status = $e->getMessage();
        }

        $this->confirmingDeleteId = null;
    }

    public function render(): View
    {
        /** @var Collection<int, Rider> $riders */
        $riders = $this->user()->riders()->withCount(['bookings', 'orderItems'])->orderBy('name')->get();

        return view('livewire.account.riders', [
            'riders' => $riders,
            'ageBrackets' => AgeBracket::cases(),
            'experienceLevels' => ExperienceLevel::cases(),
        ]);
    }

    private function ownRider(int $id): Rider
    {
        return $this->user()->riders()->findOrFail($id);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'showForm', 'confirmingDeleteId', 'name', 'age_bracket', 'experience_level', 'emergency_contact_name', 'emergency_contact_phone', 'notes']);
        $this->resetValidation();
    }
}
