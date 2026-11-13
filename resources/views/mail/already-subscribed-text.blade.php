Someone (hopefully you) tried to subscribe this address to email from {!! $tenantName !!}. You are already subscribed, so there is nothing more to do.

Manage my preferences: {!! $preferencesUrl !!}
Unsubscribe: {!! $unsubscribeUrl !!}

If you didn't ask for this, you can ignore this message.
@if ($postalAddress !== '')

{!! $postalAddress !!}
@endif
