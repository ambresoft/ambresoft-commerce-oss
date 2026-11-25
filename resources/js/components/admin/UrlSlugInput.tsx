import type { InputHTMLAttributes } from 'react';

export default function UrlSlugInput({
  prefix = '/',
  ...input
}: InputHTMLAttributes<HTMLInputElement> & { prefix?: string }) {
  return (
    <div className="flex min-w-0">
      <span className="flex shrink-0 items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-3 text-slate-500">
        {prefix}
      </span>
      <input {...input} className="field min-w-0 rounded-l-none" />
    </div>
  );
}
