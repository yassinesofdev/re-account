"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { LeaveOrgButton } from "@/components/LeaveOrgButton";
import { OrgTabs } from "@/components/OrgTabs";
import { RequireAuth } from "@/components/RouteGuard";
import { TopNav } from "@/components/TopNav";
import { Banner, Button, Card, Field, Input } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import type { OrgInvite, OrgMember, OrgRole, ProductAssignment, ProductSeats } from "@/lib/types";
import { useOrg } from "@/lib/useOrg";

const isFull = (s: ProductSeats) => s.limit !== null && s.used >= s.limit;

function seatLabel(s: ProductSeats) {
  return `${s.used}/${s.limit ?? "∞"}`;
}

/** Mirrors the backend default (Product::defaultRoleFor): owner/admin → manager when declared, else the default role. */
function defaultProductRole(s: ProductSeats, orgRole: OrgRole): string | null {
  const roles = s.product.roles ?? [];
  if (orgRole !== "member" && roles.some((r) => r.key === "manager")) return "manager";
  return roles.find((r) => r.default)?.key ?? roles[0]?.key ?? null;
}

function roleName(seats: ProductSeats[], p: ProductAssignment) {
  const roles = seats.find((s) => s.product.id === p.id)?.product.roles ?? [];
  return roles.find((r) => r.key === p.role)?.name ?? p.role;
}

const selectClass =
  "rounded-lg border border-border bg-surface px-2 py-1 text-xs text-foreground focus:border-accent focus:outline-none disabled:opacity-40";

/** Product role select (only for products that declare roles). */
function ProductRoleSelect({
  seat,
  value,
  label,
  disabled,
  onChange,
}: {
  seat: ProductSeats;
  value: string | null;
  label: string;
  disabled?: boolean;
  onChange: (role: string) => void;
}) {
  if (!seat.product.roles?.length) return null;
  return (
    <select aria-label={label} value={value ?? ""} disabled={disabled} onChange={(e) => onChange(e.target.value)} className={selectClass}>
      {seat.product.roles.map((r) => (
        <option key={r.key} value={r.key}>
          {r.name}
        </option>
      ))}
    </select>
  );
}

