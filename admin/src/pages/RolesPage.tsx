import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Lock } from 'lucide-react';
import { usePreferences } from '@/app/preferences';
import { Code, ErrorState, Loading, PageHeader, Tag } from '@/components/ui';
import { formatNumber } from '@/lib/format';
import { useRoles } from './queries';

export default function RolesPage() {
  const { t } = useTranslation();
  const { lang } = usePreferences();
  const roles = useRoles();

  return (
    <div className="space-y-8">
      <PageHeader title={t('roles.title')} subtitle={t('roles.subtitle')} />

      {roles.isPending ? (
        <Loading />
      ) : roles.isError ? (
        <ErrorState error={roles.error} onRetry={() => void roles.refetch()} />
      ) : roles.data.length === 0 ? (
        <p className="text-muted-foreground">{t('roles.empty')}</p>
      ) : (
        <div className="overflow-x-auto border border-border bg-card">
          <table className="w-full min-w-[34rem] text-start text-sm">
            <thead className="border-b border-border bg-muted/60 text-xs font-bold text-muted-foreground">
              <tr>
                <th scope="col" className="px-5 py-3 text-start">{t('roles.name')}</th>
                <th scope="col" className="px-5 py-3 text-start">{t('roles.key')}</th>
                <th scope="col" className="px-5 py-3 text-end">{t('roles.users')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {roles.data.map((role) => (
                <tr key={role.id} className="group transition-colors hover:bg-muted/50">
                  <td className="px-5 py-4">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link
                        to={`/roles/${role.id}`}
                        className="font-bold text-foreground underline decoration-transparent underline-offset-4 transition-colors hover:decoration-link"
                      >
                        {role.display_name}
                      </Link>
                      {role.is_protected ? (
                        <Tag title={t('roles.protectedHint')}>
                          <Lock aria-hidden="true" className="me-1 h-3 w-3" />
                          {t('roles.protected')}
                        </Tag>
                      ) : null}
                    </div>
                    {role.description ? <p className="mt-1 text-muted-foreground">{role.description}</p> : null}
                  </td>
                  <td className="px-5 py-4 align-top">
                    <Code>{role.name}</Code>
                  </td>
                  <td className="px-5 py-4 text-end align-top font-latin tabular-nums">
                    {role.users_count !== undefined ? formatNumber(role.users_count, lang) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
