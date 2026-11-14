export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://account-api.re.localsite:8338/api";
const API_ROOT = API_URL.replace(/\/api\/?$/, "");

export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]>;
  /** Machine-readable error code (billing errors), e.g. "seats_over_limit". */
  code?: string;
  /** The full error payload, for extra fields like `seats_used`. */
  data: Record<string, unknown>;

  constructor(
    status: number,
    message: string,
    errors: Record<string, string[]> = {},
    code?: string,
    data: Record<string, unknown> = {},
  ) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.code = code;
    this.data = data;
  }

  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

let csrfReady: Promise<void> | null = null;

async function ensureCsrfCookie(): Promise<void> {
  if (!csrfReady) {
    csrfReady = fetch(`${API_ROOT}/sanctum/csrf-cookie`, {
      credentials: "include",
    }).then(() => undefined);
  }
  return csrfReady;
}

type ApiOptions = Omit<RequestInit, "body"> & { body?: unknown; skipAuthRedirect?: boolean };

export async function apiFetch<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();

  if (method !== "GET" && method !== "HEAD") {
    await ensureCsrfCookie();
  }

  const headers: Record<string, string> = {
    Accept: "application/json",
    ...(options.headers as Record<string, string> | undefined),
  };

  let body: BodyInit | undefined;

  if (options.body instanceof FormData) {
    body = options.body;
  } else if (options.body !== undefined) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(options.body);
  }

  if (method !== "GET" && method !== "HEAD") {
    const xsrf = readCookie("XSRF-TOKEN");
    if (xsrf) headers["X-XSRF-TOKEN"] = xsrf;
  }

  const res = await fetch(`${API_URL}${path}`, {
    ...options,
    method,
    body,
    headers,
    credentials: "include",
  });

  if (res.status === 401 && !options.skipAuthRedirect && typeof window !== "undefined") {
    const next = window.location.pathname + window.location.search;
    window.location.href = `/login?next=${encodeURIComponent(next)}`;
    return new Promise<T>(() => {});
  }

  const isJson = res.headers.get("content-type")?.includes("application/json");
  const payload = isJson ? await res.json() : undefined;

  if (!res.ok) {
    throw new ApiError(
      res.status,
      payload?.message ?? res.statusText,
      payload?.errors ?? {},
      typeof payload?.code === "string" ? payload.code : undefined,
      payload && typeof payload === "object" ? payload : {},
    );
  }

  return payload as T;
}

export const api = {
  get: <T>(path: string, options?: ApiOptions) => apiFetch<T>(path, { ...options, method: "GET" }),
  post: <T>(path: string, body?: unknown, options?: ApiOptions) =>
    apiFetch<T>(path, { ...options, method: "POST", body }),
  put: <T>(path: string, body?: unknown, options?: ApiOptions) =>
    apiFetch<T>(path, { ...options, method: "PUT", body }),
  delete: <T>(path: string, options?: ApiOptions) => apiFetch<T>(path, { ...options, method: "DELETE" }),
};
