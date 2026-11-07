"use client";

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { api, ApiError } from "./api";
import type { Organization, User } from "./types";

type AuthState = {
  user: User | null;
  organizations: Organization[];
  loading: boolean;
  refresh: () => Promise<User | null>;
  refreshOrganizations: () => Promise<void>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const { user } = await api.get<{ user: User }>("/auth/me", { skipAuthRedirect: true });
      setUser(user);
      return user;
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        setUser(null);
        return null;
      }
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const refreshOrganizations = useCallback(async () => {
    const { organizations } = await api.get<{ organizations: Organization[] }>("/orgs", {
      skipAuthRedirect: true,
    });
    setOrganizations(organizations);
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  useEffect(() => {
    if (user) refreshOrganizations();
    else setOrganizations([]);
  }, [user, refreshOrganizations]);

  const logout = useCallback(async () => {
    await api.post("/auth/logout");
    setUser(null);
    setOrganizations([]);
    window.location.href = "/login";
  }, []);

  return (
    <AuthContext.Provider value={{ user, organizations, loading, refresh, refreshOrganizations, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
