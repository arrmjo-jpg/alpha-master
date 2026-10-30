import { Link, useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ArrowLeft, ArrowRight, Lock } from 'lucide-react';
import { usePreferences } from '@/app/preferences';
import { useAuth } from '@/auth/auth';
import { Code, ErrorState, Loading, PageHeader, Tag } from '@/components/ui';
import { formatDateTime, formatNumber } from '@/lib/format';
import { ApiError } from '@/lib/problem';
import { usePermissionGroups, useRole } from './queries';
import NotFoundPage from './NotFoundPage';

export default function RoleDetailPage() {
  const { roleId = '' } = useParams();
  const { t } = useTranslation();
  const { lang, dir } = usePreferences();
  const { can } = useAuth();
  const role = useRole(roleId);
  // The catalog gives each permission its translated name and group.
  const groups = usePermissionGroups(can('permissions.view'));
  const Back = dir === 'rtl' ? ArrowRight : ArrowLeft;

  if (role.isError && role.error instanceof ApiError && role.error.status === 404) return <NotFoundPage />;

  const granted = new Set(role.data?.permissions ?? []);
  const grouped = groups.data
    ?.map((g) => ({ ...g, permissions: g.permissions.filter((p) => granted.has(p.name)) }))
    .filter((g) => g.permissions.length > 0);

  return (
    <div className="space-y-8">
      <Link to="/roles" className="inline-flex items-center gap-2 text-sm font-bold text-link hover:underline hover:underline-offset-4">
        <Back aria-hidden="true" className="h-4 w-4" />
        {t('roles.back')}
      </Link>

      {role.isPending ? (
        <Loading />
      ) : role.isError ? (
        <ErrorState error={role.error} onRetry={() => void role.refetch()} />
      ) : (
        <>
          <PageHeader
            title={role.data.display_name}
            subtitle={role.data.description ?? undefined}
            actions={role.data.is_protected ? (
              <Tag title={t('roles.protectedHint')}>
                <Lock aria-hidden="true" className="me-1 h-3 w-3" />
                {t('roles.protected')}
              </Tag>
            ) : undefined}
          />

          <dl className="grid gap-x-10 gap-y-4 text-sm sm:grid-cols-4">
            <div>
              <dt className="text-muted-foreground">{t('roles.key')}</dt>
              <dd className="mt-1"><Code className="text-foreground">{role.data.name}</Code></dd>
            </div>
            <div>
              <dt className="text-muted-foreground">{t('roles.users')}</dt>
              <dd className="mt-1 font-latin tabular-nums">{role.data.users_count !== undefined ? formatNumber(role.data.users_count, lang) : '—'}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground">{t('roles.created')}</dt>
              <dd className="mt-1">{formatDateTime(role.data.created_at, lang)}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground">{t('roles.updated')}</dt>
              <dd className="mt-1">{formatDateTime(role.data.updated_at, lang)}</dd>
            </div>
          </dl>

          <section aria-labelledby="role-permissions" className="space-y-4">
            <div className="flex flex-wrap items-baseline justify-between gap-3 border-b border-border pb-3">
              <h2 id="role-permissions" className="text-xl font-bold">
                {t('roles.permissionsTitle')}
              </h2>
              <p className="text-sm text-muted-foreground">
                {t('roles.permissionsCount', { count: granted.size })} · {t('roles.readOnlyNote')}
              </p>
            </div>

            {granted.size === 0 ? (
              <p className="text-muted-foreground">{t('roles.noPermissions')}</p>
            ) : grouped ? (
              <div className="grid gap-6 md:grid-cols-2">
                {grouped.map((group) => (
                  <div key={group.key} className="border border-border bg-card">
                    <h3 className="border-b border-border px-5 py-3 font-bold">{group.name}</h3>
                    <ul className="divide-y divide-border">
                      {group.permissions.map((p) => (
                        <li key={p.name} className="flex items-start justify-between gap-3 px-5 py-3">
                          <div className="min-w-0">
                            <p className="font-medium">{p.display_name}</p>
                            <Code>{p.name}</Code>
                          </div>
                          {p.is_sensitive ? <Tag tone="warning" title={t('permissions.sensitiveHint')}>{t('permissions.sensitive')}</Tag> : null}
                        </li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            ) : groups.isPending && can('permissions.view') ? (
              <Loading />
            ) : (
              // Without permissions.view there is no catalog to label them: list the keys.
              <ul className="flex flex-wrap gap-2">
                {[...granted].map((name) => (
                  <li key={name} className="border border-border bg-card px-2 py-1">
                    <Code>{name}</Code>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </div>
  );
}
