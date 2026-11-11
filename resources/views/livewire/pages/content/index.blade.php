<?php

declare(strict_types=1);

use App\Models\ContentItem;
use App\Support\Audit;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public ?int $editId = null;
    public string $type = 'auction';
    public string $title = '';
    public string $starts_at = '';
    public string $ends_at = '';
    public string $url = '';
    public string $image_url = '';
    public string $description = '';
    public bool $is_active = true;

    private function tz(): string
    {
        return app(TenantContext::class)->current()->timezone;
    }

    /** datetime-local value entered in the tenant's timezone -> UTC for storage. */
    private function toUtc(string $value): ?CarbonImmutable
    {
        return $value === '' ? null : CarbonImmutable::parse($value, $this->tz())->utc();
    }

    private function fromUtc($value): string
    {
        return $value ? $value->setTimezone($this->tz())->format('Y-m-d\TH:i') : '';
    }

    public function save(): void
    {
        Gate::authorize('manage-audience');

        $data = $this->validate([
            'type' => ['required', Rule::in(ContentItem::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'url' => ['nullable', 'url:http,https', 'max:2048'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'description' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ]);

        $attrs = [
            'type' => $data['type'],
            'title' => trim($data['title']),
            'starts_at' => $this->toUtc($data['starts_at'] ?? ''),
            'ends_at' => $this->toUtc($data['ends_at'] ?? ''),
            'url' => $data['url'] ?: null,
            'image_url' => $data['image_url'] ?: null,
            'description' => $data['description'] ?: null,
            'is_active' => $data['is_active'],
        ];

        if ($this->editId !== null) {
            $item = ContentItem::findOrFail($this->editId);
            $item->update($attrs);
            Audit::record('content.updated', $item);
        } else {
            $item = ContentItem::create($attrs + ['source' => 'manual']);
            Audit::record('content.created', $item);
        }

        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $i = ContentItem::findOrFail($id);
        $this->editId = $i->id;
        $this->type = $i->type;
        $this->title = $i->title;
        $this->starts_at = $this->fromUtc($i->starts_at);
        $this->ends_at = $this->fromUtc($i->ends_at);
        $this->url = (string) $i->url;
        $this->image_url = (string) $i->image_url;
        $this->description = (string) $i->description;
        $this->is_active = $i->is_active;
    }

    public function remove(int $id): void
    {
        Gate::authorize('manage-audience');
        $i = ContentItem::findOrFail($id);
        Audit::record('content.removed', $i, ['title' => $i->title]);
        $i->delete();
        if ($this->editId === $id) {
            $this->resetForm();
        }
    }

    public function resetForm(): void
    {
        $this->reset(['editId', 'title', 'starts_at', 'ends_at', 'url', 'image_url', 'description']);
        $this->type = 'auction';
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function with(): array
    {
        return [
            'items' => ContentItem::orderByDesc('is_active')->orderBy('starts_at')->paginate(25),
            'canManage' => Gate::allows('manage-audience'),
            'types' => ContentItem::TYPES,
            'tz' => $this->tz(),
        ];
    }
};
?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Content') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <p class="text-sm text-gray-600 mb-3">Items available to feature in a mailer. Times are shown in {{ $tz }}.</p>
                <table class="w-full text-sm">
                    <thead class="text-gray-500"><tr>
                        <th class="py-2 pe-4 text-start font-medium">Title</th><th class="pe-4 text-start font-medium">Type</th>
                        <th class="pe-4 text-start font-medium">Starts</th><th class="pe-4 text-start font-medium">Ends</th>
                        <th class="pe-4 text-start font-medium">Active</th><th></th>
                    </tr></thead>
                    <tbody>
                        @forelse ($items as $i)
                            <tr class="border-t" wire:key="item-{{ $i->id }}">
                                <td class="py-2 pe-4">{{ $i->title }}</td>
                                <td class="pe-4">{{ $i->type }}</td>
                                <td class="pe-4 whitespace-nowrap">{{ $i->starts_at?->setTimezone($tz)->format('M j, Y g:i a') ?? '—' }}</td>
                                <td class="pe-4 whitespace-nowrap">{{ $i->ends_at?->setTimezone($tz)->format('M j, Y g:i a') ?? '—' }}</td>
                                <td class="pe-4">{{ $i->is_active ? 'Yes' : 'No' }}</td>
                                <td class="whitespace-nowrap space-x-3">
                                    @if ($canManage)
                                        <button wire:click="edit({{ $i->id }})" class="text-indigo-600 hover:underline">Edit</button>
                                        <button wire:click="remove({{ $i->id }})" wire:confirm="Remove this item?" class="text-red-600 hover:underline">Remove</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-3 text-gray-500">No content items yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="mt-3">{{ $items->links() }}</div>
            </div>

            @if ($canManage)
                <form wire:submit="save" class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-4 sm:grid-cols-2">
                    <h3 class="sm:col-span-2 font-semibold text-gray-800">{{ $editId ? __('Edit item') : __('New item') }}</h3>
                    <div>
                        <x-input-label for="type" value="Type" />
                        <select wire:model="type" id="type" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                            @foreach ($types as $t)<option value="{{ $t }}">{{ ucfirst($t) }}</option>@endforeach
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="title" value="Title" />
                        <x-text-input wire:model="title" id="title" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('title')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="starts_at" value="Starts ({{ $tz }})" />
                        <x-text-input type="datetime-local" wire:model="starts_at" id="starts_at" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('starts_at')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="ends_at" value="Ends ({{ $tz }})" />
                        <x-text-input type="datetime-local" wire:model="ends_at" id="ends_at" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('ends_at')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="url" value="Link URL" />
                        <x-text-input wire:model="url" id="url" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('url')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="image_url" value="Image URL" />
                        <x-text-input wire:model="image_url" id="image_url" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('image_url')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="description" value="Description" />
                        <textarea wire:model="description" id="description" rows="4" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm text-sm"></textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-1" />
                    </div>
                    <label class="sm:col-span-2 inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="is_active" class="rounded border-gray-300"> Active (available for mailers)</label>
                    <div class="sm:col-span-2 space-x-3">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        @if ($editId)<button type="button" wire:click="resetForm" class="text-sm text-gray-500 hover:underline">Cancel</button>@endif
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
