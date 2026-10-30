import * as React from 'react';
import clsx from 'clsx';
import { AlertCircle, Eye, EyeOff, Loader2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/lib/problem';

/* Flat primitives: 1px borders, no radius, no shadows. Press feedback scales to 0.97 (150ms). */

const press = 'transition-[transform,background-color,border-color,color] duration-150 ease-out active:scale-[0.97] motion-reduce:active:scale-100';

type ButtonVariant = 'primary' | 'outline' | 'ghost';

export const Button = React.forwardRef<
  HTMLButtonElement,
  React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; busy?: boolean; size?: 'md' | 'lg' }
>(({ variant = 'primary', busy, size = 'md', className, disabled, children, type = 'button', ...props }, ref) => (
  <button
    ref={ref}
    type={type}
    disabled={disabled || busy}
    aria-busy={busy || undefined}
    className={clsx(
      'inline-flex items-center justify-center gap-2 whitespace-nowrap font-bold',
      'disabled:cursor-not-allowed disabled:opacity-60 aria-busy:opacity-100',
      press,
      size === 'lg' ? 'h-12 px-6 text-base' : 'h-10 px-4 text-sm',
      variant === 'primary' && 'bg-primary text-primary-foreground hover:bg-primary/90',
      variant === 'outline' && 'border border-input bg-card text-foreground hover:border-foreground/40 hover:bg-muted',
      variant === 'ghost' && 'text-foreground hover:bg-muted',
      className,
    )}
    {...props}
  >
    {busy ? <Loader2 aria-hidden="true" className="h-4 w-4 animate-spin" /> : null}
    {children}
  </button>
));
Button.displayName = 'Button';

export const inputClass =
  'block h-12 w-full border border-input bg-card px-3 text-base text-foreground placeholder:text-muted-foreground/80 transition-colors hover:border-foreground/40 focus-visible:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 aria-[invalid=true]:border-destructive';

export function Field({
  id,
  label,
  error,
  hint,
  children,
}: {
  id: string;
  label: string;
  error?: string | null;
  hint?: string;
  children: (describedBy: string | undefined) => React.ReactNode;
}) {
  const errorId = error ? `${id}-error` : undefined;
  const hintId = hint && !error ? `${id}-hint` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;
  return (
    <div className="space-y-2">
      <label htmlFor={id} className="block text-sm font-bold text-foreground">
        {label}
      </label>
      {children(describedBy)}
      {hintId ? (
        <p id={hintId} className="text-sm text-muted-foreground">
          {hint}
        </p>
      ) : null}
      {errorId ? (
        <p id={errorId} className="flex items-center gap-1.5 text-sm font-medium text-destructive">
          <AlertCircle aria-hidden="true" className="h-4 w-4 shrink-0" />
          {error}
        </p>
      ) : null}
    </div>
  );
}

export const PasswordInput = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
  ({ className, id, ...props }, ref) => {
    const { t } = useTranslation();
    const [visible, setVisible] = React.useState(false);
    return (
      <div className="relative">
        <input
          ref={ref}
          id={id}
          type={visible ? 'text' : 'password'}
          className={clsx(inputClass, 'pe-12', className)}
          {...props}
        />
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          aria-pressed={visible}
          aria-controls={id}
          aria-label={visible ? t('login.hidePassword') : t('login.showPassword')}
          className="absolute inset-y-0 end-0 flex w-12 items-center justify-center text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
        >
          {visible ? <EyeOff aria-hidden="true" className="h-5 w-5" /> : <Eye aria-hidden="true" className="h-5 w-5" />}
        </button>
      </div>
    );
  },
);
PasswordInput.displayName = 'PasswordInput';

export function Alert({ children, tone = 'danger' }: { children: React.ReactNode; tone?: 'danger' | 'info' }) {
  return (
    <div
      role={tone === 'danger' ? 'alert' : 'status'}
      className={clsx(
        'flex items-start gap-3 border px-4 py-3 text-sm leading-6 text-foreground',
        tone === 'danger' ? 'border-destructive/50 bg-destructive/[0.06]' : 'border-primary/40 bg-primary/[0.06]',
      )}
    >
      <AlertCircle
        aria-hidden="true"
        className={clsx('mt-1 h-4 w-4 shrink-0', tone === 'danger' ? 'text-destructive' : 'text-link')}
      />
      <div className="min-w-0">{children}</div>
    </div>
  );
}

/** Turns any thrown value into the message an administrator should read. */
export function useErrorMessage() {
  const { t } = useTranslation();
  return React.useCallback(
    (error: unknown): { message: string; reference?: string } => {
      if (error instanceof ApiError) {
        if (error.status === 0) return { message: t('errors.network') };
        if (error.status === 429) {
          return { message: t('errors.rateLimited', { seconds: error.retryAfter ?? 60 }) };
        }
        // The API localizes title and detail through Accept-Language.
        return { message: error.message || t('errors.generic'), reference: error.traceId };
      }
      return { message: t('errors.generic') };
    },
    [t],
  );
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const { t } = useTranslation();
  const describe = useErrorMessage();
  const { message, reference } = describe(error);
  return (
    <Alert>
      <p>{message}</p>
      {reference ? (
        <p className="mt-1 text-muted-foreground">
          {t('errors.reference', { id: '' })}
          <span dir="ltr" className="font-latin text-xs">
            {reference}
          </span>
        </p>
      ) : null}
      {onRetry ? (
        <Button variant="outline" className="mt-3" onClick={onRetry}>
          {t('errors.retry')}
        </Button>
      ) : null}
    </Alert>
  );
}

export function Loading({ label }: { label?: string }) {
  const { t } = useTranslation();
  return (
    <div role="status" className="flex items-center gap-3 py-10 text-sm text-muted-foreground">
      <Loader2 aria-hidden="true" className="h-5 w-5 animate-spin text-link" />
      {label ?? t('common.loading')}
    </div>
  );
}

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: React.ReactNode }) {
  return (
    <header className="flex flex-col gap-4 border-b border-border pb-6 sm:flex-row sm:items-end sm:justify-between">
      <div className="space-y-2">
        <h1 className="text-balance text-3xl font-bold leading-tight text-foreground">{title}</h1>
        {subtitle ? <p className="max-w-2xl text-pretty text-base leading-7 text-muted-foreground">{subtitle}</p> : null}
      </div>
      {actions}
    </header>
  );
}

export function Tag({ children, tone = 'neutral', title }: { children: React.ReactNode; tone?: 'neutral' | 'primary' | 'warning' | 'danger' | 'success'; title?: string }) {
  return (
    <span
      title={title}
      className={clsx(
        'inline-flex h-6 items-center whitespace-nowrap border px-2 text-xs font-bold',
        tone === 'neutral' && 'border-border text-muted-foreground',
        tone === 'primary' && 'border-primary/40 text-link',
        tone === 'warning' && 'border-warning/50 text-warning',
        tone === 'danger' && 'border-destructive/50 text-destructive',
        tone === 'success' && 'border-success/50 text-success',
      )}
    >
      {children}
    </span>
  );
}

/** Stable identifiers (permission keys, event names) are Latin and read left-to-right. */
export function Code({ children, className }: { children: React.ReactNode; className?: string }) {
  return (
    <code dir="ltr" className={clsx('font-latin text-[0.8125rem] text-muted-foreground', className)}>
      {children}
    </code>
  );
}
