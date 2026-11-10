<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $name = '';
    public string $slug = '';
    public string $timezone = 'UTC';
    public string $from_name = '';
    public string $from_address = '';
    public string $mailgun_domain = '';

    public function create(): void
    {
        abort_unless(auth()->user()?->is_super_admin === true, 403);

        $this->slug = Str::slug($this->slug !== '' ? $this->slug : $this->name);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', Rule::unique('tenants', 'slug')],
            'timezone' => ['required', 'timezone:all'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'mailgun_domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'mailgun_domain')],
        ]);

        Tenant::create([
            ...$data,
            'mailgun_domain' => $data['mailgun_domain'] !== '' ? strtolower($data['mailgun_domain']) : null,
        ]);

        $this->reset(['name', 'slug', 'from_name', 'from_address', 'mailgun_domain']);
    }

    public function with(): array
    {
        return ['tenants' => Tenant::orderBy('name')->get()];
    }
};
?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Tenants') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <table class="w-full text-sm text-left">
                    <thead class="text-gray-500">
                        <tr><th class="py-2">Name</th><th>Slug</th><th>Status</th><th>Sending domain</th><th>Domain</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($tenants as $t)
                            <tr class="border-t" wire:key="tenant-{{ $t->id }}">
                                <td class="py-2">{{ $t->name }}</td>
                                <td>{{ $t->slug }}</td>
                                <td>{{ $t->status }}</td>
                                <td>{{ $t->mailgun_domain ?? '—' }}</td>
                                <td>{{ $t->domain_status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <form wire:submit="create" class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-4 sm:grid-cols-2">
                <h3 class="sm:col-span-2 font-semibold text-gray-800">{{ __('New tenant') }}</h3>
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input wire:model="name" id="name" class="block mt-1 w-full" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="slug" value="Slug (optional)" />
                    <x-text-input wire:model="slug" id="slug" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('slug')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="timezone" value="Time zone" />
                    <x-text-input wire:model="timezone" id="timezone" class="block mt-1 w-full" required />
                    <x-input-error :messages="$errors->get('timezone')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="mailgun_domain" value="Sending domain (optional)" />
                    <x-text-input wire:model="mailgun_domain" id="mailgun_domain" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('mailgun_domain')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="from_name" value="From name" />
                    <x-text-input wire:model="from_name" id="from_name" class="block mt-1 w-full" />
                </div>
                <div>
                    <x-input-label for="from_address" value="From address" />
                    <x-text-input wire:model="from_address" id="from_address" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('from_address')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <x-primary-button>{{ __('Create tenant') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</div>
