import clsx from 'clsx';

/** The readable product name with its flat square mark. */
export function BrandMark({ className, inverse }: { className?: string; inverse?: boolean }) {
  return (
    <div dir="ltr" className={clsx('flex items-center gap-2.5 font-latin text-base font-bold', inverse ? 'text-white' : 'text-foreground', className)}>
      <span aria-hidden="true" className={clsx('block h-6 w-6', inverse ? 'bg-white' : 'bg-primary')} />
      AlphaMaster
    </div>
  );
}
