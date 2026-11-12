<?php

declare(strict_types=1);

use App\Jobs\ProcessRecipientImport;
use App\Models\AudienceGroup;
use App\Models\ImportRun;
use App\Support\Audit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public $file = null;
    public string $groupId = '';

    public function upload(): void
    {
        Gate::authorize('manage-audience');
        $this->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'groupId' => ['required', 'integer'],
        ]);

        $group = AudienceGroup::findOrFail((int) $this->groupId);
        $tenantId = app(TenantContext::class)->requireId('store an import file');
        $path = $this->file->storeAs("imports/{$tenantId}", Str::uuid()->toString().'.csv', 'local');

        $run = ImportRun::create([
            'user_id' => auth()->id(),
            'audience_group_id' => $group->id,
            'source' => 'csv',
            'filename' => mb_substr($this->file->getClientOriginalName(), 0, 255),
            'stored_path' => $path,
            'file_hash' => hash_file('sha256', $this->file->getRealPath()),
            'status' => 'pending',
        ]);
        Audit::record('import.queued', $run, ['group' => $group->slug]);

        ProcessRecipientImport::dispatch($run->id);

        $this->reset('file');
    }

    public function with(): array
    {
        $runs = ImportRun::with('group')->where('source', '!=', 'suppressions')->latest('id')->limit(25)->get();

        return [
            'groups' => AudienceGroup::orderBy('name')->get(),
            'runs' => $runs,
            'active' => $runs->contains(fn ($r) => in_array($r->status, ['pending', 'running'], true)),
            'canManage' => Gate::allows('manage-audience'),
        ];
    }
};
?>

<div @if ($active) wire:poll.3s @endif>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Import recipients') }}</h2>
            <a href="{{ route('audience') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">{{ __('Back to audience') }}</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($canManage)
                <form wire:submit="upload" class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-3 max-w-xl">
                    <p class="text-sm text-gray-600">CSV with an <code>email</code> column. Optional: <code>name</code> (or <code>first_name</code> and <code>last_name</code>) and <code>external_id</code>. Legacy exports are recognised too: <code>bidder_id</code>, <code>client_id</code> or <code>email_id</code> is stored as the legacy id. Re-importing a file is safe. Anyone who has unsubscribed or been suppressed stays opted out.</p>
                    <div>
                        <x-input-label for="file" value="CSV file (max 10 MB)" />
                        <input type="file" wire:model="file" id="file" accept=".csv,.txt,text/csv" class="block mt-1 w-full text-sm" />
                        <x-input-error :messages="$errors->get('file')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="groupId" value="Add to group" />
                        <select wire:model="groupId" id="groupId" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">Choose…</option>
                            @foreach ($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                        </select>
                        <x-input-error :messages="$errors->get('groupId')" class="mt-1" />
                    </div>
                    <div><x-primary-button>{{ __('Upload and import') }}</x-primary-button></div>
                </form>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <h3 class="font-semibold text-gray-800 mb-3">{{ __('Import history') }}</h3>
                <table class="w-full text-sm">
                    <thead class="text-gray-500">
                        <tr>
                            @foreach (['When', 'File', 'Group', 'Status', 'Rows', 'Created', 'Updated', 'Unchanged', 'Invalid', 'Duplicate', 'Kept opted out', ''] as $h)
                                <th class="py-2 pe-3 text-start font-medium">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($runs as $r)
                            <tr class="border-t" wire:key="run-{{ $r->id }}">
                                <td class="py-2 pe-3 whitespace-nowrap">{{ $r->created_at->setTimezone(app(\App\Support\Tenancy\TenantContext::class)->current()->timezone)->format('M j, g:i a') }}</td>
                                <td class="pe-3">{{ $r->filename ?? $r->source }}</td>
                                <td class="pe-3">{{ $r->group?->name ?? '—' }}</td>
                                <td class="pe-3">{{ $r->status }}</td>
                                <td class="pe-3">{{ $r->total_rows }}</td>
                                <td class="pe-3">{{ $r->created_count }}</td>
                                <td class="pe-3">{{ $r->updated_count }}</td>
                                <td class="pe-3">{{ $r->unchanged_count }}</td>
                                <td class="pe-3">{{ $r->invalid_count }}</td>
                                <td class="pe-3">{{ $r->duplicate_count }}</td>
                                <td class="pe-3">{{ $r->protected_count }}</td>
                                <td><a href="{{ route('audience.imports.show', $r->id) }}" wire:navigate class="text-indigo-600 hover:underline">Details</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="py-3 text-gray-500">No imports yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
