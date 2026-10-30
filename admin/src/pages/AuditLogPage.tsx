import * as React from 'react';
import { useSearchParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ChevronDown } from 'lucide-react';
import clsx from 'clsx';
import { usePreferences } from '@/app/preferences';
import { Button, Code, ErrorState, Loading, PageHeader, Tag, inputClass } from '@/components/ui';
import { formatDateTime } from '@/lib/format';
import type { AuditLog } from '@/lib/schemas';
import ar from '@/i18n/ar';
import { useAuditLogs, type AuditFilters } from './queries';

const OUTCOMES = ['success', 'failed', 'denied'] as const;
const KNOWN_EVENTS = Object.keys(ar.events);
const FILTER_KEYS = ['event', 'outcome', 'from', 'to'] as const;

export default function AuditLogPage() {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();

  const filters: AuditFilters = {
    event: params.get('event') ?? undefined,
    outcome: params.get('outcome') ?? undefined,
    from: params.get('from') ?? undefined,
    // A date filter means the whole day.
    to: params.get('to') ? `${params.get('to')}T23:59:59` : undefined,
    cursor: params.get('cursor') ?? undefined,
    per_page: 25,
  };
  const logs = useAuditLogs(filters);

  const onSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const next = new URLSearchParams();
    for (const key of FILTER_KEYS) {
      const value = String(form.get(key) ?? '').trim();
      if (value) next.set(key, value);
    }
    setParams(next);
  };

  const goTo = (cursor: string | null) => {
    const next = new URLSearchParams(params);
    if (cursor) next.set('cursor', cursor);
    else next.delete('cursor');
    setParams(next);
    document.getElementById('main')?.focus();
  };

  const hasFilters = FILTER_KEYS.some((k) => params.get(k));

  return (
    <div className="space-y-8">
      <PageHeader title={t('audit.title')} subtitle={t('audit.subtitle')} />

      <form
        key={params.toString()}
        onSubmit={onSubmit}
        aria-label={t('audit.filters')}
        className="grid gap-4 border border-border bg-card p-5 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end"
      >
        <div className="space-y-2">
          <label htmlFor="f-event" className="block text-sm font-bold">{t('audit.event')}</label>
          <input
            id="f-event"
            name="event"
            list="known-events"
            dir="ltr"
            defaultValue={params.get('event') ?? ''}
            placeholder={t('audit.eventPlaceholder')}
            autoComplete="off"
            spellCheck={false}
            className={`${inputClass} h-10 font-latin text-sm rtl:text-right`}
          />
          <datalist id="known-events">
            {KNOWN_EVENTS.map((e) => (
              <option key={e} value={e} label={t(`events.${e}`, { defaultValue: e })} />
            ))}
          </datalist>
        </div>
        <div className="space-y-2">
          <label htmlFor="f-outcome" className="block text-sm font-bold">{t('audit.outcome')}</label>
          <select id="f-outcome" name="outcome" defaultValue={params.get('outcome') ?? ''} className={`${inputClass} h-10 text-sm`}>
            <option value="">{t('audit.anyOutcome')}</option>
            {OUTCOMES.map((o) => (
              <option key={o} value={o}>{t(`audit.outcomes.${o}`)}</option>
            ))}
          </select>
        </div>
        <div className="space-y-2">
          <label htmlFor="f-from" className="block text-sm font-bold">{t('audit.from')}</label>
          <input id="f-from" name="from" type="date" defaultValue={params.get('from') ?? ''} className={`${inputClass} h-10 text-sm`} />
        </div>
        <div className="space-y-2">
          <label htmlFor="f-to" className="block text-sm font-bold">{t('audit.to')}</label>
          <input id="f-to" name="to" type="date" defaultValue={params.get('to') ?? ''} className={`${inputClass} h-10 text-sm`} />
        </div>
        <div className="flex gap-2 sm:col-span-2 lg:col-span-1">
          <Button type="submit" className="flex-1 lg:flex-none">{t('audit.apply')}</Button>
          {hasFilters ? (
            <Button variant="outline" onClick={() => setParams(new URLSearchParams())}>
              {t('audit.reset')}
            </Button>
          ) : null}
        </div>
      </form>

      {logs.isPending ? (
        <Loading />
      ) : logs.isError ? (
        <ErrorState error={logs.error} onRetry={() => void logs.refetch()} />
      ) : (
        <div className={clsx('space-y-6 transition-opacity', logs.isPlaceholderData && 'opacity-60')} aria-busy={logs.isFetching || undefined}>
          <AuditList entries={logs.data.data} />
          {logs.data.meta.prev_cursor || logs.data.meta.next_cursor ? (
            <nav aria-label={t('audit.title')} className="flex justify-between gap-3">
              <Button variant="outline" disabled={!logs.data.meta.prev_cursor} onClick={() => goTo(logs.data.meta.prev_cursor)}>
                {t('audit.newer')}
              </Button>
              <Button variant="outline" disabled={!logs.data.meta.next_cursor} onClick={() => goTo(logs.data.meta.next_cursor)}>
                {t('audit.older')}
              </Button>
            </nav>
          ) : null}
        </div>
      )}
    </div>
  );
}

