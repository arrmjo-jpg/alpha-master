import * as React from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie, setUnauthenticatedHandler } from '@/lib/api';
import { ApiError } from '@/lib/problem';
import { meResponse, type Me } from '@/lib/schemas';

export const ME_KEY = ['auth', 'me'] as const;

/** Raised on the client when a non-admin account signs in; the server would refuse it anyway. */
export class NotAdminError extends Error {
  constructor() {
    super('NOT_ADMIN');
    this.name = 'NotAdminError';
  }
}

interface AuthState {
  /** undefined while the session is being checked, null when signed out. */
  user: Me | null | undefined;
  sessionExpired: boolean;
  /** Set when the session check itself failed (network or server), not when signed out. */
  sessionError: Error | null;
  retrySession: () => void;
  can: (permission: string) => boolean;
  login: ReturnType<typeof useLoginMutation>;
  logout: ReturnType<typeof useLogoutMutation>;
}

const AuthContext = React.createContext<AuthState | null>(null);

async function fetchMe(signal?: AbortSignal): Promise<Me | null> {
  try {
    return (await api('/auth/me', { schema: meResponse, signal, skipAuthRedirect: true })).data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null;
    throw error;
  }
}

function useLoginMutation(onSignedIn: () => void) {
  const queryClient = useQueryClient();
  return useMutation<Me, Error, { identifier: string; password: string }>({
    mutationFn: async (credentials) => {
      // Laravel rotates the session on login, so start from a fresh CSRF cookie.
      await ensureCsrfCookie();
      const { data: user } = await api('/auth/login', {
        method: 'POST',
        body: credentials,
        schema: meResponse,
        skipAuthRedirect: true,
      });
      if (user.account_type !== 'admin') {
        // Close the session the API just opened; admin routes would reject it regardless.
        await api('/auth/logout', { method: 'POST', skipAuthRedirect: true }).catch(() => undefined);
        throw new NotAdminError();
      }
      return user;
    },
    onSuccess: (user) => {
      queryClient.setQueryData(ME_KEY, user);
      onSignedIn();
    },
  });
}

function useLogoutMutation() {
  const queryClient = useQueryClient();
  return useMutation<void, Error, void>({
    mutationFn: async () => {
      await api('/auth/logout', { method: 'POST', skipAuthRedirect: true }).catch((error: unknown) => {
        // Already signed out on the server is still signed out.
        if (!(error instanceof ApiError && error.status === 401)) throw error;
      });
    },
    onSuccess: () => {
      queryClient.clear();
      queryClient.setQueryData(ME_KEY, null);
    },
  });
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const queryClient = useQueryClient();
  const [sessionExpired, setSessionExpired] = React.useState(false);

  const me = useQuery({
    queryKey: ME_KEY,
    queryFn: ({ signal }) => fetchMe(signal),
    staleTime: 5 * 60_000,
    retry: false,
  });

  React.useEffect(() => {
    setUnauthenticatedHandler(() => {
      if (queryClient.getQueryData(ME_KEY)) setSessionExpired(true);
      queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== ME_KEY[0] });
      queryClient.setQueryData(ME_KEY, null);
    });
    return () => setUnauthenticatedHandler(null);
  }, [queryClient]);

  const login = useLoginMutation(() => setSessionExpired(false));
  const logout = useLogoutMutation();

  const user = me.isPending ? undefined : (me.data ?? null);
  const permissions = React.useMemo(() => new Set(user?.permissions ?? []), [user]);

  const value = React.useMemo<AuthState>(
    () => ({
      user,
      sessionExpired,
      sessionError: me.error,
      retrySession: () => void me.refetch(),
      // For showing or hiding UI only: the API checks every permission itself (ADR-0004).
      can: (permission) => permissions.has(permission),
      login,
      logout,
    }),
    [user, sessionExpired, me, permissions, login, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const value = React.useContext(AuthContext);
  if (!value) throw new Error('useAuth must be used inside AuthProvider');
  return value;
}
