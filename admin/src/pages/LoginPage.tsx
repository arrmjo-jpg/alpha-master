import * as React from 'react';
import { Navigate, useLocation } from 'react-router';
import { useTranslation } from 'react-i18next';
import { NotAdminError, useAuth } from '@/auth/auth';
import { ApiError } from '@/lib/problem';
import { Alert, Button, Field, PasswordInput, inputClass, useErrorMessage } from '@/components/ui';
import { AuthShell } from '@/layouts/AuthShell';

type FieldName = 'identifier' | 'password';

export default function LoginPage() {
  const { t } = useTranslation();
  const location = useLocation();
  const { user, login, sessionExpired } = useAuth();
  const describe = useErrorMessage();
  const [values, setValues] = React.useState({ identifier: '', password: '' });
  const [clientErrors, setClientErrors] = React.useState<Partial<Record<FieldName, string>>>({});
  const alertRef = React.useRef<HTMLDivElement>(null);

  const from = (location.state as { from?: string } | null)?.from ?? '/';

  React.useEffect(() => {
    if (login.isError) alertRef.current?.focus();
  }, [login.isError, login.error]);

  if (user?.account_type === 'admin') return <Navigate to={from} replace />;

  const serverFieldErrors = login.error instanceof ApiError ? login.error.fieldErrors : {};
  const errorFor = (name: FieldName) => clientErrors[name] ?? serverFieldErrors[name] ?? null;

  let formError: { message: string; reference?: string } | null = null;
  if (login.error instanceof NotAdminError) formError = { message: t('login.notAdmin') };
  else if (login.error && !(login.error instanceof ApiError && login.error.status === 422)) formError = describe(login.error);

  const onSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const errors: Partial<Record<FieldName, string>> = {};
    if (!values.identifier.trim()) errors.identifier = t('login.required');
    if (!values.password) errors.password = t('login.required');
    setClientErrors(errors);
    const first = (Object.keys(errors) as FieldName[])[0];
    if (first) {
      event.currentTarget.querySelector<HTMLInputElement>(`[name="${first}"]`)?.focus();
      return;
    }
    login.mutate({ identifier: values.identifier.trim(), password: values.password });
  };

  const bind = (name: FieldName) => ({
    name,
    id: name,
    value: values[name],
    onChange: (e: React.ChangeEvent<HTMLInputElement>) => {
      setValues((v) => ({ ...v, [name]: e.target.value }));
      if (clientErrors[name]) setClientErrors((c) => ({ ...c, [name]: undefined }));
    },
    'aria-invalid': Boolean(errorFor(name)) || undefined,
  });

  return (
    <AuthShell>
      <div className="space-y-8">
        <header className="space-y-3">
          <h1 className="text-balance text-[2rem] font-bold leading-tight sm:text-4xl">{t('login.title')}</h1>
          <p className="text-base leading-7 text-muted-foreground">{t('login.subtitle')}</p>
        </header>

        <form noValidate onSubmit={onSubmit} className="space-y-6">
          {sessionExpired && !login.isError ? <Alert tone="info">{t('login.sessionExpired')}</Alert> : null}
          {formError ? (
            <div ref={alertRef} tabIndex={-1} className="outline-none">
              <Alert>
                <p>{formError.message}</p>
              </Alert>
            </div>
          ) : null}

          <Field id="identifier" label={t('login.identifier')} error={errorFor('identifier')}>
            {(describedBy) => (
              <input
                {...bind('identifier')}
                type="text"
                dir="ltr"
                autoComplete="username"
                autoCapitalize="none"
                spellCheck={false}
                autoFocus
                aria-describedby={describedBy}
                className={`${inputClass} font-latin rtl:text-right`}
              />
            )}
          </Field>

          <Field id="password" label={t('login.password')} error={errorFor('password')}>
            {(describedBy) => (
              <PasswordInput {...bind('password')} autoComplete="current-password" aria-describedby={describedBy} />
            )}
          </Field>

          <Button type="submit" size="lg" busy={login.isPending} className="w-full">
            {login.isPending ? t('login.submitting') : t('login.submit')}
          </Button>

          <p className="text-sm leading-6 text-muted-foreground">{t('login.noReset')}</p>
        </form>
      </div>
    </AuthShell>
  );
}
