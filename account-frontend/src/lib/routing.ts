import type { User } from "./types";

export function homeRouteFor(user: Pick<User, "is_super_admin"> | null): string {
  return user?.is_super_admin ? "/admin" : "/dashboard";
}
