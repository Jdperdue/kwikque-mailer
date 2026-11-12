<?php

declare(strict_types=1);

use App\Models\AudienceGroup;
use App\Models\Recipient;
use App\Services\ManualAudience;
use App\Services\OptOuts;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $groupFilter = '';
    public string $status = '';

    public string $newEmail = '';
    public string $newName = '';
    public string $newGroup = '';

    public string $pasteText = '';
    public string $pasteGroup = '';
    public ?string $message = null;

    public ?int $editId = null;
    public string $editName = '';
    public array $editGroups = [];

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedGroupFilter(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }

    public function addRecipient(ManualAudience $manual): void
    {
        Gate::authorize('manage-audience');
        $this->validate([
            'newEmail' => ['required', 'string', 'max:254'],
            'newName' => ['nullable', 'string', 'max:255'],
            'newGroup' => ['required', 'integer'],
        ]);

        $group = AudienceGroup::findOrFail((int) $this->newGroup);
        $line = trim($this->newName) !== '' ? trim($this->newName).' <'.trim($this->newEmail).'>' : trim($this->newEmail);
        $run = $manual->addFromText($group, $line, 'manual');

        $this->message = $this->summarise($run);
        $this->reset(['newEmail', 'newName']);
    }

    public function paste(ManualAudience $manual): void
    {
        Gate::authorize('manage-audience');
        $this->validate([
            'pasteText' => ['required', 'string', 'max:2000000'],
            'pasteGroup' => ['required', 'integer'],
        ]);

        $group = AudienceGroup::findOrFail((int) $this->pasteGroup);
        $run = $manual->addFromText($group, $this->pasteText, 'paste');

        $this->message = $this->summarise($run);
        $this->reset('pasteText');
    }

    public function startEdit(int $id): void
    {
        $r = Recipient::with('groups')->findOrFail($id);
        $this->editId = $r->id;
        $this->editName = (string) $r->name;
        $this->editGroups = $r->groups->pluck('id')->map(fn ($v) => (string) $v)->all();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editId', 'editName', 'editGroups']);
    }

    public function saveEdit(): void
    {
        Gate::authorize('manage-audience');
        $this->validate(['editName' => ['nullable', 'string', 'max:255'], 'editGroups' => ['array']]);

        $r = Recipient::findOrFail($this->editId);
        $r->update(['name' => trim($this->editName) !== '' ? trim($this->editName) : null]);
        $r->groups()->sync(AudienceGroup::whereIn('id', $this->editGroups)->pluck('id')->all());
        Audit::record('recipient.updated', $r);

        $this->cancelEdit();
    }

    public function optOut(int $id, OptOuts $optOuts): void
    {
        Gate::authorize('manage-audience');
        $r = Recipient::findOrFail($id);
        $optOuts->suppress($r->email, 'manual', 'Opted out by operator');
    }

    public function remove(int $id): void
    {
        Gate::authorize('manage-audience');
        $r = Recipient::findOrFail($id);
        // An existing suppressions row (unsubscribe/bounce/manual) is permanent and is NOT deleted here.
        Audit::record('recipient.removed', $r, ['email_hash' => hash('sha256', $r->email)]);
        $r->delete();
    }

    private function summarise($run): string
    {
        return "Added {$run->created_count}, updated {$run->updated_count}, unchanged {$run->unchanged_count}, "
            ."invalid {$run->invalid_count}, duplicate {$run->duplicate_count}"
            .($run->protected_count ? ", {$run->protected_count} left opted out" : '').'.';
    }

    public function with(): array
    {
        $groups = AudienceGroup::orderBy('name')->get();

        $recipients = Recipient::with('groups')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('email', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($this->search))).'%')
                ->orWhere('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%')))
            ->when($this->groupFilter !== '', fn ($q) => $q->whereHas('groups', fn ($g) => $g->where('audience_groups.id', (int) $this->groupFilter)))
            ->when($this->status === 'mailable', fn ($q) => $q->mailable())
            ->when($this->status === 'unsubscribed', fn ($q) => $q->whereNotNull('unsubscribed_at'))
            ->when($this->status === 'suppressed', fn ($q) => $q->whereNotNull('suppressed_at'))
            ->orderBy('email')
            ->paginate(25);

        return ['groups' => $groups, 'recipients' => $recipients, 'canManage' => Gate::allows('manage-audience')];
    }
};
?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Audience') }}</h2>
            <div class="space-x-4 text-sm">
                <a href="{{ route('audience.import') }}" wire:navigate class="text-indigo-600 hover:underline">{{ __('Import CSV') }}</a>
                <a href="{{ route('audience.suppressions') }}" wire:navigate class="text-indigo-600 hover:underline">{{ __('Suppressions') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($message)
                <div class="bg-green-50 text-green-800 rounded-md p-3 text-sm" role="status">{{ $message }}</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex flex-wrap gap-3">
                    <x-text-input wire:model.live.debounce.300ms="search" placeholder="Search email or name" aria-label="Search" class="w-64" />
                    <select wire:model.live="groupFilter" aria-label="Group" class="border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="">All groups</option>
                        @foreach ($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                    </select>
                    <select wire:model.live="status" aria-label="Status" class="border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="">Any status</option>
                        <option value="mailable">Mailable</option>
                        <option value="unsubscribed">Unsubscribed</option>
                        <option value="suppressed">Suppressed</option>
                    </select>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-500">
                            <tr>
                                <th class="py-2 pe-4 text-start font-medium">Email</th>
                                <th class="pe-4 text-start font-medium">Name</th>
                                <th class="pe-4 text-start font-medium">Groups</th>
                                <th class="pe-4 text-start font-medium">Status</th>
                                <th class="text-start font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recipients as $r)
                                <tr class="border-t align-top" wire:key="recipient-{{ $r->id }}">
                                    <td class="py-2 pe-4">{{ $r->email }}</td>
                                    @if ($editId === $r->id)
                                        <td class="pe-4" colspan="3">
                                            <x-text-input wire:model="editName" aria-label="Name" class="w-full mb-2" />
                                            <div class="flex flex-wrap gap-3">
                                                @foreach ($groups as $g)
                                                    <label class="inline-flex items-center gap-1"><input type="checkbox" wire:model="editGroups" value="{{ $g->id }}" class="rounded border-gray-300"> {{ $g->name }}</label>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap space-x-3">
                                            <button wire:click="saveEdit" class="text-indigo-600 hover:underline">Save</button>
                                            <button wire:click="cancelEdit" class="text-gray-500 hover:underline">Cancel</button>
                                        </td>
                                    @else
                                        <td class="pe-4">{{ $r->name ?? '—' }}</td>
                                        <td class="pe-4">{{ $r->groups->pluck('name')->join(', ') ?: '—' }}</td>
                                        <td class="pe-4">
                                            @if ($r->unsubscribed_at) Unsubscribed
                                            @elseif ($r->suppressed_at) Suppressed ({{ $r->suppression_reason }})
                                            @elseif (! $r->subscribed) Not subscribed
                                            @else Mailable @endif
                                        </td>
                                        <td class="whitespace-nowrap space-x-3">
                                            @if ($canManage)
                                                <button wire:click="startEdit({{ $r->id }})" class="text-indigo-600 hover:underline">Edit</button>
                                                @unless ($r->isOptedOut())
                                                    <button wire:click="optOut({{ $r->id }})" wire:confirm="Opt this address out? This cannot be undone here." class="text-amber-700 hover:underline">Opt out</button>
                                                @endunless
                                                <button wire:click="remove({{ $r->id }})" wire:confirm="Remove this recipient? Any opt-out record is kept." class="text-red-600 hover:underline">Remove</button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-4 text-gray-500">No recipients match.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $recipients->links() }}
            </div>

            @if ($canManage)
                <div x-data="{ tab: 'add' }" class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex gap-6 border-b mb-4 text-sm" role="tablist">
                        <button type="button" role="tab" x-on:click="tab = 'add'" x-bind:aria-selected="tab === 'add'" x-bind:class="tab === 'add' ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700'" class="-mb-px pb-2 border-b-2 font-medium">{{ __('Add a recipient') }}</button>
                        <button type="button" role="tab" x-on:click="tab = 'paste'" x-bind:aria-selected="tab === 'paste'" x-bind:class="tab === 'paste' ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700'" class="-mb-px pb-2 border-b-2 font-medium">{{ __('Paste a list') }}</button>
                        <a href="{{ route('audience.import') }}" wire:navigate class="ms-auto pb-2 text-indigo-600 hover:underline">{{ __('Import CSV') }} →</a>
                    </div>
                    <div x-show="tab === 'add'" role="tabpanel">
                        <form wire:submit="addRecipient" class="grid gap-3 max-w-xl">
                        <div>
                            <x-input-label for="newEmail" value="Email" />
                            <x-text-input wire:model="newEmail" id="newEmail" class="block mt-1 w-full" />
                            <x-input-error :messages="$errors->get('newEmail')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="newName" value="Name (optional)" />
                            <x-text-input wire:model="newName" id="newName" class="block mt-1 w-full" />
                        </div>
                        <div>
                            <x-input-label for="newGroup" value="Group" />
                            <select wire:model="newGroup" id="newGroup" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">Choose…</option>
                                @foreach ($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                            </select>
                            <x-input-error :messages="$errors->get('newGroup')" class="mt-1" />
                        </div>
                        <div><x-primary-button>{{ __('Add') }}</x-primary-button></div>
                    </form>
                    </div>
                    <div x-show="tab === 'paste'" style="display: none" role="tabpanel">
                        <form wire:submit="paste" class="grid gap-3 max-w-xl">
                        <div>
                            <x-input-label for="pasteText" value="One per line: email, or email, Name, or Name <email>" />
                            <textarea wire:model="pasteText" id="pasteText" rows="5" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm text-sm"></textarea>
                            <x-input-error :messages="$errors->get('pasteText')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="pasteGroup" value="Group" />
                            <select wire:model="pasteGroup" id="pasteGroup" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">Choose…</option>
                                @foreach ($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                            </select>
                            <x-input-error :messages="$errors->get('pasteGroup')" class="mt-1" />
                        </div>
                        <div><x-primary-button>{{ __('Add list') }}</x-primary-button></div>
                    </form>
                    </div>
                </div>
            @endif

        </div>
    </div>
</div>
