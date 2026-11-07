<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Look up an org by slug (404) and authorize the caller against
 * OrganizationPolicy (403 with the policy's message).
 */
trait ResolvesOrganization
{
    /**
     * @param  string  $ability  an OrganizationPolicy ability (view, update, manageMembers, …)
     */
    protected function resolveOrg(Request $request, string $slug, string $ability = 'view'): Organization
    {
        $org = Organization::where('slug', $slug)->firstOrFail();

        Gate::forUser($request->user())->authorize($ability, $org);

        return $org;
    }
}
