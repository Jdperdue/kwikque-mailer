<x-layouts.public :tenant="$tenant" title="Confirm subscription">
@if ($done)
    @if ($ok)
        <h1>You're subscribed</h1>
        <p>Thank you. Your subscription to {{ $tenant->name }} is confirmed.</p>
    @else
        <h1>We couldn't confirm this</h1>
        <p>This address can't be subscribed through this link.</p>
    @endif
@else
    <h1>Confirm your subscription</h1>
    <p>Confirm that you want to receive email from {{ $tenant->name }}.</p>
    <form method="POST" action="{{ route('public.confirm.do', $token) }}">@csrf
        <button type="submit">Yes, subscribe me</button>
    </form>
@endif
</x-layouts.public>
