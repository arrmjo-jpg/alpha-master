import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';

export default function NotFoundPage() {
  const { t } = useTranslation();
  return (
    <section className="max-w-xl space-y-4 py-10">
      <p aria-hidden="true" className="font-latin text-6xl font-extrabold leading-none text-muted-foreground/40">404</p>
      <h1 className="text-3xl font-bold">{t('errors.notFoundTitle')}</h1>
      <p className="text-base leading-7 text-muted-foreground">{t('errors.notFoundBody')}</p>
      <Link to="/" className="inline-block font-bold text-link underline underline-offset-4">
        {t('errors.backHome')}
      </Link>
    </section>
  );
}
