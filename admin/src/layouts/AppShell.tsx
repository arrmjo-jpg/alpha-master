import * as React from 'react';
import { NavLink, Outlet, useLocation } from 'react-router';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { KeyRound, LayoutGrid, LogOut, Menu, ScrollText, ShieldCheck, X, type LucideIcon } from 'lucide-react';
import { useAuth } from '@/auth/auth';
import { BrandMark } from '@/components/Brand';
import { PrefsControls } from '@/components/PrefsControls';
import { Button } from '@/components/ui';

interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  permission?: string;
  end?: boolean;
}

/**
 * Signed-in layout: the brand field becomes the navigation rail on the inline-start
 * edge, content sits on the neutral background. Below lg the rail is a drawer.
 */
export function AppShell() {
  const { t } = useTranslation();
  const { user, can, logout } = useAuth();
  const location = useLocation();
  const [open, setOpen] = React.useState(false);
  const menuButton = React.useRef<HTMLButtonElement>(null);

  // Close the drawer on navigation, and with Escape.
  React.useEffect(() => setOpen(false), [location.pathname]);
  React.useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false);
        menuButton.current?.focus();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  const allSections: { label?: string; items: NavItem[] }[] = [
    { items: [{ to: '/', label: t('nav.overview'), icon: LayoutGrid, end: true }] },
    {
      label: t('nav.access'),
      items: [
        { to: '/roles', label: t('nav.roles'), icon: ShieldCheck, permission: 'roles.view' },
        { to: '/permissions', label: t('nav.permissions'), icon: KeyRound, permission: 'permissions.view' },
        { to: '/audit', label: t('nav.audit'), icon: ScrollText, permission: 'audit.view' },
      ],
    },
  ];
  const sections = allSections
    // Hiding links is a convenience; the API refuses what the account may not do.
    .map((s) => ({ ...s, items: s.items.filter((i) => !i.permission || can(i.permission)) }))
    .filter((s) => s.items.length > 0);

  const nav = (
    <nav aria-label={t('nav.label')} className="flex-1 space-y-6 overflow-y-auto px-3 py-6">
      {sections.map((section, i) => (
        <div key={i} className="space-y-1">
          {section.label ? (
            <p className="px-3 pb-1 text-xs font-bold text-white">{section.label}</p>
          ) : null}
          {section.items.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                clsx(
                  'flex h-11 items-center gap-3 px-3 text-[0.9375rem] font-medium transition-colors focus-visible:outline-white',
                  isActive ? 'bg-white text-[hsl(202_52%_30%)]' : 'text-white hover:bg-white/10',
                )
              }
            >
              <item.icon aria-hidden="true" className="h-[1.125rem] w-[1.125rem] shrink-0" strokeWidth={1.75} />
              {item.label}
            </NavLink>
          ))}
        </div>
      ))}
    </nav>
  );

  const account = user ? (
    <div className="border-t border-white/20 px-6 py-5 text-white">
      <p className="text-xs text-white">{t('account.signedInAs')}</p>
      <p className="mt-1 truncate font-bold">{user.name}</p>
      <p dir="ltr" className="truncate font-latin text-sm text-white rtl:text-right">
        {user.username}
      </p>
      <button
        type="button"
        onClick={() => logout.mutate()}
        disabled={logout.isPending}
        className="mt-4 inline-flex h-10 w-full items-center justify-center gap-2 border border-white/40 text-sm font-bold text-white transition-[transform,background-color,border-color] duration-150 ease-out hover:border-white hover:bg-white/10 focus-visible:outline-white active:scale-[0.97] disabled:opacity-70 motion-reduce:active:scale-100"
      >
        <LogOut aria-hidden="true" className="h-4 w-4 rtl:-scale-x-100" />
        {logout.isPending ? t('account.loggingOut') : t('account.logout')}
      </button>
    </div>
  ) : null;

  return (
    <div className="min-h-dvh lg:grid lg:grid-cols-[16.5rem_1fr]">
      <a
        href="#main"
        className="sr-only z-50 bg-card px-4 py-2 font-bold text-foreground focus:not-sr-only focus:fixed focus:start-4 focus:top-4"
      >
        {t('nav.skip')}
      </a>

      {/* Desktop rail */}
      <aside className="sticky top-0 hidden h-dvh flex-col bg-brand-field lg:flex">
        <div className="flex h-16 items-center px-6">
          <BrandMark inverse />
        </div>
        {nav}
        {account}
      </aside>

      {/* Mobile drawer */}
      {open ? (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div aria-hidden="true" className="absolute inset-0 bg-black/40" onClick={() => setOpen(false)} />
          <aside
            role="dialog"
            aria-modal="true"
            aria-label={t('nav.label')}
            className="absolute inset-y-0 start-0 flex w-[min(18rem,85vw)] animate-rise-in flex-col bg-brand-field"
          >
            <div className="flex h-16 items-center justify-between px-6">
              <BrandMark inverse />
              <button
                type="button"
                autoFocus
                onClick={() => {
                  setOpen(false);
                  menuButton.current?.focus();
                }}
                aria-label={t('nav.closeMenu')}
                className="-me-2 inline-flex h-10 w-10 items-center justify-center text-white hover:bg-white/10 focus-visible:outline-white"
              >
                <X aria-hidden="true" className="h-5 w-5" />
              </button>
            </div>
            {nav}
            {account}
          </aside>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-col">
        <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-border bg-background/95 px-4 backdrop-blur sm:px-8">
          <div className="flex items-center gap-3">
            <Button
              ref={menuButton}
              variant="ghost"
              className="-ms-2 w-10 px-0 lg:hidden"
              aria-label={t('nav.openMenu')}
              aria-expanded={open}
              onClick={() => setOpen(true)}
            >
              <Menu aria-hidden="true" className="h-5 w-5" />
            </Button>
            <BrandMark className="lg:hidden" />
          </div>
          <PrefsControls />
        </header>

        <main id="main" tabIndex={-1} className="mx-auto w-full max-w-6xl flex-1 px-4 py-8 outline-none sm:px-8 lg:py-10">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
