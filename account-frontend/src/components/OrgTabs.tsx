"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

export function OrgTabs({ slug }: { slug: string }) {
  const pathname = usePathname();

  const tabs = [
    { href: `/orgs/${slug}`, label: "Overview" },
    { href: `/orgs/${slug}/members`, label: "Members" },
    { href: `/orgs/${slug}/settings`, label: "Settings" },
  ];

  return (
    <div className="mb-6 flex gap-1 border-b border-border">
      {tabs.map((tab) => {
        const active = pathname === tab.href;
        return (
          <Link
            key={tab.href}
            href={tab.href}
            className={`border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
              active ? "border-accent text-foreground" : "border-transparent text-muted hover:text-foreground"
            }`}
          >
            {tab.label}
          </Link>
        );
      })}
    </div>
  );
}
