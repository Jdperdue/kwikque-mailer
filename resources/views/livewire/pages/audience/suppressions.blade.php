<?php

declare(strict_types=1);

use App\Models\Suppression;
use App\Services\OptOuts;
use App\Support\Audience\EmailAddress;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $email = '';
    public string $note = '';

    public function add(OptOuts $optOuts): void
    {
        Gate::authorize('manage-audience');
        $this->validate(['email' => ['required', 'string', 'max:254'], 'note' => ['nullable', 'string', 'max:255']]);

        $normalized = EmailAddress::normalize($this->email);
        if ($normalized === null) {
            $this->addError('email', 'Enter a valid email address.');

            return;
        }

        $optOuts->suppress($normalized, 'manual', trim($this->note) ?: null);
        $this->reset(['email', 'note']);
    }

    public function with(): array
    {
        return [
            'suppressions' => Suppression::latest('id')->paginate(25),
            'canManage' => Gate::allows('manage-audience'),
            'tz' => app(TenantContext::class)->current()->timezone,
        ];
    }
};
?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Suppressions') }}</h2>
            <a href="{{ route('audience') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">{{ __('Back to audience') }}</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <p class="text-sm text-gray-600">Addresses here never receive mail from this account, even if a later import lists them. Unsubscribes, manual blocks, bounces and complaints are kept permanently. A separate platform-wide list of hard bounces and complaints is also checked on every send.</p>

            @if ($canManage)
                <form wire:submit="add" class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-3 max-w-xl">
                    <h3 class="font-semibold text-gray-800">{{ __('Block an address') }}</h3>
                    <div>
                        <x-input-label for="email" value="Email" />
                        <x-text-input wire:model="email" id="email" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="note" value="Note (optional)" />
                        <x-text-input wire:model="note" id="note" class="block mt-1 w-full" />
                    </div>
                    <div><x-primary-button>{{ __('Block') }}</x-primary-button></div>
                </form>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-gray-500"><tr><th class="py-2 pe-4 text-start font-medium">Email</th><th class="pe-4 text-start font-medium">Reason</th><th class="pe-4 text-start font-medium">Note</th><th class="text-start font-medium">Added</th></tr></thead>
                    <tbody>
                        @forelse ($suppressions as $s)
                            <tr class="border-t" wire:key="sup-{{ $s->id }}">
                                <td class="py-2 pe-4">{{ $s->email }}</td>
                                <td class="pe-4">{{ $s->reason }}</td>
                                <td class="pe-4">{{ $s->note ?? '—' }}</td>
                                <td>{{ $s->created_at->setTimezone($tz)->format('M j, Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-gray-500">No suppressions.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="mt-3">{{ $suppressions->links() }}</div>
            </div>
        </div>
    </div>
</div>
