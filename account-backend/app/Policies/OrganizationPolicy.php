<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The single source of truth for who can do what in an organization.
 *
 * Owner  = everything admins can do + billing, delete. Exactly one per org
 *          (the creator); ownership can never be transferred, the owner
 *          cannot be removed, demoted or leave (only delete the org).
 * Admin  = invites, remove members (not the owner), admin<->member roles,
 *          product access, organization settings.
 * Member = read-only; can leave.
 *
 * Controllers call these through Gate (see Concerns\ResolvesOrganization);
 * `GET orgs/{slug}` returns `permissions` built from the same matrix so the
 * frontend never re-implements it.
 */
class OrganizationPolicy
{
    /** @var array<string, array<int, string>> ability => roles allowed */
    public const MATRIX = [
        'view' => ['owner', 'admin', 'member'],
        'update' => ['owner', 'admin'],
        'manageInvites' => ['owner', 'admin'],
        'manageMembers' => ['owner', 'admin'],
        'manageProductAccess' => ['owner', 'admin'],
        'manageBilling' => ['owner'],
        'delete' => ['owner'],
        'leave' => ['admin', 'member'],
    ];

    public const NOT_A_MEMBER = 'You are not a member of this organization.';

    public const NOT_ALLOWED = 'You do not have permission to perform this action.';

    /**
     * Permission flags for the frontend: {update: bool, manage_members: bool, …}.
     *
     * @return array<string, bool>
     */
    public static function permissionsFor(?string $role): array
    {
        $flags = [];

        foreach (self::MATRIX as $ability => $roles) {
            $flags[self::snake($ability)] = $role !== null && in_array($role, $roles, true);
        }

        return $flags;
    }

    public function view(User $user, Organization $org): Response
    {
        return $this->check($user, $org, 'view');
    }

    public function update(User $user, Organization $org): Response
    {
        return $this->check($user, $org, 'update');
    }

    public function manageInvites(User $user, Organization $org): Response
    {
        return $this->check($user, $org, 'manageInvites');
    }

    public function manageMembers(User $user, Organization $org): Response
    {
        return $this->check($user, $org, 'manageMembers');
    }

    public function manageProductAccess(User $user, Organization $org): Response
    {
        return $this->check($user, $org, 'manageProductAccess');
    }

    public function manageBilling(User $user, Organization $org): Response
    {
        return $this->check($user, $org, 'manageBilling');
    }

    public function delete(User $user, Organization $org): Response
    {
        return $this->check($user, $org, 'delete');
    }

    public function leave(User $user, Organization $org): Response
    {
        $membership = $this->membership($user, $org);

        if ($membership?->isOwner()) {
            return Response::deny('The owner cannot leave the organization. Delete the organization instead.');
        }

        return $this->check($user, $org, 'leave', $membership);
    }

    /**
     * Remove $target from the org. Nobody can remove the owner.
     */
    public function removeMember(User $user, Organization $org, OrgMember $target): Response
    {
        $result = $this->check($user, $org, 'manageMembers');

        if ($result->denied()) {
            return $result;
        }

        return $target->isOwner()
            ? Response::deny('The organization owner cannot be removed.')
            : Response::allow();
    }

    /**
     * Change $target between admin and member. The owner's role never changes.
     */
    public function changeRole(User $user, Organization $org, OrgMember $target): Response
    {
        $result = $this->check($user, $org, 'manageMembers');

        if ($result->denied()) {
            return $result;
        }

        return $target->isOwner()
            ? Response::deny("The owner's role cannot be changed.")
            : Response::allow();
    }

    private function check(User $user, Organization $org, string $ability, ?OrgMember $membership = null): Response
    {
        $membership ??= $this->membership($user, $org);

        if (! $membership) {
            return Response::deny(self::NOT_A_MEMBER);
        }

        return in_array($membership->role, self::MATRIX[$ability], true)
            ? Response::allow()
            : Response::deny(self::NOT_ALLOWED);
    }

    private function membership(User $user, Organization $org): ?OrgMember
    {
        return $org->members()->where('user_id', $user->id)->first();
    }

    private static function snake(string $ability): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $ability));
    }
}
