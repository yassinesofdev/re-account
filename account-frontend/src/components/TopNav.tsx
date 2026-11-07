"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useAuth } from "@/lib/auth-context";
import { getActiveContext, setActiveContext } from "@/lib/context-storage";
import { ThemeToggle } from "./ThemeToggle";

export function TopNav() {
  const { user, organizations, logout } = useAuth();
  const router = useRouter();
  const [contextId, setContextId] = useState("");

  useEffect(() => {
    const active = getActiveContext();
    // An org the user left or deleted shows as Personal.
    setContextId(active.type === "org" && organizations.some((o) => o.id === active.orgId) ? active.orgId : "");
  }, [organizations]);

  function onContextChange(id: string) {
    setContextId(id);
    const org = organizations.find((o) => o.id === id);

    if (org) {
      setActiveContext({ type: "org", orgId: org.id, slug: org.slug, name: org.name });
      router.push(`/orgs/${org.slug}`);
    } else {
      setActiveContext({ type: "personal" });
      router.push("/dashboard");
    }
  }

  if (!user) return null;

  return (
    <nav className="border-b border-border bg-surface">
      <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
        <div className="flex items-center gap-6">
          <Link href="/dashboard" className="flex items-center gap-2 text-sm font-semibold text-foreground">
            <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-accent text-xs font-semibold text-white">
              RE
            </span>
            RE-Account
          </Link>

          <select
            value={contextId}
            onChange={(e) => onContextChange(e.target.value)}
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-foreground focus:border-accent focus:outline-none"
          >
            <option value="">Personal</option>
            {organizations.map((org) => (
              <option key={org.id} value={org.id}>
                {org.name}
              </option>
            ))}
          </select>
        </div>

        <div className="flex items-center gap-4 text-sm">
          <ThemeToggle />
          <Link href="/profile" className="text-muted hover:text-foreground">
            {user.name}
          </Link>
          <button onClick={() => logout()} className="text-muted hover:text-foreground">
            Log out
          </button>
        </div>
      </div>
    </nav>
  );
}
