import * as React from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createBrowserRouter, RouterProvider } from 'react-router';
import '@fontsource/tajawal/400.css';
import '@fontsource/tajawal/500.css';
import '@fontsource/tajawal/700.css';
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/700.css';
import '@fontsource/inter/800.css';
import './styles.css';
import './i18n';
import { ApiError } from '@/lib/problem';
import { PreferencesProvider } from '@/app/preferences';
import { AuthProvider } from '@/auth/auth';
import { RequireAdmin, RequirePermission } from '@/auth/guards';
import { AppShell } from '@/layouts/AppShell';
import LoginPage from '@/pages/LoginPage';
import OverviewPage from '@/pages/OverviewPage';
import RolesPage from '@/pages/RolesPage';
import RoleDetailPage from '@/pages/RoleDetailPage';
import PermissionsPage from '@/pages/PermissionsPage';
import AuditLogPage from '@/pages/AuditLogPage';
import NotFoundPage from '@/pages/NotFoundPage';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      // Retry only transient failures; 4xx answers will not change on retry.
      retry: (count, error) => count < 2 && (!(error instanceof ApiError) || error.status === 0 || error.status >= 500),
    },
  },
});

const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  {
    element: (
      <RequireAdmin>
        <AppShell />
      </RequireAdmin>
    ),
    children: [
      { index: true, element: <OverviewPage /> },
      { path: 'roles', element: <RequirePermission permission="roles.view"><RolesPage /></RequirePermission> },
      { path: 'roles/:roleId', element: <RequirePermission permission="roles.view"><RoleDetailPage /></RequirePermission> },
      { path: 'permissions', element: <RequirePermission permission="permissions.view"><PermissionsPage /></RequirePermission> },
      { path: 'audit', element: <RequirePermission permission="audit.view"><AuditLogPage /></RequirePermission> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
]);

createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <PreferencesProvider>
        <AuthProvider>
          <RouterProvider router={router} />
        </AuthProvider>
      </PreferencesProvider>
    </QueryClientProvider>
  </React.StrictMode>,
);
