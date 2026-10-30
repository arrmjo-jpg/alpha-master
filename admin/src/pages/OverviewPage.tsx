import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useAuth } from '@/auth/auth';
import { usePreferences } from '@/app/preferences';
import { ErrorState, Loading, PageHeader, Tag } from '@/components/ui';
import { formatNumber } from '@/lib/format';
import { useAuditLogs, usePermissionGroups, useRoles } from './queries';
import { AuditList } from './AuditLogPage';

export default function OverviewPage() {
  const { t } = useTranslation();
  const { lang, dir } = usePreferences();
  const { user, can } = useAuth();
  const roles = useRoles(can('roles.view'));
  const groups = usePermissionGroups(can('permissions.view'));
  const audit = useAuditLogs({ per_page: 6 }, can('audit.view'));
  const Forward = dir === 'rtl' ? ArrowLeft : ArrowRight;

  if (!user) return null;

  const permissionTotal = groups.data?.reduce((sum, g) => sum + g.permissions.length, 0);

  const stats: { label: string; value: string | null; to?: string; allowed: boolean; pending: boolean }[] = [
    { label: t('overview.roles'), value: roles.data ? formatNumber(roles.data.length, lang) : null, to: '/roles', allowed: can('roles.view'), pending: roles.isPending },
    { label: t('overview.permissions'), value: permissionTotal !== undefined ? formatNumber(permissionTotal, lang) : null, to: '/permissions', allowed: can('permissions.view'), pending: groups.isPending },
    { label: t('overview.yourPermissions'), value: formatNumber(user.permissions.length, lang), allowed: true, pending: false },
  ];

  return (
    <div className="space-y-10">
      <PageHeader title={t('overview.welcome', { name: user.name })} />

      <section aria-labelledby="your-roles" className="space-y-3">
        <h2 id="your-roles" className="text-sm font-bold text-muted-foreground">
          {t('overview.yourRoles')}
        </h2>
        {user.roles.length ? (
          <ul className="flex flex-wrap gap-2">
            {user.roles.map((role) => (
              <li key={role.name}>
                <Tag tone="primary">{role.display_name}</Tag>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">{t('overview.noRoles')}</p>
        )}
      </section>

      <dl className="grid border-s border-t border-border sm:grid-cols-3">
        {stats.map((stat) => (
          <div key={stat.label} className="border-b border-e border-border bg-card p-6">
            <dt className="text-sm font-medium text-muted-foreground">{stat.label}</dt>
            <dd className="mt-3 flex items-end justify-between gap-3">
              <span className="font-latin text-4xl font-bold leading-none tabular-nums text-foreground">
                {!stat.allowed ? '—' : stat.pending ? '…' : (stat.value ?? '—')}
              </span>
              {stat.to && stat.allowed ? (
                <Link
                  to={stat.to}
                  aria-label={stat.label}
                  className="inline-flex h-9 w-9 items-center justify-center border border-border text-foreground transition-colors hover:border-foreground/40 hover:bg-muted"
                >
                  <Forward aria-hidden="true" className="h-4 w-4" />
                </Link>
              ) : null}
            </dd>
          </div>
        ))}
      </dl>

      <section aria-labelledby="recent-activity" className="space-y-4">
        <div className="flex items-baseline justify-between gap-4">
          <h2 id="recent-activity" className="text-xl font-bold">
            {t('overview.recentActivity')}
          </h2>
          {can('audit.view') ? (
            <Link to="/audit" className="text-sm font-bold text-link underline decoration-link/30 underline-offset-4 hover:decoration-link">
              {t('overview.viewAll')}
            </Link>
          ) : null}
        </div>
        {!can('audit.view') ? (
          <p className="text-sm text-muted-foreground">{t('overview.noAccess')}</p>
        ) : audit.isPending ? (
          <Loading />
        ) : audit.isError ? (
          <ErrorState error={audit.error} onRetry={() => void audit.refetch()} />
        ) : (
          <AuditList entries={audit.data.data} compact />
        )}
      </section>
    </div>
  );
}
