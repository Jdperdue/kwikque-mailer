<?php

declare(strict_types=1);

use App\Models\AudienceGroup;
use App\Models\Recipient;
use App\Support\Audit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?string $message = null;

    public string $groupName = '';
    public ?int $renameGroupId = null;
    public string $renameGroupName = '';

    public function addGroup(): void
    {
        Gate::authorize('manage-audience');
        $name = trim($this->groupName);
        $this->groupName = $name;
        $this->validate(['groupName' => ['required', 'string', 'max:255', $this->uniqueGroupRule(null)]]);

        $group = AudienceGroup::create(['name' => $name, 'slug' => Str::slug($name)]);
        Audit::record('group.created', $group, ['name' => $name]);

        $this->reset('groupName');
        $this->message = "Group \"{$name}\" added.";
    }

    /** Public groups are the only ones a visitor can pick on the subscribe form or change on their preferences page. */
    public function togglePublic(int $id): void
    {
        Gate::authorize('manage-audience');
        $g = AudienceGroup::findOrFail($id);
        $g->update(['is_public' => ! $g->is_public]);
        Audit::record('group.public_toggled', $g, ['is_public' => $g->is_public]);
    }

    public function startRenameGroup(int $id): void
    {
        $g = AudienceGroup::findOrFail($id);
        $this->renameGroupId = $g->id;
        $this->renameGroupName = $g->name;
    }

    public function cancelRenameGroup(): void
    {
        $this->reset(['renameGroupId', 'renameGroupName']);
        $this->resetValidation('renameGroupName');
    }

    public function saveGroup(): void
    {
        Gate::authorize('manage-audience');
        $group = AudienceGroup::findOrFail($this->renameGroupId);
        $name = trim($this->renameGroupName);
        $this->renameGroupName = $name;
        $this->validate(['renameGroupName' => ['required', 'string', 'max:255', $this->uniqueGroupRule($group->id)]]);

        // The slug is kept stable on rename so anything referring to it keeps working.
        $group->update(['name' => $name]);
        Audit::record('group.renamed', $group, ['name' => $name]);

        $this->cancelRenameGroup();
    }

    public function deleteGroup(int $id): void
    {
        Gate::authorize('manage-audience');
        $group = AudienceGroup::findOrFail($id);

        if ($group->recipients()->exists()) {
            $this->addError('groupDelete', "\"{$group->name}\" still has recipients. Move or remove them first.");

            return;
        }

        Audit::record('group.deleted', $group, ['name' => $group->name]);
        $group->delete();
        $this->message = "Group \"{$group->name}\" deleted.";
    }

    private function uniqueGroupRule(?int $ignoreId): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId): void {
            $slug = Str::slug((string) $value);
            if ($slug === '') {
                $fail('The name must contain letters or numbers.');

                return;
            }
            $clash = AudienceGroup::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where(fn ($q) => $q->where('slug', $slug)->orWhere('name', $value))
                ->exists();
            if ($clash) {
                $fail('A group with this name already exists.');
            }
        };
    }

    public function with(): array
    {
        $groups = AudienceGroup::orderBy('name')->get()->each(function ($g) {
            $g->total = $g->recipients()->count();
            $g->mailable = $g->recipients()->mailable()->count();
        });

        return [
            'tenantName' => app(TenantContext::class)->current()->name,
            'groups' => $groups,
            'people' => Recipient::count(),
            'mailablePeople' => Recipient::mailable()->count(),
            'canManage' => Gate::allows('manage-audience'),
        ];
    }
};
?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Dashboard') }}</h2>
            <span class="text-sm text-gray-500">{{ $tenantName }}</span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($message)
                <div class="bg-green-50 text-green-800 rounded-md p-3 text-sm" role="status">{{ $message }}</div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <div class="text-sm text-gray-500">People</div>
                    <div class="text-2xl font-semibold text-gray-900">{{ number_format($people) }}</div>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <div class="text-sm text-gray-500">Mailable (unique)</div>
                    <div class="text-2xl font-semibold text-gray-900">{{ number_format($mailablePeople) }}</div>
                </div>
            </div>

            <div>
                <h3 class="font-semibold text-gray-800 mb-2">{{ __('Groups') }}</h3>
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <table class="w-full text-sm text-start">
                    <thead class="text-gray-500">
                        <tr>
                            <th class="py-2 pe-4 text-start font-medium">Group</th>
                            <th class="pe-4 text-start font-medium">Recipients</th>
                            <th class="pe-4 text-start font-medium">Mailable</th>
                            <th class="text-start font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($groups as $g)
                            <tr class="border-t" wire:key="group-{{ $g->id }}">
                                @if ($renameGroupId === $g->id)
                                    <td class="py-2 pe-4" colspan="3">
                                        <x-text-input wire:model="renameGroupName" wire:keydown.enter="saveGroup" aria-label="Group name" class="w-full max-w-sm" />
                                        <x-input-error :messages="$errors->get('renameGroupName')" class="mt-1" />
                                    </td>
                                    <td class="whitespace-nowrap space-x-3">
                                        <button wire:click="saveGroup" class="text-indigo-600 hover:underline">Save</button>
                                        <button wire:click="cancelRenameGroup" class="text-gray-500 hover:underline">Cancel</button>
                                    </td>
                                @else
                                    <td class="py-2 pe-4">{{ $g->name }}@if ($g->is_public) <span class="text-xs text-gray-500">(public)</span>@endif</td>
                                    <td class="pe-4">{{ number_format($g->total) }}</td>
                                    <td class="pe-4">{{ number_format($g->mailable) }}</td>
                                    <td class="whitespace-nowrap space-x-3">
                                        @if ($canManage)
                                            <button wire:click="togglePublic({{ $g->id }})" class="text-indigo-600 hover:underline">{{ $g->is_public ? 'Public: on' : 'Public: off' }}</button>
                                            <button wire:click="startRenameGroup({{ $g->id }})" class="text-indigo-600 hover:underline">Rename</button>
                                            @if ($g->total === 0)
                                                <button wire:click="deleteGroup({{ $g->id }})" wire:confirm="Delete the empty group &quot;{{ $g->name }}&quot;?" class="text-red-600 hover:underline">Delete</button>
                                            @endif
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-gray-500">No groups yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-input-error :messages="$errors->get('groupDelete')" class="mt-2" />
                @if ($canManage)
                    <form wire:submit="addGroup" class="mt-4 flex flex-wrap items-start gap-3 border-t pt-4">
                        <div>
                            <x-text-input wire:model="groupName" aria-label="New group name" placeholder="New group, e.g. Bidders - Inactive" class="w-72" />
                            <x-input-error :messages="$errors->get('groupName')" class="mt-1" />
                        </div>
                        <x-primary-button>{{ __('Add group') }}</x-primary-button>
                    </form>
                @endif
                <p class="mt-3 text-xs text-gray-500">Mailable = subscribed and not unsubscribed or suppressed (the platform bounce/complaint list is also checked at send time).</p>
            </div>
            </div>
        </div>
    </div>
</div>
