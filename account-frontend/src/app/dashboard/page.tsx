"use client";

import Link from "next/link";
import { RequireAuth } from "@/components/RouteGuard";
import { TopNav } from "@/components/TopNav";
import { Card, LinkButton } from "@/components/ui";
import { useAuth } from "@/lib/auth-context";
import { setActiveContext } from "@/lib/context-storage";

function DashboardContent() {
  const { user, organizations } = useAuth();

  return (
    <div className="min-h-screen">
      <TopNav />
      <main className="mx-auto max-w-5xl px-4 py-10">
        <div className="mb-8 flex items-center justify-between">
          <div>
            <p className="text-sm text-muted">Signed in as</p>
            <h1 className="text-2xl font-semibold text-foreground">{user?.name}</h1>
          </div>
          <LinkButton href="/orgs/new" variant="primary">
            New organization
          </LinkButton>
        </div>

        <section className="mb-6">
          <h2 className="mb-3 text-sm font-medium text-muted">Personal</h2>
          <Card
            className="cursor-pointer transition-colors hover:bg-surface-hover"
            onClick={() => setActiveContext({ type: "personal" })}
          >
            <p className="font-medium text-foreground">{user?.name}</p>
            <p className="text-sm text-muted">{user?.email}</p>
          </Card>
        </section>

        <section>
          <h2 className="mb-3 text-sm font-medium text-muted">Organizations</h2>
          {organizations.length === 0 ? (
            <Card className="text-center text-sm text-muted">
              You&apos;re not part of any organization yet.
            </Card>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2">
              {organizations.map((org) => (
                <Link
                  key={org.id}
                  href={`/orgs/${org.slug}`}
                  onClick={() => setActiveContext({ type: "org", orgId: org.id, slug: org.slug, name: org.name })}
                >
                  <Card className="flex items-center gap-3 transition-colors hover:bg-surface-hover">
                    {org.logo_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={org.logo_url} alt="" className="h-10 w-10 rounded-lg object-cover" />
                    ) : (
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/20 text-sm font-semibold text-accent">
                        {org.name.slice(0, 2).toUpperCase()}
                      </div>
                    )}
                    <div className="min-w-0">
                      <p className="truncate font-medium text-foreground">{org.name}</p>
                      <p className="text-sm text-muted">
                        {org.member_count ?? 1} member{(org.member_count ?? 1) === 1 ? "" : "s"}
                      </p>
                    </div>
                  </Card>
                </Link>
              ))}
            </div>
          )}
        </section>
      </main>
    </div>
  );
}

export default function DashboardPage() {
  return (
    <RequireAuth>
      <DashboardContent />
    </RequireAuth>
  );
}
