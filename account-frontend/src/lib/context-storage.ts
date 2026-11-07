const KEY = "reaccount:context";

export type ActiveContext = { type: "personal" } | { type: "org"; orgId: string; slug: string; name: string };

export function getActiveContext(): ActiveContext {
  if (typeof window === "undefined") return { type: "personal" };

  try {
    const raw = window.localStorage.getItem(KEY);
    if (!raw) return { type: "personal" };
    const parsed = JSON.parse(raw) as ActiveContext;
    return parsed.type === "org" ? parsed : { type: "personal" };
  } catch {
    return { type: "personal" };
  }
}

export function setActiveContext(context: ActiveContext): void {
  if (typeof window === "undefined") return;
  window.localStorage.setItem(KEY, JSON.stringify(context));
}

/** After leaving or deleting an org: drop it as the active context. */
export function clearOrgContext(orgId: string): void {
  const active = getActiveContext();
  if (active.type === "org" && active.orgId === orgId) setActiveContext({ type: "personal" });
}
