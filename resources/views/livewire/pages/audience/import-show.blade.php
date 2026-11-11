<?php

declare(strict_types=1);

use App\Models\ImportRun;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ImportRun $run;

    // ImportRun is tenant-scoped: another tenant's id resolves to a 404, not their data.
    public function mount(ImportRun $run): void
    {
        $this->run = $run;
    }
};
?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Import') }} #{{ $run->id }}</h2>
            <a href="{{ route('audience.import') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">{{ __('Back to imports') }}</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm grid gap-1">
                <div><span class="text-gray-500">File:</span> {{ $run->filename ?? $run->source }}</div>
                <div><span class="text-gray-500">Status:</span> {{ $run->status }}</div>
                @if ($run->failure_message)<div class="text-red-700">{{ $run->failure_message }}</div>@endif
                <div>Rows {{ $run->total_rows }} · created {{ $run->created_count }} · updated {{ $run->updated_count }} · unchanged {{ $run->unchanged_count }} · invalid {{ $run->invalid_count }} · duplicate {{ $run->duplicate_count }} · kept opted out {{ $run->protected_count }}</div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <h3 class="font-semibold text-gray-800 mb-3">{{ __('Rows needing attention') }}</h3>
                @if (empty($run->errors))
                    <p class="text-sm text-gray-500">None.</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="text-gray-500"><tr><th class="py-2 pe-4 text-start font-medium">Row</th><th class="pe-4 text-start font-medium">Value</th><th class="text-start font-medium">Reason</th></tr></thead>
                        <tbody>
                            @foreach ($run->errors as $e)
                                <tr class="border-t"><td class="py-2 pe-4">{{ $e['row'] }}</td><td class="pe-4">{{ $e['email'] ?? '—' }}</td><td>{{ $e['reason'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($run->invalid_count + $run->duplicate_count > count($run->errors))
                        <p class="mt-2 text-xs text-gray-500">Showing the first {{ count($run->errors) }} problems; counts above are complete.</p>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
