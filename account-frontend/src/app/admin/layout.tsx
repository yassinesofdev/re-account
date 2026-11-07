"use client";

import type { ReactNode } from "react";
import { AdminNav } from "@/components/AdminNav";
import { RequireSuperAdmin } from "@/components/RouteGuard";

export default function AdminLayout({ children }: { children: ReactNode }) {
  return (
    <RequireSuperAdmin>
      <div className="min-h-screen">
        <AdminNav />
        <main className="mx-auto max-w-5xl px-4 py-10">{children}</main>
      </div>
    </RequireSuperAdmin>
  );
}
