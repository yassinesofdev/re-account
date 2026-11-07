<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOrganization;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\OrgService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrgInviteController extends Controller
{
    use ResolvesOrganization;

    public function __construct(private readonly OrgService $orgs) {}

    public function index(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug, 'manageInvites');

        $invites = $org->invites()->whereNull('accepted_at')->with('products:id,slug,name')->get();

        return response()->json(['invites' => $invites->map(fn ($invite) => OrgService::presentInvite($invite))]);
    }

    public function store(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug, 'manageInvites');

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in(['admin', 'member'])],
            // [id] or [{product_id, role?}]; `products` is the same with objects.
            'product_ids' => ['sometimes', 'array'],
            'products' => ['sometimes', 'array'],
            'products.*.product_id' => ['required', 'string', 'uuid'],
            'products.*.role' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Legacy: one product by slug. Granted when the org subscribes to it.
            'product_slug' => ['sometimes', 'nullable', 'string', 'exists:products,slug'],
        ]);

        $key = isset($data['products']) ? 'products' : 'product_ids';
        $items = $data[$key] ?? null;

        if ($items === null && ! empty($data['product_slug'])) {
            $legacy = Product::where('slug', $data['product_slug'])->firstOrFail();
            $items = $this->orgs->orgProducts($org)->contains('id', $legacy->id) ? [$legacy->id] : [];
        }

        $selection = OrgMemberController::parseSelectionOrFail($items ?? [], $key);
        $assignments = $this->orgs->resolveProductAssignments($org, $selection, $data['role']);

        // Over a product's limit → SeatLimitExceededException, rendered as 422.
        $invite = $this->orgs->inviteMember($org, $request->user(), $data['email'], $data['role'], $assignments);

        return response()->json(['invite' => OrgService::presentInvite($invite)], 201);
    }

    public function destroy(Request $request, string $slug, string $id)
    {
        $org = $this->resolveOrg($request, $slug, 'manageInvites');

        abort_if(! Str::isUuid($id) || ! $org->invites()->whereNull('accepted_at')->where('id', $id)->delete(), 404, 'Invite not found.');

        return response()->json(['message' => 'Invite revoked.']);
    }

    public function show(string $token)
    {
        $invite = $this->orgs->findInviteByToken($token);

        abort_if(! $invite, 404, 'This invitation link is invalid.');

        return response()->json([
            'invite' => [
                'email' => $invite->email,
                'role' => $invite->role,
                'organization' => $invite->organization,
                'products' => $invite->products()->get(['products.id', 'products.slug', 'products.name'])
                    ->map(fn (Product $p) => [...$p->only(['id', 'slug', 'name']), 'role' => $p->pivot->role])->values(),
                'is_expired' => $invite->isExpired(),
                'is_accepted' => $invite->isAccepted(),
            ],
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $invite = $this->orgs->findInviteByToken($token);

        abort_if(! $invite, 404, 'This invitation link is invalid.');

        $member = $this->orgs->acceptInvite($invite, $request->user());

        abort_if(! $member, 422, 'This invitation has expired, was already used, or was sent to a different email address.');

        return response()->json([
            'message' => 'Invitation accepted.',
            'organization' => $invite->organization,
        ]);
    }
}
