import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { Search } from 'lucide-react';
import { Code, ErrorState, Loading, PageHeader, Tag, inputClass } from '@/components/ui';
import { usePermissionGroups } from './queries';

export default function PermissionsPage() {
  const { t } = useTranslation();
  const groups = usePermissionGroups();
  const [query, setQuery] = React.useState('');
  const needle = query.trim().toLowerCase();

  const filtered = groups.data
    ?.map((g) => ({
      ...g,
      permissions: needle
        ? g.permissions.filter((p) => p.name.toLowerCase().includes(needle) || p.display_name.toLowerCase().includes(needle))
        : g.permissions,
    }))
    .filter((g) => g.permissions.length > 0);

  return (
    <div className="space-y-8">
      <PageHeader
        title={t('permissions.title')}
        subtitle={t('permissions.subtitle')}
        actions={
          <div className="w-full sm:w-72">
            <label htmlFor="permission-search" className="sr-only">
              {t('permissions.search')}
            </label>
            <div className="relative">
              <Search aria-hidden="true" className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <input
                id="permission-search"
                type="search"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={t('permissions.searchPlaceholder')}
                className={`${inputClass} h-10 ps-9 text-sm`}
              />
            </div>
          </div>
        }
      />

      {groups.isPending ? (
        <Loading />
      ) : groups.isError ? (
        <ErrorState error={groups.error} onRetry={() => void groups.refetch()} />
      ) : !filtered?.length ? (
        <p role="status" className="text-muted-foreground">{t('permissions.none')}</p>
      ) : (
        <div className="space-y-8">
          {filtered.map((group) => (
            <section key={group.key} aria-labelledby={`group-${group.key}`} className="border border-border bg-card">
              <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                  <h2 id={`group-${group.key}`} className="text-lg font-bold">{group.name}</h2>
                  {group.description ? <p className="mt-1 text-sm text-muted-foreground">{group.description}</p> : null}
                </div>
                <span className="text-sm text-muted-foreground">{t('permissions.count', { count: group.permissions.length })}</span>
              </header>
              <ul className="divide-y divide-border">
                {group.permissions.map((p) => (
                  <li key={p.name} className="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                    <div className="min-w-0 space-y-1">
                      <p className="font-bold">{p.display_name}</p>
                      <Code>{p.name}</Code>
                      {p.description ? <p className="text-sm leading-6 text-muted-foreground">{p.description}</p> : null}
                    </div>
                    <div className="flex gap-2">
                      {p.is_sensitive ? <Tag tone="warning" title={t('permissions.sensitiveHint')}>{t('permissions.sensitive')}</Tag> : null}
                      {p.is_deprecated ? <Tag>{t('permissions.deprecated')}</Tag> : null}
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}
