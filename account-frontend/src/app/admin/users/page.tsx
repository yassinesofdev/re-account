"use client";

import { useEffect, useState } from "react";
import { Button, Card } from "@/components/ui";
import { api } from "@/lib/api";
import type { Paginated, User } from "@/lib/types";

export default function AdminUsersPage() {
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<Paginated<User> | null>(null);

  useEffect(() => {
    api.get<Paginated<User>>(`/admin/users?page=${page}`).then(setResult);
  }, [page]);

  return (
    <div>
      <h1 className="mb-6 text-xl font-semibold text-foreground">Users</h1>

      <Card className="p-0 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="border-b border-border text-left text-muted">
            <tr>
              <th className="px-4 py-2 font-medium">Name</th>
              <th className="px-4 py-2 font-medium">Email</th>
              <th className="px-4 py-2 font-medium">Verified</th>
              <th className="px-4 py-2 font-medium">Joined</th>
            </tr>
          </thead>
          <tbody>
            {result?.data.map((user) => (
              <tr key={user.id} className="border-b border-border last:border-0">
                <td className="px-4 py-3 text-foreground">
                  {user.name}
                  {user.is_super_admin && (
                    <span className="ml-2 rounded-full border border-accent/40 px-2 py-0.5 text-xs text-accent">
                      admin
                    </span>
                  )}
                </td>
                <td className="px-4 py-3 text-muted">{user.email}</td>
                <td className="px-4 py-3 text-muted">{user.email_verified_at ? "Yes" : "No"}</td>
                <td className="px-4 py-3 text-muted">{new Date(user.created_at).toLocaleDateString()}</td>
              </tr>
            ))}
            {result && result.data.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-muted">
                  No users yet.
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
