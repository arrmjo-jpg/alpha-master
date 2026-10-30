import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { auditLogPageResponse, permissionGroupsResponse, roleResponse, rolesResponse } from '@/lib/schemas';

export interface AuditFilters {
  event?: string;
  outcome?: string;
  from?: string;
  to?: string;
  cursor?: string;
  per_page?: number;
}

export function useRoles(enabled = true) {
  return useQuery({
    queryKey: ['access', 'roles'],
    queryFn: ({ signal }) => api('/admin/access/roles', { schema: rolesResponse, signal }).then((r) => r.data),
    enabled,
  });
}

export function useRole(id: string) {
  return useQuery({
    queryKey: ['access', 'roles', id],
    queryFn: ({ signal }) => api(`/admin/access/roles/${encodeURIComponent(id)}`, { schema: roleResponse, signal }).then((r) => r.data),
  });
}

export function usePermissionGroups(enabled = true) {
  return useQuery({
    queryKey: ['access', 'permissions'],
    queryFn: ({ signal }) => api('/admin/access/permissions', { schema: permissionGroupsResponse, signal }).then((r) => r.data),
    enabled,
    staleTime: 5 * 60_000,
  });
}

export function useAuditLogs(filters: AuditFilters, enabled = true) {
  return useQuery({
    queryKey: ['access', 'audit', filters],
    queryFn: ({ signal }) => api('/admin/access/audit-logs', { schema: auditLogPageResponse, query: { ...filters }, signal }),
    enabled,
    placeholderData: keepPreviousData,
  });
}
