import { Moon, Sun } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { usePreferences } from '@/app/preferences';

/** Language and theme switches, shared by the sign-in screen and the app bar. */
export function PrefsControls({ className, tone = 'default' }: { className?: string; tone?: 'default' | 'inverse' }) {
  const { t } = useTranslation();
  const { lang, setLang, theme, toggleTheme } = usePreferences();
  const next = lang === 'ar' ? 'en' : 'ar';
  const control = clsx(
    'inline-flex h-10 items-center justify-center border text-sm font-bold transition-[transform,background-color,border-color] duration-150 ease-out active:scale-[0.97] motion-reduce:active:scale-100',
    tone === 'inverse'
      ? 'border-white/40 text-white hover:border-white hover:bg-white/10 focus-visible:outline-white'
      : 'border-border text-foreground hover:border-foreground/40 hover:bg-muted',
  );

  return (
    <div className={clsx('flex items-center gap-2', className)}>
      <button
        type="button"
        lang={next}
        onClick={() => setLang(next)}
        aria-label={t('prefs.switchLanguage')}
        className={clsx(control, 'px-3', next === 'en' ? 'font-latin' : 'font-sans')}
      >
        {t('prefs.languageName')}
      </button>
      <button
        type="button"
        onClick={toggleTheme}
        aria-label={theme === 'dark' ? t('prefs.lightMode') : t('prefs.darkMode')}
        className={clsx(control, 'w-10')}
      >
        {theme === 'dark' ? <Sun aria-hidden="true" className="h-4 w-4" /> : <Moon aria-hidden="true" className="h-4 w-4" />}
      </button>
    </div>
  );
}
