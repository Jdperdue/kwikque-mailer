<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $id = (int) $request->validate(['tenant_id' => ['required', 'integer']])['tenant_id'];

        // Only tenants the user can actually access; anything else is a 403.
        abort_unless($request->user()->accessibleTenants()->contains('id', $id), 403);

        $request->session()->put('tenant_id', $id);

        return redirect()->route('dashboard');
    }
}
