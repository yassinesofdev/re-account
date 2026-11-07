<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOrganization;
use App\Http\Controllers\Controller;
use App\Policies\OrganizationPolicy;
use App\Services\Billing\BillingService;
use App\Services\OrgService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrgController extends Controller
{
    use ResolvesOrganization;

    public function __construct(
        private readonly OrgService $orgs,
        private readonly BillingService $billing,
    ) {}

    public function index(Request $request)
    {
        $organizations = $request->user()->organizations()->withCount('users as member_count')->get();

        return response()->json(['organizations' => $organizations]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', 'unique:organizations,slug'],
            'logo_url' => ['nullable', 'url'],
        ]);

        $org = $this->orgs->createOrganization($request->user(), $data['name'], $data['slug'] ?? null, $data['logo_url'] ?? null);

        return response()->json(['organization' => $org], 201);
    }

    public function show(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug);
        $role = $this->orgs->membershipFor($org, $request->user())?->role;

        return response()->json([
            'organization' => $org->loadCount('users as member_count'),
            'my_role' => $role,
            'permissions' => OrganizationPolicy::permissionsFor($role),
        ]);
    }

    /**
     * Organization settings (owner + admins). Every field is optional, so the
     * settings form can send the whole object or a single field.
     */
    public function update(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug, 'update');

        // Standard SaaS nicety: "acme.ae" → "https://acme.ae".
        $website = $request->input('website');
        if (is_string($website) && $website !== '' && ! preg_match('#^https?://#i', $website)) {
            $request->merge(['website' => 'https://'.$website]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('organizations', 'slug')->ignore($org->id)],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()\-]{5,30}$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'orn' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{1,20}$/', Rule::unique('organizations', 'orn')->ignore($org->id)],
            'trade_license_number' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9\/\- ]*$/'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
            'brand_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'phone.regex' => 'Enter a valid phone number, e.g. +971 4 123 4567.',
            'orn.regex' => 'The ORN must contain digits only.',
            'orn.unique' => 'Another organization is already registered with this ORN.',
            'trade_license_number.regex' => 'Use letters, digits, spaces, "-" or "/" only.',
            'brand_color.regex' => 'Use a hex colour like #10B981.',
        ]);

        if (isset($data['brand_color'])) {
            $data['brand_color'] = strtoupper($data['brand_color']);
        }

        $org->fill($data)->save();

        return response()->json(['organization' => $org->fresh()->loadCount('users as member_count')]);
    }

    /**
     * Upload a logo (owner + admins). Same pattern as the profile avatar.
     */
    public function uploadLogo(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug, 'update');

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $path = $request->file('logo')->store('org-logos', 'public');
        $org->forceFill(['logo_url' => asset('storage/'.$path)])->save();

        return response()->json(['organization' => $org->fresh()->loadCount('users as member_count')]);
    }

    public function destroy(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug, 'delete');

        // Paid subscriptions are canceled in Stripe right away, no refund.
        $this->billing->cancelForDeletion($org);

        $org->delete();

        return response()->json(['message' => 'Organization deleted.']);
    }
}
