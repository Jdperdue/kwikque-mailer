<?php

declare(strict_types=1);

use App\Jobs\ProcessSuppressionImport;
use App\Models\ImportRun;
use App\Models\PlatformSuppression;
use App\Models\Suppression;
use App\Services\OptOuts;
use App\Services\SuppressionImporter;
use App\Support\Audit;
use App\Support\Audience\EmailAddress;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination, WithFileUploads;

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

    public $file = null;
    public string $reason = '';
    public string $confirmText = '';
    public ?string $message = null;

    // Set only by the server after a successful preview; cannot be changed from the browser.
    #[Locked]
    public string $previewPath = '';
    #[Locked]
    public string $previewReason = '';
    #[Locked]
    public string $previewName = '';
    #[Locked]
    public array $preview = [];

    /** Step 1: read the file and show what it would do. Writes nothing. */
    public function previewImport(SuppressionImporter $importer): void
    {
        Gate::authorize('manage-audience');
        $this->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'reason' => ['required', 'in:'.implode(',', SuppressionImporter::REASONS)],
        ]);

        $this->discardPreview();
        $tenantId = app(TenantContext::class)->requireId('store a suppression file');
        $path = $this->file->storeAs("imports/{$tenantId}", Str::uuid()->toString().'.csv', 'local');

        try {
            $preview = $importer->preview(Storage::disk('local')->path($path), $this->reason);
        } catch (\InvalidArgumentException|\League\Csv\Exception $e) {
            Storage::disk('local')->delete($path);
            $this->addError('file', $e instanceof \InvalidArgumentException ? $e->getMessage() : 'The file could not be read as a CSV or address list.');

            return;
        }

        $this->previewPath = $path;
        $this->previewReason = $this->reason;
        $this->previewName = mb_substr($this->file->getClientOriginalName(), 0, 255);
        $this->preview = $preview;
        $this->reset(['file', 'confirmText']);
    }

    public function cancelImport(): void
    {
        $this->discardPreview();
        $this->reset(['file', 'reason', 'confirmText']);
    }

    /** Step 2 and 3: typed confirmation here, plus a browser confirm dialog on the button. */
    public function applyImport(): void
    {
        Gate::authorize('manage-audience');

        $this->resetErrorBag();
        if ($this->previewPath === '') {
            return;
        }
        if (trim($this->confirmText) !== 'SUPPRESS') {
            $this->addError('confirmText', 'Type SUPPRESS (capitals) to confirm.');

            return;
        }

        $run = ImportRun::create([
            'user_id' => auth()->id(),
            'source' => 'suppressions',
            'filename' => $this->previewName,
            'stored_path' => $this->previewPath,
            'file_hash' => hash_file('sha256', Storage::disk('local')->path($this->previewPath)),
            'options' => ['reason' => $this->previewReason],
            'status' => 'pending',
        ]);
        Audit::record('suppression.import.queued', $run, ['reason' => $this->previewReason, 'new' => $this->preview['new'] ?? null]);
        ProcessSuppressionImport::dispatch($run->id);

        $this->message = 'Suppression import queued. It is being applied in the background.';
        $this->previewPath = '';
        $this->previewReason = '';
        $this->previewName = '';
        $this->preview = [];
        $this->reset(['file', 'reason', 'confirmText']);
    }

    private function discardPreview(): void
    {
        if ($this->previewPath !== '') {
            Storage::disk('local')->delete($this->previewPath);
        }
        $this->previewPath = $this->previewReason = $this->previewName = '';
        $this->preview = [];
    }

    public ?int $liftId = null;
    public string $liftWhy = '';

    public function startLift(int $id): void
    {
        Gate::authorize('lift-suppressions');
        $this->liftId = Suppression::findOrFail($id)->id;
        $this->liftWhy = '';
        $this->resetErrorBag();
    }

    public function cancelLift(): void
    {
        $this->reset(['liftId', 'liftWhy']);
        $this->resetErrorBag();
    }

    public function lift(OptOuts $optOuts): void
    {
        Gate::authorize('lift-suppressions');
        $this->validate(['liftWhy' => ['required', 'string', 'min:5', 'max:255']]);

        $s = Suppression::findOrFail($this->liftId);
        $optOuts->lift($s->email, trim($this->liftWhy));

        $this->message = 'Block removed for '.$s->email.'.'
            .(PlatformSuppression::where('email', $s->email)->exists() ? ' It is still on the platform-wide bounce/complaint list, so mail to it stays blocked.' : '');
        $this->cancelLift();
    }

    public function with(): array
    {
        return [
            'suppressions' => Suppression::latest('id')->paginate(25),
            'imports' => ImportRun::where('source', 'suppressions')->latest('id')->limit(5)->get(),
            'reasons' => SuppressionImporter::REASONS,
            'canLift' => Gate::allows('lift-suppressions'),
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

            @if ($message)
                <div class="bg-green-50 text-green-800 rounded-md p-3 text-sm" role="status">{{ $message }}</div>
            @endif

            @if ($canManage)
                <div class="grid gap-6 lg:grid-cols-2">
                    <form wire:submit="add" class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-3 content-start">
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

                    <div class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-3 content-start">
                        <h3 class="font-semibold text-gray-800">{{ __('Import a list of opt-outs') }}</h3>
                        @if ($previewPath === '')
                            <form wire:submit="previewImport" class="grid gap-3">
                                <p class="text-sm text-gray-600">CSV with an <code>email</code> column (optional <code>reason</code> and <code>note</code>), or one address per line. Applies to this account only. Nothing is changed until you confirm on the next step.</p>
                                <div>
                                    <x-input-label for="supFile" value="File (max 10 MB)" />
                                    <input type="file" wire:model="file" id="supFile" accept=".csv,.txt,text/csv" class="block mt-1 w-full text-sm" />
                                    <x-input-error :messages="$errors->get('file')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="supReason" value="Reason for these addresses" />
                                    <select wire:model="reason" id="supReason" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                                        <option value="">Choose…</option>
                                        @foreach ($reasons as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                                </div>
                                <div><x-primary-button>{{ __('Preview') }}</x-primary-button></div>
                            </form>
                        @else
                            <div class="text-sm grid gap-1">
                                <div><span class="text-gray-500">File:</span> {{ $previewName }} · <span class="text-gray-500">reason:</span> {{ $previewReason }}</div>
                                <div>{{ number_format($preview['total']) }} rows: <strong>{{ number_format($preview['new']) }}</strong> new, {{ number_format($preview['existing']) }} already suppressed, {{ number_format($preview['invalid']) }} invalid, {{ number_format($preview['duplicate']) }} duplicate.</div>
                                <div class="text-amber-800">{{ number_format($preview['deactivated']) }} currently mailable {{ Str::plural('person', $preview['deactivated']) }} will stop receiving mail.</div>
                                @if ($preview['sample'])<div class="text-gray-500">e.g. {{ implode(', ', $preview['sample']) }}</div>@endif
                            </div>
                            @if ($preview['new'] === 0)
                                <p class="text-sm text-gray-600">Nothing new to suppress.</p>
                                <div><button wire:click="cancelImport" class="text-gray-600 hover:underline text-sm">Close</button></div>
                            @else
                                <div>
                                    <x-input-label for="confirmText" value="Type SUPPRESS to confirm. This cannot be undone from the app." />
                                    <x-text-input wire:model="confirmText" id="confirmText" autocomplete="off" class="block mt-1 w-full" />
                                    <x-input-error :messages="$errors->get('confirmText')" class="mt-1" />
                                </div>
                                <div class="flex items-center gap-4">
                                    <x-danger-button type="button" wire:click="applyImport" wire:confirm="Permanently suppress {{ number_format($preview['new']) }} addresses for this account? This cannot be undone from the app.">{{ __('Apply suppressions') }}</x-danger-button>
                                    <button type="button" wire:click="cancelImport" class="text-sm text-gray-600 hover:underline">{{ __('Cancel') }}</button>
                                </div>
                            @endif
                        @endif
                        @if ($imports->isNotEmpty())
                            <div class="border-t pt-3 text-xs text-gray-600 grid gap-1">
                                @foreach ($imports as $i)
                                    <div wire:key="supimp-{{ $i->id }}">{{ $i->created_at->setTimezone($tz)->format('M j, g:i a') }} · {{ $i->filename }} · {{ $i->status }} · {{ $i->created_count }} added, {{ $i->updated_count }} switched off · <a href="{{ route('audience.imports.show', $i->id) }}" wire:navigate class="text-indigo-600 hover:underline">Details</a></div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-gray-500"><tr><th class="py-2 pe-4 text-start font-medium">Email</th><th class="pe-4 text-start font-medium">Reason</th><th class="pe-4 text-start font-medium">Note</th><th class="pe-4 text-start font-medium">Added</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($suppressions as $s)
                            <tr class="border-t" wire:key="sup-{{ $s->id }}">
                                <td class="py-2 pe-4">{{ $s->email }}</td>
                                <td class="pe-4">{{ $s->reason }}</td>
                                <td class="pe-4">{{ $s->note ?? '—' }}</td>
                                <td class="pe-4">{{ $s->created_at->setTimezone($tz)->format('M j, Y') }}</td>
                                <td class="whitespace-nowrap">
                                    @if ($canLift && $liftId !== $s->id)
                                        <button wire:click="startLift({{ $s->id }})" class="text-red-600 hover:underline">Remove block</button>
                                    @endif
                                </td>
                            </tr>
                            @if ($canLift && $liftId === $s->id)
                                <tr class="bg-red-50" wire:key="lift-{{ $s->id }}">
                                    <td colspan="5" class="p-3">
                                        <div class="text-sm text-red-900 mb-2">
                                            Mail to {{ $s->email }} will resume at the next send.
                                            @if (in_array($s->reason, ['unsubscribe', 'complaint'], true))
                                                <strong>This person asked not to be mailed ({{ $s->reason }}). Only continue if you have their fresh consent.</strong>
                                            @endif
                                        </div>
                                        <div class="flex flex-wrap items-start gap-3">
                                            <div>
                                                <x-text-input wire:model="liftWhy" aria-label="Why is this block being removed?" placeholder="Why is this block being removed?" class="w-96" />
                                                <x-input-error :messages="$errors->get('liftWhy')" class="mt-1" />
                                            </div>
                                            <x-danger-button type="button" wire:click="lift" wire:confirm="Remove the block on {{ $s->email }}? They will be mailed again.">{{ __('Remove block') }}</x-danger-button>
                                            <button type="button" wire:click="cancelLift" class="text-sm text-gray-600 hover:underline self-center">{{ __('Cancel') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="5" class="py-3 text-gray-500">No suppressions.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="mt-3">{{ $suppressions->links() }}</div>
            </div>
        </div>
    </div>
</div>
