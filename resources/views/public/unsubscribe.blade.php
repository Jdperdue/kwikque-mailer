<x-layouts.public :tenant="$tenant" title="Unsubscribe">
@if ($done)
    <h1>You have been unsubscribed</h1>
    <p>You will no longer receive email from {{ $tenant->name }}.</p>
@else
    <h1>Unsubscribe</h1>
    <p>Stop receiving email from {{ $tenant->name }}?</p>
    <form method="POST" action="{{ route('public.unsubscribe.do', $token) }}">@csrf
        <button type="submit">Unsubscribe me</button>
    </form>
@endif
</x-layouts.public>
