<?php

declare(strict_types=1);

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Locked]
    public int $tenantId;

    public string $email = '';
    public string $name = '';
    public string $role = 'operator';

    public ?string $message = null;
    // Shown once to the platform admin after creating a new login; never stored or logged.
    public ?string $tempPassword = null;
    public ?string $tempFor = null;

    public function mount(Tenant $tenant): void
    {
        abort_unless(auth()->user()?->is_super_admin === true, 403);
        $this->tenantId = $tenant->id;
    }

    private function tenant(): Tenant
    {
        abort_unless(auth()->user()?->is_super_admin === true, 403);

        return Tenant::findOrFail($this->tenantId);
    }

    /** Audit rows are tenant-owned, so record them inside this tenant's context. */
    private function audited(Tenant $tenant, string $action, User $user, array $context = []): void
    {
        app(TenantContext::class)->run($tenant, fn () => Audit::record($action, $user, $context + ['email_hash' => hash('sha256', $user->email)]));
    }

    public function add(): void
    {
        $tenant = $this->tenant();
        $this->tempPassword = $this->tempFor = $this->message = null;
        $this->email = mb_strtolower(trim($this->email));

        $this->validate([
            'email' => ['required', 'email', 'max:254'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::enum(TenantRole::class)],
        ]);

        $user = User::where('email', $this->email)->first();

        if ($user?->is_super_admin) {
            $this->addError('email', 'This is a platform admin and already has full access to every tenant.');

            return;
        }
        if ($user && $tenant->users()->whereKey($user->id)->exists()) {
            $this->addError('email', 'This user is already a member of this tenant.');

            return;
        }

        if ($user === null) {
            if (trim($this->name) === '') {
                $this->addError('name', 'Name is required for a new user.');

                return;
            }
            $password = Str::password(16, symbols: false);
            $user = User::create(['name' => trim($this->name), 'email' => $this->email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->tempPassword = $password;
            $this->tempFor = $user->email;
        }

        $tenant->users()->attach($user->id, ['role' => $this->role]);
        $this->audited($tenant, 'member.added', $user, ['role' => $this->role, 'new_user' => $this->tempPassword !== null]);

        $this->message = $this->tempPassword !== null
            ? "Created a login for {$user->email}. Give them the temporary password below; it is shown only once."
            : "{$user->email} added.";
        $this->reset(['email', 'name']);
    }

    public function setRole(int $userId, string $role): void
    {
        $tenant = $this->tenant();
        abort_unless(TenantRole::tryFrom($role) !== null, 422);

        $user = $tenant->users()->findOrFail($userId);
        $tenant->users()->updateExistingPivot($user->id, ['role' => $role]);
        $this->audited($tenant, 'member.role_changed', $user, ['role' => $role]);
        $this->tempPassword = $this->tempFor = null;
        $this->message = "{$user->email} is now {$role}.";
    }

    public function remove(int $userId): void
    {
        $tenant = $this->tenant();
        $user = $tenant->users()->findOrFail($userId);
        $tenant->users()->detach($user->id);
        $this->audited($tenant, 'member.removed', $user);
        $this->tempPassword = $this->tempFor = null;
        $this->message = "{$user->email} removed from this tenant. Their login is kept.";
    }

    public function with(): array
    {
        $tenant = $this->tenant();

        return [
            'tenant' => $tenant,
            'members' => $tenant->users()->orderBy('users.name')->get(),
            'roles' => TenantRole::cases(),
        ];
    }
};
?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $tenant->name }}: {{ __('Members') }}</h2>
            <a href="{{ route('platform.tenants') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">{{ __('Back to tenants') }}</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($message)
                <div class="bg-green-50 text-green-800 rounded-md p-3 text-sm" role="status">{{ $message }}</div>
            @endif
            @if ($tempPassword)
                <div class="bg-amber-50 text-amber-900 rounded-md p-3 text-sm" role="alert">
                    Temporary password for {{ $tempFor }}: <code class="font-mono font-semibold select-all">{{ $tempPassword }}</code>
                    <div class="text-xs mt-1">Shown once. Pass it on securely and ask them to change it under Profile after logging in. Nothing here is emailed.</div>
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-gray-500"><tr><th class="py-2 pe-4 text-start font-medium">Name</th><th class="pe-4 text-start font-medium">Email</th><th class="pe-4 text-start font-medium">Role</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($members as $m)
                            <tr class="border-t" wire:key="member-{{ $m->id }}">
                                <td class="py-2 pe-4">{{ $m->name }}</td>
                                <td class="pe-4">{{ $m->email }}</td>
                                <td class="pe-4">
                                    <select wire:change="setRole({{ $m->id }}, $event.target.value)" aria-label="Role for {{ $m->email }}" class="border-gray-300 rounded-md shadow-sm text-sm py-1">
                                        @foreach ($roles as $r)<option value="{{ $r->value }}" @selected($m->pivot->role === $r->value)>{{ ucfirst($r->value) }}</option>@endforeach
                                    </select>
                                </td>
                                <td><button wire:click="remove({{ $m->id }})" wire:confirm="Remove {{ $m->email }} from {{ $tenant->name }}?" class="text-red-600 hover:underline">Remove</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-gray-500">No members yet. Platform admins can still act in this tenant.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p class="mt-3 text-xs text-gray-500">Admin: everything, including lifting suppressions. Operator: manage audience and content. Viewer: read only.</p>
            </div>

            <form wire:submit="add" class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 items-start">
                <h3 class="sm:col-span-2 lg:col-span-4 font-semibold text-gray-800">{{ __('Add a member') }}</h3>
                <div>
                    <x-input-label for="mEmail" value="Email" />
                    <x-text-input wire:model="email" id="mEmail" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="mName" value="Name (new users only)" />
                    <x-text-input wire:model="name" id="mName" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="mRole" value="Role" />
                    <select wire:model="role" id="mRole" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                        @foreach ($roles as $r)<option value="{{ $r->value }}">{{ ucfirst($r->value) }}</option>@endforeach
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-1" />
                </div>
                <div class="self-end"><x-primary-button>{{ __('Add member') }}</x-primary-button></div>
            </form>
        </div>
    </div>
</div>