const outcomeTone = { success: 'success', failed: 'danger', denied: 'warning' } as const;

/** Audit entries as a flat list; each row expands to its recorded context. */
export function AuditList({ entries, compact }: { entries: AuditLog[]; compact?: boolean }) {
  const { t } = useTranslation();
  const { lang } = usePreferences();

  if (entries.length === 0) return <p className="text-muted-foreground">{t('audit.empty')}</p>;

  const who = (actor: AuditLog['actor']) => {
    if (actor.type === 'anonymous' || !actor.type) return t('audit.anonymous');
    if (actor.type === 'system') return t('audit.system');
    return `${actor.type}#${actor.id ?? '?'}`;
  };

  return (
    <ul className="divide-y divide-border border border-border bg-card">
      {entries.map((entry) => {
        const tone = outcomeTone[entry.outcome as keyof typeof outcomeTone] ?? 'neutral';
        const hasContext = entry.context && typeof entry.context === 'object' && Object.keys(entry.context).length > 0;
        const summary = (
          <div className="grid w-full gap-x-6 gap-y-1 sm:grid-cols-[minmax(0,1fr)_auto]">
            <div className="min-w-0">
              <p className="flex flex-wrap items-center gap-2">
                <span className="font-bold">{t(`events.${entry.event}`, { defaultValue: entry.event })}</span>
                <Tag tone={tone}>{t(`audit.outcomes.${entry.outcome}`, { defaultValue: entry.outcome })}</Tag>
              </p>
              <p className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                <Code>{entry.event}</Code>
                <span>
                  {t('audit.actor')}: <span dir="ltr" className="font-latin">{who(entry.actor)}</span>
                </span>
                {entry.subject ? (
                  <span>
                    {t('audit.subject')}: <span dir="ltr" className="font-latin">{`${entry.subject.type}#${entry.subject.id ?? '?'}`}</span>
                  </span>
                ) : null}
              </p>
            </div>
            <time dateTime={entry.occurred_at} className="text-sm text-muted-foreground sm:text-end">
              {formatDateTime(entry.occurred_at, lang, true)}
            </time>
          </div>
        );

        if (compact) {
          return (
            <li key={entry.id} className="px-5 py-4">
              {summary}
            </li>
          );
        }

        return (
          <li key={entry.id}>
            <details className="group">
              <summary className="flex cursor-pointer list-none items-start gap-3 px-5 py-4 transition-colors hover:bg-muted/50 focus-visible:outline-offset-[-2px] [&::-webkit-details-marker]:hidden">
                <ChevronDown aria-hidden="true" className="mt-1 h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-150 group-open:rotate-180" />
                {summary}
              </summary>
              <dl className="grid gap-x-8 gap-y-3 border-t border-border bg-muted/40 px-5 py-4 text-sm sm:grid-cols-[auto_minmax(0,1fr)] sm:ps-12">
                <dt className="text-muted-foreground">{t('audit.ip')}</dt>
                <dd dir="ltr" className="font-latin rtl:text-right">{entry.ip ?? '—'}</dd>
                <dt className="text-muted-foreground">{t('audit.request')}</dt>
                <dd dir="ltr" className="break-all font-latin rtl:text-right">{entry.request_id ?? '—'}</dd>
                <dt className="text-muted-foreground">User-Agent</dt>
                <dd dir="ltr" className="break-all font-latin text-muted-foreground rtl:text-right">{entry.user_agent ?? '—'}</dd>
                {hasContext ? (
                  <>
                    <dt className="text-muted-foreground">{t('audit.details')}</dt>
                    <dd>
                      <pre dir="ltr" className="overflow-x-auto whitespace-pre-wrap break-all border border-border bg-card p-3 text-start font-latin text-xs leading-5">
                        {JSON.stringify(entry.context, null, 2)}
                      </pre>
                    </dd>
                  </>
                ) : null}
              </dl>
            </details>
          </li>
        );
      })}
    </ul>
  );
}
