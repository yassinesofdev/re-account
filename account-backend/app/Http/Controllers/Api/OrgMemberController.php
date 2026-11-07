<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesOrganization;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Services\OrgService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrgMemberController extends Controller
{
    use ResolvesOrganization;

    public function __construct(private readonly OrgService $orgs) {}

    public function index(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug);

        return response()->json(['members' => $this->orgs->membersWithProducts($org)]);
    }

    /**
     * Replace a member's product access (+ product role):
     * PUT {products: [{product_id, role?}]} or the legacy {product_ids: [id | {product_id, role?}]}.
     * A missing role keeps the current one, else the product's default
     * (owner/admin → manager when the product declares it).
     */
    public function updateProducts(Request $request, string $slug, string $userId)
    {
        $org = $this->resolveOrg($request, $slug, 'manageProductAccess');
        $selection = $this->productSelection($request);
        $target = $this->targetOrFail($org, $userId);

        $assignments = $this->orgs->resolveProductAssignments(
            $org, $selection, $target->role, $this->orgs->memberProductRoles($org, $userId),
        );
        $this->orgs->setMemberProducts($org, $userId, $assignments, $request->user());

        return response()->json([
            'member' => $this->orgs->membersWithProducts($org, $userId)->first(),
            'seats' => $this->orgs->seatSummary($org),
        ]);
    }

    /**
     * Per-product seat usage: [{product, plan, used, limit}].
     */
    public function seats(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug);

        return response()->json(['seats' => $this->orgs->seatSummary($org)]);
    }

    public function destroy(Request $request, string $slug, string $userId)
    {
        $org = $this->resolveOrg($request, $slug, 'manageMembers');
        $target = $this->targetOrFail($org, $userId);

        Gate::forUser($request->user())->authorize('removeMember', [$org, $target]);
        abort_if($userId === $request->user()->id, 422, 'Use "Leave organization" to remove yourself.');

        $this->orgs->removeMember($org, $userId);

        return response()->json(['message' => 'Member removed.']);
    }

    public function updateRole(Request $request, string $slug, string $userId)
    {
        $org = $this->resolveOrg($request, $slug, 'manageMembers');

        $data = $request->validate([
            // Never 'owner': ownership is fixed to the org's creator.
            'role' => ['required', Rule::in(['admin', 'member'])],
        ]);

        $target = $this->targetOrFail($org, $userId);
        Gate::forUser($request->user())->authorize('changeRole', [$org, $target]);

        $this->orgs->updateMemberRole($org, $userId, $data['role']);

        return response()->json(['message' => 'Role updated.']);
    }

    /**
     * The caller leaves the org (admins and members; the owner can only delete the org).
     */
    public function leave(Request $request, string $slug)
    {
        $org = $this->resolveOrg($request, $slug, 'leave');

        $this->orgs->removeMember($org, $request->user()->id);

        return response()->json(['message' => 'You left the organization.']);
    }

    /**
     * @return array<string, ?string> product_id => role|null
     */
    private function productSelection(Request $request): array
    {
        $key = $request->has('products') ? 'products' : 'product_ids';

        $request->validate([
            $key => ['present', 'array'],
            "{$key}.*.product_id" => ['sometimes', 'string', 'uuid'],
            "{$key}.*.role" => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        return self::parseSelectionOrFail($request->input($key), $key);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<string, ?string>
     */
    public static function parseSelectionOrFail(array $items, string $key): array
    {
        $selection = OrgService::parseProductSelection($items);

        foreach (array_keys($selection) as $id) {
            if (! Str::isUuid((string) $id)) {
                throw ValidationException::withMessages([$key => ['Each product must be a product ID.']]);
            }
        }

        return $selection;
    }

    private function targetOrFail(Organization $org, string $userId): OrgMember
    {
        $target = Str::isUuid($userId) ? $org->members()->where('user_id', $userId)->first() : null;
        abort_if(! $target, 404, 'Member not found.');

        return $target;
    }
}
