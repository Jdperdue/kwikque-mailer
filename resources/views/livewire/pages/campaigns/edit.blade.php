<?php

declare(strict_types=1);

use App\Models\AudienceGroup;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Locked]
    public ?int $campaignId = null;
    #[Locked]
    public string $status = Campaign::DRAFT;

    public string $name = '';
    public string $subject = '';
    public string $preheader = '';
    public string $intro = '';
    public string $scheduledAt = '';
    public array $groupIds = [];
    /** Content item ids in message order. */
    public array $itemIds = [];
    public string $addItemId = '';

    public ?string $message = null;
    public array $problems = [];

    public function mount(?Campaign $campaign = null): void
    {
        abort_unless(Gate::allows('manage-campaigns'), 403);

        if ($campaign?->exists) {
            $this->campaignId = $campaign->id;
            $this->status = $campaign->status;
            $this->name = $campaign->name;
            $this->subject = $campaign->subject;
            $this->preheader = (string) $campaign->preheader;
            $this->intro = (string) $campaign->intro;
            $this->scheduledAt = $campaign->scheduled_at?->setTimezone($this->tz())->format('Y-m-d\TH:i') ?? '';
            $this->groupIds = $campaign->groups()->pluck('audience_groups.id')->map(fn ($v) => (string) $v)->all();
            $this->itemIds = $campaign->items()->pluck('content_items.id')->map(fn ($v) => (string) $v)->all();
        }
    }

    private function tz(): string
    {
        return app(TenantContext::class)->current()->timezone;
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->current();
    }

    private function editable(): bool
    {
        return $this->status === Campaign::DRAFT;
    }

    public function addItem(): void
    {
        if (! $this->editable() || $this->addItemId === '') {
            return;
        }
        $id = (string) (int) $this->addItemId;
        if (! in_array($id, $this->itemIds, true) && ContentItem::whereKey((int) $id)->exists()) {
            $this->itemIds[] = $id;
        }
        $this->addItemId = '';
    }

    public function removeItem(string $id): void
    {
        if ($this->editable()) {
            $this->itemIds = array_values(array_filter($this->itemIds, fn ($i) => $i !== $id));
        }
    }

    public function moveItem(string $id, int $direction): void
    {
        if (! $this->editable()) {
            return;
        }
        $i = array_search($id, $this->itemIds, true);
        $j = $i === false ? false : $i + ($direction < 0 ? -1 : 1);
        if ($i !== false && isset($this->itemIds[$j])) {
            [$this->itemIds[$i], $this->itemIds[$j]] = [$this->itemIds[$j], $this->itemIds[$i]];
        }
    }

    /** Validates and saves the draft; returns the campaign. */
    private function persist(): Campaign
    {
        Gate::authorize('manage-campaigns');
        abort_unless($this->editable(), 422, 'Unschedule the campaign before editing it.');

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:5000'],
            'scheduledAt' => ['nullable', 'date'],
        ]);

        $attrs = [
            'name' => trim($this->name),
            'subject' => trim($this->subject),
            'preheader' => trim($this->preheader) ?: null,
            'intro' => trim($this->intro) ?: null,
            'scheduled_at' => $this->scheduledAt === '' ? null : CarbonImmutable::parse($this->scheduledAt, $this->tz())->utc(),
        ];

        if ($this->campaignId === null) {
            $campaign = Campaign::create($attrs + ['created_by' => auth()->id()]);
            $this->campaignId = $campaign->id;
            Audit::record('campaign.created', $campaign);
        } else {
            $campaign = Campaign::findOrFail($this->campaignId);
            $campaign->update($attrs);
            Audit::record('campaign.updated', $campaign);
        }

        // Only this tenant's groups and items can be attached (the scoped queries drop anything else).
        $campaign->groups()->sync(AudienceGroup::whereIn('id', $this->groupIds)->pluck('id')->all());

        $valid = ContentItem::whereIn('id', $this->itemIds)->pluck('id')->map(fn ($v) => (string) $v)->all();
        $this->itemIds = array_values(array_filter($this->itemIds, fn ($i) => in_array($i, $valid, true)));
        $campaign->items()->sync(collect($this->itemIds)->values()->mapWithKeys(fn ($id, $n) => [(int) $id => ['sort_order' => $n]])->all());

        return $campaign;
    }

    public function save(): void
    {
        $this->persist();
        $this->problems = [];
        $this->message = 'Saved.';
    }

    public function schedule(): void
    {
        $campaign = $this->persist();
        $this->problems = $campaign->scheduleProblems($this->tenant());

        if ($this->problems !== []) {
            $this->message = null;

            return;
        }

        $campaign->update(['status' => Campaign::SCHEDULED]);
        $this->status = Campaign::SCHEDULED;
        Audit::record('campaign.scheduled', $campaign, ['scheduled_at' => $campaign->scheduled_at?->toIso8601String()]);
        $this->message = 'Scheduled. Sending itself is not switched on yet.';
    }

    public function unschedule(): void
    {
        Gate::authorize('manage-campaigns');
        $campaign = Campaign::findOrFail($this->campaignId);
        $campaign->update(['status' => Campaign::DRAFT]);
        $this->status = Campaign::DRAFT;
        Audit::record('campaign.unscheduled', $campaign);
        $this->message = 'Back to draft.';
    }

    public function delete()
    {
        Gate::authorize('manage-campaigns');
        $campaign = Campaign::findOrFail($this->campaignId);
        abort_unless($campaign->isDraft(), 422, 'Only drafts can be deleted.');
        Audit::record('campaign.deleted', $campaign, ['name' => $campaign->name]);
        $campaign->delete();

        return $this->redirect(route('campaigns'), navigate: true);
    }

    public function with(): array
    {
        $items = ContentItem::orderBy('starts_at')->orderBy('title')->get();

        return [
            'groups' => AudienceGroup::orderBy('name')->get(),
            'allItems' => $items->keyBy('id'),
            'available' => $items->filter(fn ($i) => $i->is_active && ! in_array((string) $i->id, $this->itemIds, true)),
            'tz' => $this->tz(),
            'editable' => $this->editable(),
        ];
    }
};
?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $campaignId ? __('Campaign') : __('New campaign') }}</h2>
            <div class="space-x-4 text-sm">
                @if ($campaignId)<a href="{{ route('campaigns.preview', $campaignId) }}" wire:navigate class="text-indigo-600 hover:underline">{{ __('Preview and test send') }}</a>@endif
                <a href="{{ route('campaigns') }}" wire:navigate class="text-indigo-600 hover:underline">{{ __('All campaigns') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if ($message)<div class="bg-green-50 text-green-800 rounded-md p-3 text-sm" role="status">{{ $message }}</div>@endif
            @if ($problems)
                <div class="bg-amber-50 text-amber-900 rounded-md p-3 text-sm" role="alert">
                    <div class="font-medium">Not ready to schedule:</div>
                    <ul class="list-disc ms-5">@foreach ($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
                </div>
            @endif
            @unless ($editable)
                <div class="bg-indigo-50 text-indigo-900 rounded-md p-3 text-sm">This campaign is scheduled. Unschedule it to make changes.</div>
            @endunless

            <form wire:submit="save" class="grid gap-6 lg:grid-cols-2">
                <fieldset @disabled(! $editable) class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-3 content-start">
                    <div>
                        <x-input-label for="name" value="Internal name" />
                        <x-text-input wire:model="name" id="name" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="subject" value="Subject" />
                        <x-text-input wire:model="subject" id="subject" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('subject')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="preheader" value="Preview text (optional)" />
                        <x-text-input wire:model="preheader" id="preheader" class="block mt-1 w-full" />
                    </div>
                    <div>
                        <x-input-label for="intro" value="Message above the items (optional)" />
                        <textarea wire:model="intro" id="intro" rows="4" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm text-sm"></textarea>
                        <x-input-error :messages="$errors->get('intro')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="scheduledAt" value="Send time ({{ $tz }})" />
                        <input type="datetime-local" wire:model="scheduledAt" id="scheduledAt" class="block mt-1 border-gray-300 rounded-md shadow-sm text-sm" />
                        <x-input-error :messages="$errors->get('scheduledAt')" class="mt-1" />
                    </div>
                </fieldset>

                <fieldset @disabled(! $editable) class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-4 content-start">
                    <div>
                        <div class="text-sm font-medium text-gray-700 mb-1">Audience groups</div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            @forelse ($groups as $g)
                                <label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" wire:model="groupIds" value="{{ $g->id }}" class="rounded border-gray-300"> {{ $g->name }}</label>
                            @empty
                                <span class="text-sm text-gray-500">No groups yet.</span>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-700 mb-1">Content items, in message order</div>
                        <ol class="divide-y border rounded-md text-sm">
                            @forelse ($itemIds as $n => $id)
                                @php $item = $allItems[(int) $id] ?? null; @endphp
                                @if ($item)
                                    <li class="flex items-center gap-2 px-3 py-2" wire:key="item-{{ $id }}">
                                        <span class="text-gray-400 w-5">{{ $n + 1 }}</span>
                                        <span class="flex-1">{{ $item->title }}@unless ($item->is_active) <span class="text-amber-700">(inactive)</span>@endunless
                                            <span class="text-gray-500">{{ $item->starts_at?->setTimezone($tz)->format('M j') }}</span></span>
                                        @if ($editable)
                                            <button type="button" wire:click="moveItem('{{ $id }}', -1)" class="text-gray-500 hover:text-gray-800" aria-label="Move up" @disabled($loop->first)>↑</button>
                                            <button type="button" wire:click="moveItem('{{ $id }}', 1)" class="text-gray-500 hover:text-gray-800" aria-label="Move down" @disabled($loop->last)>↓</button>
                                            <button type="button" wire:click="removeItem('{{ $id }}')" class="text-red-600 hover:underline">Remove</button>
                                        @endif
                                    </li>
                                @endif
                            @empty
                                <li class="px-3 py-2 text-gray-500">No items yet.</li>
                            @endforelse
                        </ol>
                        @if ($editable)
                            <div class="mt-2 flex gap-2">
                                <select wire:model="addItemId" aria-label="Add a content item" class="flex-1 border-gray-300 rounded-md shadow-sm text-sm">
                                    <option value="">Add an item…</option>
                                    @foreach ($available as $i)<option value="{{ $i->id }}">{{ $i->title }}@if ($i->starts_at) ({{ $i->starts_at->setTimezone($tz)->format('M j') }})@endif</option>@endforeach
                                </select>
                                <x-secondary-button type="button" wire:click="addItem">{{ __('Add') }}</x-secondary-button>
                            </div>
                        @endif
                    </div>
                </fieldset>

                <div class="lg:col-span-2 flex flex-wrap items-center gap-3">
                    @if ($editable)
                        <x-primary-button>{{ __('Save draft') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="schedule">{{ __('Save and schedule') }}</x-secondary-button>
                        @if ($campaignId)
                            <button type="button" wire:click="delete" wire:confirm="Delete this draft?" class="text-sm text-red-600 hover:underline ms-auto">Delete draft</button>
                        @endif
                    @else
                        <x-secondary-button type="button" wire:click="unschedule">{{ __('Unschedule') }}</x-secondary-button>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>
