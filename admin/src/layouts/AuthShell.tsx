import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { PrefsControls } from '@/components/PrefsControls';
import { BrandMark } from '@/components/Brand';

/**
 * Approved direction "panel docked on the brand color": a flat sheet docked to the
 * inline-start edge of a full-viewport brand field. Below lg the sheet goes full width
 * under a brand band, so the brand stays visible on phones.
 */
export function AuthShell({ children }: { children: React.ReactNode }) {
  const { t } = useTranslation();
  const year = new Date().getFullYear();

  return (
    <div className="flex min-h-dvh flex-col bg-brand-field lg:grid lg:grid-cols-[minmax(0,30rem)_1fr]">
      <div className="px-6 pb-8 pt-10 text-white sm:px-10 lg:hidden">
        <Wordmark className="text-5xl sm:text-6xl md:text-7xl" />
        <p className="mt-4 max-w-md text-sm leading-6 text-white md:text-base">{t('brand.adminOnly')}</p>
      </div>

      <section className="flex flex-1 flex-col bg-card px-6 pb-6 pt-5 sm:px-10 lg:min-h-dvh lg:px-12 lg:pb-8 lg:pt-8">
        <header className="flex items-center justify-end gap-4 lg:justify-between">
          <BrandMark className="max-lg:sr-only" />
          <PrefsControls />
        </header>

        <main id="main" className="flex w-full max-w-[26rem] flex-1 flex-col justify-center py-10 lg:py-12">
          <div className="animate-rise-in">{children}</div>
        </main>

        <footer className="border-t border-border pt-4 text-xs text-muted-foreground">
          © {year} <span className="font-latin">AlphaMaster</span>
        </footer>
      </section>

      <aside className="relative hidden overflow-hidden px-12 pb-10 pt-12 text-white lg:flex lg:flex-col lg:justify-between">
        <div className="max-w-xl space-y-5">
          <p className="text-pretty text-xl leading-9 xl:text-2xl xl:leading-10">{t('brand.purpose')}</p>
          <div className="h-px w-16 bg-white/40" />
          <p className="text-base leading-7 text-white">{t('brand.adminOnly')}</p>
        </div>
        <Wordmark className="self-end text-[clamp(5.5rem,10vw,10.5rem)]" />
      </aside>
    </div>
  );
}

/** Monumental, decorative product name; the readable name is BrandMark. */
function Wordmark({ className }: { className?: string }) {
  return (
    <span
      aria-hidden="true"
      dir="ltr"
      className={`block select-none font-latin font-extrabold leading-[0.82] tracking-[-0.045em] text-white ${className ?? ''}`}
    >
      AlphaMaster
    </span>
  );
}
