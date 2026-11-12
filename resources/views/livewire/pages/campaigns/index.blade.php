<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public function with(): array
    {
        return [
            'campaigns' => Campaign::withCount(['items', 'groups'])->latest('id')->paginate(25),
            'canManage' => Gate::allows('manage-campaigns'),
            'tz' => app(TenantContext::class)->current()->timezone,
        ];
    }
};
?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Campaigns') }}</h2>
            @if ($canManage)
                <a href="{{ route('campaigns.create') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">{{ __('New campaign') }}</a>
            @endif
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-gray-500">
                        <tr>
                            @foreach (['Name', 'Subject', 'Status', 'Send time', 'Items', 'Groups', ''] as $h)
                                <th class="py-2 pe-4 text-start font-medium">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($campaigns as $c)
                            <tr class="border-t" wire:key="campaign-{{ $c->id }}">
                                <td class="py-2 pe-4">{{ $c->name }}</td>
                                <td class="pe-4">{{ $c->subject }}</td>
                                <td class="pe-4">{{ $c->status }}</td>
                                <td class="pe-4 whitespace-nowrap">{{ $c->scheduled_at?->setTimezone($tz)->format('M j, Y g:i a') ?? '—' }}</td>
                                <td class="pe-4">{{ $c->items_count }}</td>
                                <td class="pe-4">{{ $c->groups_count }}</td>
                                <td class="whitespace-nowrap space-x-3">
                                    <a href="{{ route('campaigns.preview', $c->id) }}" wire:navigate class="text-indigo-600 hover:underline">Preview</a>
                                    @if ($canManage)<a href="{{ route('campaigns.edit', $c->id) }}" wire:navigate class="text-indigo-600 hover:underline">{{ $c->isDraft() ? 'Edit' : 'Open' }}</a>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-4 text-gray-500">No campaigns yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="mt-3">{{ $campaigns->links() }}</div>
            </div>
        </div>
    </div>
</div>
