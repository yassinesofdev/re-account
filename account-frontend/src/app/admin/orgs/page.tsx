"use client";

import { useEffect, useState } from "react";
import { Button, Card } from "@/components/ui";
import { api } from "@/lib/api";
import type { Organization, Paginated } from "@/lib/types";

export default function AdminOrgsPage() {
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<Paginated<Organization> | null>(null);

  useEffect(() => {
    api.get<Paginated<Organization>>(`/admin/orgs?page=${page}`).then(setResult);
  }, [page]);

  return (
    <div>
      <h1 className="mb-6 text-xl font-semibold text-foreground">Organizations</h1>

      <Card className="p-0 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="border-b border-border text-left text-muted">
            <tr>
              <th className="px-4 py-2 font-medium">Name</th>
              <th className="px-4 py-2 font-medium">Slug</th>
              <th className="px-4 py-2 font-medium">Members</th>
              <th className="px-4 py-2 font-medium">Created</th>
            </tr>
          </thead>
          <tbody>
            {result?.data.map((org) => (
              <tr key={org.id} className="border-b border-border last:border-0">
                <td className="px-4 py-3 text-foreground">{org.name}</td>
                <td className="px-4 py-3 text-muted">/{org.slug}</td>
                <td className="px-4 py-3 text-muted">{org.member_count ?? "—"}</td>
                <td className="px-4 py-3 text-muted">{new Date(org.created_at).toLocaleDateString()}</td>
              </tr>
            ))}
            {result && result.data.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-muted">
                  No organizations yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </Card>

      {result && result.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm">
          <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </Button>
          <span className="text-muted">
            Page {result.current_page} of {result.last_page} · {result.total} total
          </span>
          <Button variant="secondary" disabled={page >= result.last_page} onClick={() => setPage((p) => p + 1)}>
            Next
          </Button>
        </div>
      )}
    </div>
  );
}
