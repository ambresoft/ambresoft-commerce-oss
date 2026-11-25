import { useEffect, useId, useRef, useState } from 'react';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';
import type { StickerOption } from '../../../types/stickers';
import TargetPicker from '../Promotions/TargetPicker';

/** Keep the rule readable; open the existing server-search picker only when choosing its target. */
export default function ConditionPicker(props: {
  label: string;
  kind: string;
  values: string[];
  options: StickerOption[];
  path: string;
  query: Record<string, string>;
  onChange: (ids: string[]) => void;
}) {
  const [open, setOpen] = useState(false);
  const chosen = props.options.find((option) => option.id === props.values[0]);
  return (
    <>
      <button
        type="button"
        className="button-secondary max-w-full gap-2 text-left"
        onClick={() => setOpen(true)}
      >
        <Icon name={props.values.length ? 'edit' : 'plus'} />
        <span className="truncate">
          {chosen?.name ??
            (props.values[0] ? `#${props.values[0]}` : props.label)}
        </span>
      </button>
      {open && <Picker {...props} close={() => setOpen(false)} />}
    </>
  );
}

function Picker({
  close,
  ...props
}: Parameters<typeof ConditionPicker>[0] & { close: () => void }) {
  const t = useTranslation();
  const ref = useRef<HTMLDialogElement>(null);
  const id = useId();
  useEffect(() => {
    ref.current?.showModal();
  }, []);
  return (
    <dialog
      ref={ref}
      aria-labelledby={id}
      className="m-auto max-h-[85vh] w-[min(36rem,95vw)] overflow-y-auto rounded-xl border border-slate-200 bg-white p-5 text-slate-900 backdrop:bg-black/50"
      onCancel={(event) => {
        event.preventDefault();
        close();
      }}
      onClick={(event) => {
        if (event.target === event.currentTarget) close();
      }}
    >
      <div className="mb-5 flex items-center justify-between gap-3">
        <h2 id={id} className="text-lg font-semibold">
          {props.label}
        </h2>
        <IconButton icon="x" label={t('common.close')} onClick={close} />
      </div>
      <TargetPicker
        {...props}
        single
        onChange={(ids) => {
          props.onChange(ids);
          close();
        }}
      />
    </dialog>
  );
}
