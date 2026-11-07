"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { clearOrgContext } from "@/lib/context-storage";
import type { Organization } from "@/lib/types";

/** POST /orgs/{slug}/leave for admins and members (the owner can't leave; they can only delete the organization). */
export function LeaveOrgButton({
  organization,
  onError,
}: {
  organization: Organization;
  onError: (message: string) => void;
}) {
  const router = useRouter();
  const { refreshOrganizations } = useAuth();
  const [leaving, setLeaving] = useState(false);

  async function leave() {
    if (!confirm(`Leave "${organization.name}"? You lose access to its products until someone invites you again.`)) return;
    setLeaving(true);
    try {
      await api.post(`/orgs/${organization.slug}/leave`);
      clearOrgContext(organization.id);
      await refreshOrganizations();
      router.push("/dashboard");
    } catch (err) {
      onError(err instanceof ApiError ? err.message : "Could not leave the organization.");
      setLeaving(false);
    }
  }

  return (
    <Button variant="danger" onClick={leave} loading={leaving}>
      Leave organization
    </Button>
  );
}
