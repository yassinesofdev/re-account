"use client";

import { useRouter } from "next/navigation";
import { use, useEffect, useState } from "react";
import { AuthShell } from "@/components/AuthShell";
import { RequireAuth } from "@/components/RouteGuard";
import { Banner, Button } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { setActiveContext } from "@/lib/context-storage";
import type { Organization, ProductRef } from "@/lib/types";

type InvitePreview = {
  email: string;
  role: string;
  organization: Organization;
  products: ProductRef[];
  is_expired: boolean;
  is_accepted: boolean;
};

function InviteContent({ token }: { token: string }) {
  const router = useRouter();
  const { refreshOrganizations } = useAuth();

  const [invite, setInvite] = useState<InvitePreview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [accepting, setAccepting] = useState(false);

  useEffect(() => {
    api
      .get<{ invite: InvitePreview }>(`/invites/${token}`)
      .then((res) => setInvite(res.invite))
      .catch((err) => setError(err instanceof ApiError ? err.message : "This invitation link is invalid."));
  }, [token]);

  async function onAccept() {
    setAccepting(true);
    setError(null);

    try {
      await api.post(`/invites/${token}/accept`);
      await refreshOrganizations();
      if (invite) {
        setActiveContext({ type: "org", orgId: invite.organization.id, slug: invite.organization.slug, name: invite.organization.name });
      }
      router.push(`/orgs/${invite?.organization.slug}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not accept this invitation.");
      setAccepting(false);
    }
  }

  if (error) {
    return (
      <AuthShell title="Invitation">
        <Banner>{error}</Banner>
      </AuthShell>
    );
  }

  if (!invite) {
    return (
      <AuthShell title="Invitation">
        <p className="text-center text-sm text-muted">Loading invitation…</p>
      </AuthShell>
    );
  }

  if (invite.is_accepted) {
    return (
      <AuthShell title="Invitation already used">
        <Banner>This invitation has already been accepted.</Banner>
      </AuthShell>
    );
  }

  if (invite.is_expired) {
    return (
      <AuthShell title="Invitation expired">
        <Banner>This invitation has expired. Ask an admin to send a new one.</Banner>
      </AuthShell>
    );
  }

  return (
    <AuthShell title="You've been invited">
      <p className="mb-6 text-center text-sm text-foreground">
        Join <span className="font-semibold">{invite.organization.name}</span> as a{" "}
        <span className="font-semibold capitalize">{invite.role}</span>.
      </p>
      {invite.products.length > 0 && (
        <p className="-mt-4 mb-6 text-center text-sm text-muted">
          You&apos;ll get access to {invite.products.map((p) => p.name).join(", ")}.
        </p>
      )}
      <Button className="w-full" loading={accepting} onClick={onAccept}>
        Accept invitation
      </Button>
    </AuthShell>
  );
}

export default function InvitePage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);

  return (
    <RequireAuth>
      <InviteContent token={token} />
    </RequireAuth>
  );
}
