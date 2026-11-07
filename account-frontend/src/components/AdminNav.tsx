"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth-context";
import { ThemeToggle } from "./ThemeToggle";

export function AdminNav() {
  const { user, logout } = useAuth();
  const pathname = usePathname();

  const links = [
    { href: "/admin", label: "Overview" },
    { href: "/admin/users", label: "Users" },
    { href: "/admin/orgs", label: "Organizations" },
    { href: "/admin/products", label: "Products" },
  ];

  if (!user) return null;

  return (
    <nav className="border-b border-border bg-surface">
      <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
        <div className="flex items-center gap-6">
          <Link href="/admin" className="flex items-center gap-2 text-sm font-semibold text-foreground">
            <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-accent text-xs font-semibold text-white">
              RE
            </span>
            RE-Account
            <span className="rounded-full border border-border px-2 py-0.5 text-xs font-normal text-muted">
              Admin
            </span>
          </Link>

          <div className="flex gap-4 text-sm">
            {links.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className={
                  pathname === link.href ? "font-medium text-foreground" : "text-muted hover:text-foreground"
                }
              >
                {link.label}
              </Link>
            ))}
          </div>
        </div>

        <div className="flex items-center gap-4 text-sm">
          <ThemeToggle />
          <span className="text-muted">{user.name}</span>
          <button onClick={() => logout()} className="text-muted hover:text-foreground">
            Log out
          </button>
        </div>
      </div>
    </nav>
  );
}
