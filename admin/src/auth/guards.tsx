import * as React from 'react';
import { Navigate, useLocation } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ShieldOff } from 'lucide-react';
import { Link } from 'react-router';
import { useAuth } from './auth';
import { ErrorState, Loading } from '@/components/ui';

/** Sends anyone without an administrator session to /login, remembering where they were going. */
export function RequireAdmin({ children }: { children: React.ReactNode }) {
  const { user, sessionError, retrySession } = useAuth();
  const location = useLocation();

  if (user === undefined) {
    return (
      <div className="mx-auto max-w-md px-6 py-24">
        <Loading />
      </div>
    );
  }
  if (sessionError) {
    return (
      <div className="mx-auto max-w-md px-6 py-24">
        <ErrorState error={sessionError} onRetry={retrySession} />
      </div>
    );
  }
  if (!user || user.account_type !== 'admin') {
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  }
  return <>{children}</>;
}

/** Shows a clear refusal instead of a page the API would answer with 403. */
export function RequirePermission({ permission, children }: { permission: string; children: React.ReactNode }) {
  const { can } = useAuth();
  return can(permission) ? <>{children}</> : <Forbidden />;
}

export function Forbidden() {
  const { t } = useTranslation();
  return (
    <section className="max-w-xl space-y-4 py-10">
      <ShieldOff aria-hidden="true" className="h-8 w-8 text-warning" strokeWidth={1.75} />
      <h1 className="text-3xl font-bold">{t('errors.forbiddenTitle')}</h1>
      <p className="text-base leading-7 text-muted-foreground">{t('errors.forbiddenBody')}</p>
      <Link to="/" className="inline-block font-bold text-link underline underline-offset-4">
        {t('errors.backHome')}
      </Link>
    </section>
  );
}
