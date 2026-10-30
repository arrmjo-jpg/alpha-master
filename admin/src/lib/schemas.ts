import { z } from 'zod';

/*
 * Response shapes of the endpoints this app uses, read from the API Resources.
 * ADR-0010 plans to generate these from openapi.json once Scramble is set up;
 * until then they stay thin and lenient (unknown keys pass through).
 */

const iso = z.string();

export const meSchema = z.looseObject({
  id: z.number(),
  name: z.string(),
  username: z.string(),
  email: z.string(),
  email_verified_at: iso.nullable(),
  account_type: z.string(),
  preferred_locale: z.string().nullable(),
  roles: z.array(z.looseObject({ name: z.string(), display_name: z.string() })),
  permissions: z.array(z.string()),
});
export type Me = z.infer<typeof meSchema>;
export const meResponse = z.object({ data: meSchema });

export const roleSchema = z.looseObject({
  id: z.number(),
  name: z.string(),
  display_name: z.string(),
  description: z.string().nullable(),
  is_protected: z.boolean(),
  permissions: z.array(z.string()).optional(),
  users_count: z.number().optional(),
  created_at: iso.nullable(),
  updated_at: iso.nullable(),
});
export type Role = z.infer<typeof roleSchema>;
export const rolesResponse = z.object({ data: z.array(roleSchema) });
export const roleResponse = z.object({ data: roleSchema });

export const permissionSchema = z.looseObject({
  name: z.string(),
  display_name: z.string(),
  description: z.string().nullable(),
  is_sensitive: z.boolean(),
  is_deprecated: z.boolean(),
});
export type Permission = z.infer<typeof permissionSchema>;

export const permissionGroupSchema = z.looseObject({
  key: z.string(),
  name: z.string(),
  description: z.string().nullable(),
  sort_order: z.number(),
  permissions: z.array(permissionSchema).default([]),
});
export type PermissionGroup = z.infer<typeof permissionGroupSchema>;
export const permissionGroupsResponse = z.object({ data: z.array(permissionGroupSchema) });

export const auditLogSchema = z.looseObject({
  id: z.number(),
  occurred_at: iso,
  event: z.string(),
  outcome: z.string(),
  actor: z.looseObject({ type: z.string().nullable(), id: z.number().nullable() }),
  subject: z.looseObject({ type: z.string(), id: z.number().nullable() }).nullable(),
  // An object normally; PHP renders an empty array as [] so stay lenient.
  context: z.unknown(),
  ip: z.string().nullable(),
  user_agent: z.string().nullable(),
  request_id: z.string().nullable(),
});
export type AuditLog = z.infer<typeof auditLogSchema>;

/** Laravel cursor pagination (AuditLogController uses cursorPaginate). */
export const auditLogPageResponse = z.object({
  data: z.array(auditLogSchema),
  meta: z.looseObject({ next_cursor: z.string().nullable(), prev_cursor: z.string().nullable(), per_page: z.number() }),
});
export type AuditLogPage = z.infer<typeof auditLogPageResponse>;
