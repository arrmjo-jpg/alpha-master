import type { Lang } from '@/i18n';

/** Dates in the interface language; Arabic uses Latin digits to match identifiers and logs. */
export function formatDateTime(iso: string | null | undefined, lang: Lang, withSeconds = false): string {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  return new Intl.DateTimeFormat(lang === 'ar' ? 'ar-u-nu-latn' : 'en-GB', {
    dateStyle: 'medium',
    timeStyle: withSeconds ? 'medium' : 'short',
  }).format(date);
}

export function formatNumber(value: number, lang: Lang): string {
  return new Intl.NumberFormat(lang === 'ar' ? 'ar-u-nu-latn' : 'en').format(value);
}
