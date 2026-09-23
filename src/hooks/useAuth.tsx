import { createContext, useCallback, useContext, useEffect, useState, ReactNode } from "react";
import { apiFetch, getStoredToken, setStoredToken, notifyAuthChanged, AUTH_STORAGE_KEY } from "@/lib/api";

export interface AppUser {
  id: string;
  email: string;
  user_metadata: {
    full_name?: string | null;
  };
  roles?: string[];
  /** Empresa da qual o utilizador é dono; null para admin da plataforma sem empresa. */
  company_id?: string | null;
}

export interface AuthSession {
  access_token: string;
  user: AppUser;
}

interface AuthContextType {
  session: AuthSession | null;
  user: AppUser | null;
  loading: boolean;
  signOut: () => Promise<void>;
  /** Grava token + utilizador na sessão (evita corrida com /auth/me ao ir para o dashboard). */
  establishSession: (token: string, user: AppUser) => void;
}

const AuthContext = createContext<AuthContextType>({
  session: null,
  user: null,
  loading: true,
  signOut: async () => {},
  establishSession: () => {},
});

export const AuthProvider = ({ children }: { children: ReactNode }) => {
  const [session, setSession] = useState<AuthSession | null>(null);
  const [loading, setLoading] = useState(true);

  const establishSession = useCallback((token: string, user: AppUser) => {
    setStoredToken(token);
    setSession({ access_token: token, user });
    setLoading(false);
  }, []);

  const loadSession = useCallback(async () => {
    const token = getStoredToken();
    if (!token) {
      setSession(null);
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      const { user } = await apiFetch<{ user: AppUser }>("/api/auth/me");
      setSession({ access_token: token, user });
    } catch {
      setStoredToken(null);
      setSession(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadSession();
  }, [loadSession]);

  useEffect(() => {
    const onChange = () => void loadSession();
    window.addEventListener("cobx-auth-changed", onChange);
    const onStorage = (e: StorageEvent) => {
      if (e.key === AUTH_STORAGE_KEY) void loadSession();
    };
    window.addEventListener("storage", onStorage);
    return () => {
      window.removeEventListener("cobx-auth-changed", onChange);
      window.removeEventListener("storage", onStorage);
    };
  }, [loadSession]);

  const signOut = async () => {
    setStoredToken(null);
    setSession(null);
    notifyAuthChanged();
  };

  return (
    <AuthContext.Provider value={{ session, user: session?.user ?? null, loading, signOut, establishSession }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => useContext(AuthContext);
