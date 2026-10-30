import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import ar from './ar';
import en from './en';

export type Lang = 'ar' | 'en';

/** The language boot.js already applied to <html>, so the first render matches it. */
export const initialLang: Lang = document.documentElement.lang === 'en' ? 'en' : 'ar';

void i18n.use(initReactI18next).init({
  resources: { ar: { translation: ar }, en: { translation: en } },
  lng: initialLang,
  fallbackLng: 'ar',
  interpolation: { escapeValue: false },
  returnNull: false,
});

export default i18n;
