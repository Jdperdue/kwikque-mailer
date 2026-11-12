<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $from_name = '';
    public string $from_address = '';
    public string $reply_to = '';
    public string $postal_address = '';
    public string $logo_url = '';
    public string $accent_color = '';
    public ?string $message = null;

    public function mount(): void
    {
        abort_unless(Gate::allows('manage-tenant'), 403);

        $t = $this->tenant();
        $this->from_name = (string) $t->from_name;
        $this->from_address = (string) $t->from_address;
        $this->reply_to = (string) $t->reply_to;
        $this->postal_address = (string) $t->postal_address;
        $this->logo_url = (string) ($t->branding['logo_url'] ?? '');
        $this->accent_color = (string) ($t->branding['accent_color'] ?? '');
    }

    private function tenant(): Tenant
    {
        return Tenant::findOrFail(app(TenantContext::class)->requireId('edit tenant settings'));
    }

    public function save(): void
    {
        Gate::authorize('manage-tenant');

        $data = $this->validate([
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'postal_address' => ['nullable', 'string', 'max:500'],
            'logo_url' => ['nullable', 'url:https', 'max:2048'],
            'accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $t = $this->tenant();
        $branding = array_filter(
            ['logo_url' => $data['logo_url'] ?: null, 'accent_color' => $data['accent_color'] ?: null],
            fn ($v) => $v !== null,
        ) + array_diff_key((array) $t->branding, ['logo_url' => 1, 'accent_color' => 1]);

        $t->update([
            'from_name' => trim((string) $data['from_name']) ?: null,
            'from_address' => $data['from_address'] ? mb_strtolower(trim($data['from_address'])) : null,
            'reply_to' => $data['reply_to'] ? mb_strtolower(trim($data['reply_to'])) : null,
            'postal_address' => trim((string) $data['postal_address']) ?: null,
            'branding' => $branding ?: null,
        ]);
        Audit::record('tenant.settings_updated', null, ['tenant' => $t->slug]);

        $this->message = 'Saved.';
    }

    public function with(): array
    {
        $t = $this->tenant();

        return ['tenantName' => $t->name, 'timezone' => $t->timezone, 'domain' => $t->mailgun_domain, 'domainStatus' => $t->domain_status];
    }
};
?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Settings') }}: {{ $tenantName }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if ($message)<div class="bg-green-50 text-green-800 rounded-md p-3 text-sm" role="status">{{ $message }}</div>@endif

            <form wire:submit="save" class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="from_name" value="From name" />
                    <x-text-input wire:model="from_name" id="from_name" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('from_name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="from_address" value="From address" />
                    <x-text-input wire:model="from_address" id="from_address" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('from_address')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="reply_to" value="Reply-to (optional)" />
                    <x-text-input wire:model="reply_to" id="reply_to" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('reply_to')" class="mt-1" />
                </div>
                <div>
                    <x-input-label value="Time zone" />
                    <div class="mt-2 text-sm text-gray-700">{{ $timezone }} <span class="text-gray-400">(set by the platform admin)</span></div>
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="postal_address" value="Postal address (shown in every message footer)" />
                    <textarea wire:model="postal_address" id="postal_address" rows="3" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm text-sm"></textarea>
                    <x-input-error :messages="$errors->get('postal_address')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="logo_url" value="Logo URL (https, optional)" />
                    <x-text-input wire:model="logo_url" id="logo_url" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('logo_url')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="accent_color" value="Accent colour (e.g. #1f2937, optional)" />
                    <x-text-input wire:model="accent_color" id="accent_color" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('accent_color')" class="mt-1" />
                </div>
                <div class="sm:col-span-2 flex items-center justify-between">
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                    <span class="text-xs text-gray-500">Sending domain: {{ $domain ?? 'not set' }} ({{ $domainStatus }}). Managed by the platform admin.</span>
                </div>
            </form>
        </div>
    </div>
</div>
