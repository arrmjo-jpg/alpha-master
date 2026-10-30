import * as React from 'react';
import { useQueryClient } from '@tanstack/react-query';
import i18n, { initialLang, type Lang } from '@/i18n';
import { setApiLanguage } from '@/lib/api';

const LANG_KEY = 'alphamaster.admin.lang';
const THEME_KEY = 'alphamaster.admin.theme';

type Theme = 'light' | 'dark';

interface Preferences {
  lang: Lang;
  dir: 'rtl' | 'ltr';
  setLang: (lang: Lang) => void;
  theme: Theme;
  toggleTheme: () => void;
}

const PreferencesContext = React.createContext<Preferences | null>(null);

function store(key: string, value: string) {
  try {
    localStorage.setItem(key, value);
  } catch {
    // Blocked storage only means the choice is not remembered.
  }
}

setApiLanguage(initialLang);

export function PreferencesProvider({ children }: { children: React.ReactNode }) {
  const queryClient = useQueryClient();
  const [lang, setLangState] = React.useState<Lang>(initialLang);
  const [theme, setTheme] = React.useState<Theme>(() =>
    document.documentElement.classList.contains('dark') ? 'dark' : 'light',
  );

  const setLang = React.useCallback(
    (next: Lang) => {
      setLangState(next);
      store(LANG_KEY, next);
      setApiLanguage(next);
      const root = document.documentElement;
      root.lang = next;
      root.dir = next === 'ar' ? 'rtl' : 'ltr';
      void i18n.changeLanguage(next);
      // Role and permission names are translated by the server (Accept-Language).
      void queryClient.invalidateQueries();
    },
    [queryClient],
  );

  const toggleTheme = React.useCallback(() => {
    setTheme((current) => {
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.classList.toggle('dark', next === 'dark');
      store(THEME_KEY, next);
      return next;
    });
  }, []);

  const value = React.useMemo<Preferences>(
    () => ({ lang, dir: lang === 'ar' ? 'rtl' : 'ltr', setLang, theme, toggleTheme }),
    [lang, setLang, theme, toggleTheme],
  );

  return <PreferencesContext.Provider value={value}>{children}</PreferencesContext.Provider>;
}

export function usePreferences(): Preferences {
  const value = React.useContext(PreferencesContext);
  if (!value) throw new Error('usePreferences must be used inside PreferencesProvider');
  return value;
}