/** On/off switch for one member × product. "On" uses ink, not the accent. */
function AccessToggle({
  on,
  disabled,
  title,
  label,
  onChange,
}: {
  on: boolean;
  disabled?: boolean;
  title?: string;
  label: string;
  onChange: (next: boolean) => void;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={label}
      title={title}
      disabled={disabled}
      onClick={() => onChange(!on)}
      className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground disabled:cursor-not-allowed disabled:opacity-40 ${
        on ? "border-foreground bg-foreground" : "border-border bg-surface-hover"
      }`}
    >
      <span
        className={`inline-block h-3.5 w-3.5 rounded-full shadow transition-transform duration-200 ${
          on ? "translate-x-[18px] bg-background" : "translate-x-[2px] bg-muted"
        }`}
      />
    </button>
  );
}

function FullProductsNote({ seats, slug, isOwner }: { seats: ProductSeats[]; slug: string; isOwner: boolean }) {
  const full = seats.filter(isFull);
  if (full.length === 0) return null;

  return (
    <div className="mb-4">
      <Banner variant="info">
        {full.map((s) => s.product.name).join(", ")} {full.length === 1 ? "has" : "have"} no seats left. Turn off
        someone&apos;s access to free a seat
        {isOwner ? (
          <>
            , or{" "}
            <Link href={`/orgs/${slug}/settings`} className="font-medium underline">
              upgrade the plan
            </Link>
            .
          </>
        ) : (
          ", or ask the organization owner to upgrade the plan."
        )}
      </Banner>
    </div>
  );
}

function InviteForm({
  slug,
  seats,
  onInvited,
}: {
  slug: string;
  seats: ProductSeats[];
  onInvited: () => void;
}) {
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<Exclude<OrgRole, "owner">>("member");
  // Least privilege: no product is pre-selected.
  const [productIds, setProductIds] = useState<string[]>([]);
  // Explicit product roles; unset → the default for the chosen org role.
  const [productRoles, setProductRoles] = useState<Record<string, string>>({});
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [open, setOpen] = useState(false);

  function toggleProduct(id: string, checked: boolean) {
    setProductIds((ids) => (checked ? [...ids, id] : ids.filter((x) => x !== id)));
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await api.post<{ invite: OrgInvite }>(`/orgs/${slug}/invites`, {
        email,
        role,
        products: productIds.map((id) => {
          const seat = seats.find((s) => s.product.id === id);
          return { product_id: id, role: productRoles[id] ?? (seat ? defaultProductRole(seat, role) : null) };
        }),
      });
      setEmail("");
      setProductIds([]);
      setProductRoles({});
      setOpen(false);
      onInvited();
    } catch (err) {
      if (err instanceof ApiError) setError(err);
    } finally {
      setSubmitting(false);
    }
  }

  if (!open) {
    return (
      <Button variant="secondary" onClick={() => setOpen(true)}>
        Invite member
      </Button>
    );
  }

  return (
    <Card className="w-full max-w-sm">
      <form className="space-y-4" onSubmit={onSubmit}>
        {error && !error.fieldError("email") && <Banner>{error.message}</Banner>}

        <Field label="Email" htmlFor="invite-email" error={error?.fieldError("email")}>
          <Input id="invite-email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
        </Field>

        <Field label="Role" htmlFor="invite-role">
          <select
            id="invite-role"
            value={role}
            onChange={(e) => setRole(e.target.value as Exclude<OrgRole, "owner">)}
            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
          >
            <option value="member">Member</option>
            <option value="admin">Admin</option>
          </select>
        </Field>

        {seats.length > 0 && (
          <fieldset>
            <legend className="mb-1.5 text-sm font-medium text-foreground">Product access</legend>
            <div className="space-y-2">
              {seats.map((s) => {
                const full = isFull(s);
                const checked = productIds.includes(s.product.id);
                return (
                  <div
                    key={s.product.id}
                    className={`flex items-center justify-between gap-3 text-sm ${full ? "text-muted" : "text-foreground"}`}
                  >
                    <label className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        disabled={full}
                        checked={checked}
                        onChange={(e) => toggleProduct(s.product.id, e.target.checked)}
                        className="h-4 w-4 rounded border-border accent-foreground"
                      />
                      {s.product.name}
                    </label>
                    <span className="flex items-center gap-2">
                      {checked && (
                        <ProductRoleSelect
                          seat={s}
                          label={`${s.product.name} role`}
                          value={productRoles[s.product.id] ?? defaultProductRole(s, role)}
                          onChange={(r) => setProductRoles((m) => ({ ...m, [s.product.id]: r }))}
                        />
                      )}
                      <span className="text-xs tabular-nums text-muted">
                        {full ? "No seats left" : `${seatLabel(s)} seats`}
                      </span>
                    </span>
                  </div>
                );
              })}
            </div>
            <p className="mt-1.5 text-xs text-muted">
              You can change access and roles later. Each product uses one seat.
            </p>
          </fieldset>
        )}

        <div className="flex gap-2">
          <Button type="submit" loading={submitting}>
            Send invite
          </Button>
          <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
            Cancel
          </Button>
        </div>
      </form>
    </Card>
  );
}

function MembersContent() {
  const { slug } = useParams<{ slug: string }>();
  const { user } = useAuth();
  const { organization, permissions } = useOrg(slug, "");

  const [members, setMembers] = useState<OrgMember[]>([]);
  const [invites, setInvites] = useState<OrgInvite[]>([]);
  const [seats, setSeats] = useState<ProductSeats[]>([]);
  const [error, setError] = useState<ApiError | string | null>(null);
  const [pending, setPending] = useState<string | null>(null);

  // All from the backend policy (GET /orgs/{slug} → permissions).
  const canInvite = permissions.manage_invites;
  const canManageMembers = permissions.manage_members;
  const canAssign = permissions.manage_product_access;
  const isOwner = permissions.manage_billing;

  const loadMembers = useCallback(async () => {
    const { members } = await api.get<{ members: OrgMember[] }>(`/orgs/${slug}/members`);
    setMembers(members);
  }, [slug]);

  const loadSeats = useCallback(async () => {
    const { seats } = await api.get<{ seats: ProductSeats[] }>(`/orgs/${slug}/seats`);
    setSeats(seats);
  }, [slug]);

  const loadInvites = useCallback(async () => {
    if (!canInvite) return;
    const { invites } = await api.get<{ invites: OrgInvite[] }>(`/orgs/${slug}/invites`);
    setInvites(invites);
  }, [slug, canInvite]);

  useEffect(() => {
    loadMembers();
    loadSeats();
  }, [loadMembers, loadSeats]);

  useEffect(() => {
    loadInvites();
  }, [loadInvites]);

  async function run(action: () => Promise<unknown>, fallback: string) {
    setError(null);
    try {
      await action();
    } catch (err) {
      setError(err instanceof ApiError ? err : fallback);
    }
  }

  /** PUT the member's full product set; a product without `role` gets the backend default. */
  async function saveProducts(member: OrgMember, productId: string, next: { product_id: string; role?: string | null }[]) {
    setPending(`${member.user_id}:${productId}`);
    await run(async () => {
      const res = await api.put<{ member: OrgMember; seats: ProductSeats[] }>(
        `/orgs/${slug}/members/${member.user_id}/products`,
        { products: next },
      );
      setMembers((list) => list.map((m) => (m.user_id === member.user_id ? res.member : m)));
      setSeats(res.seats);
    }, "Could not update access.");
    setPending(null);
  }

  async function setAccess(member: OrgMember, productId: string, on: boolean) {
    const current = member.products.map((p) => ({ product_id: p.id, role: p.role }));
    const next = on ? [...current, { product_id: productId }] : current.filter((p) => p.product_id !== productId);
    await saveProducts(member, productId, next);
  }

  async function setProductRole(member: OrgMember, productId: string, role: string) {
    const next = member.products.map((p) => ({ product_id: p.id, role: p.id === productId ? role : p.role }));
    await saveProducts(member, productId, next);
  }

  async function removeMember(userId: string) {
    if (!confirm("Remove this member from the organization? Their product access is removed too.")) return;
    await run(async () => {
      await api.delete(`/orgs/${slug}/members/${userId}`);
      await Promise.all([loadMembers(), loadSeats()]);
    }, "Could not remove member.");
  }

  async function changeRole(userId: string, role: string) {
    await run(async () => {
      await api.put(`/orgs/${slug}/members/${userId}/role`, { role });
      await loadMembers();
    }, "Could not update role.");
  }

  async function revokeInvite(id: string) {
    await run(async () => {
      await api.delete(`/orgs/${slug}/invites/${id}`);
      await Promise.all([loadInvites(), loadSeats()]);
    }, "Could not revoke invite.");
  }

  async function onInvited() {
    await Promise.all([loadInvites(), loadSeats()]);
  }

  if (!organization) return null;

  const errorMessage = error instanceof ApiError ? error.message : error;
  const seatError = error instanceof ApiError && error.code === "seat_limit_reached";

  return (
    <div className="min-h-screen">
      <TopNav />
      <main className="mx-auto max-w-5xl px-4 py-10">
        <OrgTabs slug={slug} />

        {errorMessage && (
          <div className="mb-4">
            <Banner>
              {errorMessage}
              {seatError && isOwner && (
                <>
                  {" "}
                  <Link href={`/orgs/${slug}/settings`} className="font-medium underline">
                    View plans
                  </Link>
                </>
              )}
            </Banner>
          </div>
        )}

        <div className="mb-4 flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 className="text-lg font-semibold text-foreground">Members</h2>
            {seats.length > 0 ? (
              <div className="mt-2 flex flex-wrap gap-2" aria-label="Seats per product">
                {seats.map((s) => (
                  <span
                    key={s.product.id}
                    className={`rounded-full border px-3 py-1 text-sm tabular-nums ${
                      isFull(s) ? "border-foreground text-foreground" : "border-border text-muted"
                    }`}
                  >
                    <span className="font-medium text-foreground">{s.product.name}</span> {seatLabel(s)}
                  </span>
                ))}
              </div>
            ) : (
              <p className="mt-1 text-sm text-muted">
                This organization doesn&apos;t subscribe to any product yet.{" "}
                {isOwner && (
                  <Link href={`/orgs/${slug}/settings`} className="underline hover:text-foreground">
                    Choose a plan
                  </Link>
                )}
              </p>
            )}
          </div>
          <div className="flex flex-wrap items-start gap-2">
            {canInvite && <InviteForm slug={slug} seats={seats} onInvited={onInvited} />}
            {permissions.leave && <LeaveOrgButton organization={organization} onError={setError} />}
          </div>
        </div>

        {canAssign && <FullProductsNote seats={seats} slug={slug} isOwner={isOwner} />}

        <Card className="overflow-x-auto p-0">
          <table className="w-full text-sm">
            <thead className="border-b border-border text-left text-muted">
              <tr>
                <th className="px-4 py-2 font-medium">Member</th>
                <th className="px-4 py-2 font-medium">Role</th>
                {seats.map((s) => (
                  <th key={s.product.id} className="px-4 py-2 text-center font-medium">
                    {s.product.name}
                  </th>
                ))}
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              {members.map((member) => (
                <tr key={member.id} className="border-b border-border last:border-0">
                  <td className="px-4 py-3">
                    <div className="text-foreground">{member.user.name}</div>
                    <div className="text-xs text-muted">{member.user.email}</div>
                  </td>
                  <td className="px-4 py-3">
                    {canManageMembers && member.role !== "owner" ? (
                      <select
                        value={member.role}
                        onChange={(e) => changeRole(member.user_id, e.target.value)}
                        className={selectClass}
                      >
                        <option value="member">Member</option>
                        <option value="admin">Admin</option>
                      </select>
                    ) : (
                      <span
                        className="text-muted capitalize"
                        title={
                          member.role === "owner" && canManageMembers
                            ? "The owner's role can't be changed"
                            : undefined
                        }
                      >
                        {member.role}
                      </span>
                    )}
                  </td>
                  {seats.map((s) => {
                    const assigned = member.products.find((p) => p.id === s.product.id);
                    const on = Boolean(assigned);
                    const blocked = !on && isFull(s);
                    const busy = pending === `${member.user_id}:${s.product.id}`;
                    return (
                      <td key={s.product.id} className="px-4 py-3">
                        <div className="flex items-center justify-center gap-2">
                          {canAssign ? (
                            <>
                              <AccessToggle
                                on={on}
                                label={`${s.product.name} access for ${member.user.name}`}
                                disabled={blocked || busy}
                                title={blocked ? `${s.product.name} has no seats left` : undefined}
                                onChange={(next) => setAccess(member, s.product.id, next)}
                              />
                              {assigned && (
                                <ProductRoleSelect
                                  seat={s}
                                  value={assigned.role}
                                  label={`${s.product.name} role for ${member.user.name}`}
                                  disabled={busy}
                                  onChange={(role) => setProductRole(member, s.product.id, role)}
                                />
                              )}
                            </>
                          ) : (
                            <span className={on ? "text-foreground" : "text-muted"} aria-label={on ? "Has access" : "No access"}>
                              {on ? "✓" : "—"}
                              {assigned?.role && <span className="ml-1.5 text-xs text-muted">{roleName(seats, assigned)}</span>}
                            </span>
                          )}
                        </div>
                      </td>
                    );
                  })}
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      {canManageMembers && member.role !== "owner" && member.user_id !== user?.id && (
                        <button onClick={() => removeMember(member.user_id)} className="text-xs text-danger hover:underline">
                          Remove
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
        <p className="mt-2 text-xs text-muted">
          {canManageMembers
            ? "The owner can't be removed or have their role changed. The owner can't leave; delete the organization instead."
            : "You have read-only access. Only the organization owner and admins can invite, remove members, change roles or product access."}
        </p>

        {canInvite && invites.length > 0 && (
          <div className="mt-8">
            <h2 className="mb-3 text-lg font-semibold text-foreground">Pending invites</h2>
            <Card className="overflow-x-auto p-0">
              <table className="w-full text-sm">
                <thead className="border-b border-border text-left text-muted">
                  <tr>
                    <th className="px-4 py-2 font-medium">Email</th>
                    <th className="px-4 py-2 font-medium">Role</th>
                    <th className="px-4 py-2 font-medium">Products</th>
                    <th className="px-4 py-2 font-medium">Expires</th>
                    <th className="px-4 py-2" />
                  </tr>
                </thead>
                <tbody>
                  {invites.map((invite) => (
                    <tr key={invite.id} className="border-b border-border last:border-0">
                      <td className="px-4 py-3 text-foreground">{invite.email}</td>
                      <td className="px-4 py-3 text-muted capitalize">{invite.role}</td>
                      <td className="px-4 py-3 text-muted">
                        {invite.products.length
                          ? invite.products.map((p) => (p.role ? `${p.name} (${roleName(seats, p)})` : p.name)).join(", ")
                          : "No product access"}
                      </td>
                      <td className="px-4 py-3 text-muted">{new Date(invite.expires_at).toLocaleDateString()}</td>
                      <td className="px-4 py-3 text-right">
                        <button onClick={() => revokeInvite(invite.id)} className="text-xs text-danger hover:underline">
                          Revoke
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          </div>
        )}
      </main>
    </div>
  );
}

export default function MembersPage() {
  return (
    <RequireAuth>
      <MembersContent />
    </RequireAuth>
  );
}
