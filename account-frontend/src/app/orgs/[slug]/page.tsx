"use client";

import { notFound as nextNotFound, useParams } from "next/navigation";
import { OrgTabs } from "@/components/OrgTabs";
import { RequireAuth } from "@/components/RouteGuard";
import { TopNav } from "@/components/TopNav";
import { Card } from "@/components/ui";
import { orgHost } from "@/lib/tenant";
import { useOrg } from "@/lib/useOrg";

function OrgOverviewContent() {
  const { slug } = useParams<{ slug: string }>();
  const { organization, myRole, loading, notFound } = useOrg(slug, "");

  if (notFound) nextNotFound();
  if (loading || !organization) return null;

  return (
    <div className="min-h-screen">
      <TopNav />
      <main className="mx-auto max-w-3xl px-4 py-10">
        <OrgTabs slug={slug} />

        <Card className="flex items-center gap-4">
          {organization.logo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={organization.logo_url} alt="" className="h-16 w-16 rounded-xl object-cover" />
          ) : (
            <div className="flex h-16 w-16 items-center justify-center rounded-xl bg-accent/20 text-lg font-semibold text-accent">
              {organization.name.slice(0, 2).toUpperCase()}
            </div>
          )}
          <div>
            <h1 className="text-xl font-semibold text-foreground">{organization.name}</h1>
            <p className="text-sm text-muted">
              {organization.member_count} member{organization.member_count === 1 ? "" : "s"} · {orgHost(organization.slug)}
            </p>
            {myRole && <p className="mt-1 text-xs uppercase tracking-wide text-muted">Your role: {myRole}</p>}
          </div>
        </Card>
      </main>
    </div>
  );
}

export default function OrgOverviewPage() {
  return (
    <RequireAuth>
      <OrgOverviewContent />
    </RequireAuth>
  );
}
