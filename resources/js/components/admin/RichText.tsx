import { EditorContent, useEditor, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { Image } from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import { TextAlign } from '@tiptap/extension-text-align';
import { Color, TextStyle } from '@tiptap/extension-text-style';
import { Youtube } from '@tiptap/extension-youtube';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from '../../lib/useTranslation';
import { isRichTextLink, sanitizeRichText } from '../../lib/sanitizeRichText';
import {
  isPageLink,
  isPageMedia,
  sanitizePageContent,
} from '../../lib/sanitizePageContent';

/**
 * `basic` is the product/category description policy; `page` adds headings 1–4, tables, alignment, colour,
 * horizontal lines, images by address and YouTube embeds for content pages. Each profile has a matching server policy.
 */
export type RichTextProfile = 'basic' | 'page';

function extensionsFor(profile: RichTextProfile) {
  const page = profile === 'page';
  return [
    StarterKit.configure({
      heading: { levels: page ? [1, 2, 3, 4] : [2, 3] },
      horizontalRule: page ? {} : false,
      link: {
        openOnClick: false,
        autolink: false,
        linkOnPaste: false,
        isAllowedUri: page ? isPageLink : isRichTextLink,
        HTMLAttributes: { target: null, rel: null },
      },
    }),
    ...(page
      ? [
          TextAlign.configure({ types: ['heading', 'paragraph'] }),
          TextStyle,
          Color,
          TableKit.configure({ table: { resizable: false } }),
          Image.configure({ inline: false, allowBase64: false }),
          Youtube.configure({ nocookie: true, width: 640, height: 360 }),
        ]
      : []),
  ];
}

export default function RichText({
  id,
  label,
  value,
  onChange,
  disabled = false,
  maxLength = 100000,
  invalid = false,
  describedBy,
  profile = 'basic',
}: {
  id: string;
  label: string;
  value: string;
  onChange: (html: string) => void;
  disabled?: boolean;
  maxLength?: number;
  invalid?: boolean;
  describedBy?: string;
  profile?: RichTextProfile;
}) {
  const t = useTranslation();
  const clean = profile === 'page' ? sanitizePageContent : sanitizeRichText;
  const [mode, setMode] = useState<'visual' | 'html'>('visual');
  const [source, setSource] = useState({
    raw: value,
    clean: value,
    edited: false,
  });
  const lastEmitted = useRef(value);
  const editor = useEditor({
    shouldRerenderOnTransaction: true,
    extensions: extensionsFor(profile),
    content: clean(value),
    editable: !disabled,
    editorProps: {
      attributes: {
        id,
        role: 'textbox',
        'aria-label': label,
        'aria-multiline': 'true',
        'aria-invalid': String(invalid),
        ...(describedBy ? { 'aria-describedby': describedBy } : {}),
        class:
          'min-h-48 p-4 outline-none focus-visible:ring-2 focus-visible:ring-primary [&_a]:underline [&_ul]:list-disc [&_ol]:list-decimal [&_li]:ml-5 [&_h2]:text-xl [&_h3]:text-lg [&_blockquote]:border-l-2 [&_blockquote]:pl-4 [&_pre]:whitespace-pre-wrap' +
          (profile === 'page'
            ? ' rich-page min-h-96 [&_h1]:text-2xl [&_h1]:font-semibold [&_h2]:font-semibold [&_h4]:font-semibold [&_p]:my-2 [&_hr]:my-4 [&_hr]:border-slate-300 [&_img]:max-w-full [&_iframe]:max-w-full [&_table]:my-3 [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:border-slate-300 [&_td]:p-2 [&_th]:border [&_th]:border-slate-300 [&_th]:bg-slate-50 [&_th]:p-2 [&_.selectedCell]:bg-primary-tint'
            : ''),
      },
      transformPastedHTML: clean,
    },
    onUpdate: ({ editor }) => {
      const html = editor.isEmpty ? '' : clean(editor.getHTML());
      lastEmitted.current = html;
      onChange(html);
    },
  });
  useEffect(() => {
    if (editor && value !== lastEmitted.current) {
      editor.commands.setContent(clean(value), {
        emitUpdate: false,
      });
      lastEmitted.current = value;
    }
  }, [editor, value, clean]);
  useEffect(() => {
    editor?.setEditable(!disabled, false);
  }, [editor, disabled]);
  const sourceValue = value === source.clean ? source.raw : value;
  const toolbar = [
    ['bold', 'bold', () => editor?.chain().focus().toggleBold().run()],
    ['italic', 'italic', () => editor?.chain().focus().toggleItalic().run()],
    [
      'underline',
      'underline',
      () => editor?.chain().focus().toggleUnderline().run(),
    ],
    ['strike', 'strike', () => editor?.chain().focus().toggleStrike().run()],
    [
      'list',
      'bulletList',
      () => editor?.chain().focus().toggleBulletList().run(),
    ],
    [
      'ordered_list',
      'orderedList',
      () => editor?.chain().focus().toggleOrderedList().run(),
    ],
    [
      'heading2',
      'heading',
      () => editor?.chain().focus().toggleHeading({ level: 2 }).run(),
    ],
    [
      'heading3',
      'heading',
      () => editor?.chain().focus().toggleHeading({ level: 3 }).run(),
    ],
    [
      'quote',
      'blockquote',
      () => editor?.chain().focus().toggleBlockquote().run(),
    ],
    [
      'code',
      'codeBlock',
      () => editor?.chain().focus().toggleCodeBlock().run(),
    ],
  ] as const;
  return (
    <div className="overflow-hidden rounded-md border border-slate-300">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 p-2">
        <div role="group" aria-label={t('editor.mode')} className="flex gap-1">
          {(['visual', 'html'] as const).map((item) => (
            <button
              type="button"
              key={item}
              className="button-secondary"
              aria-pressed={mode === item}
              disabled={disabled || !editor}
              onClick={() => {
                if (!editor || mode === item) return;
                if (item === 'html')
                  setSource({
                    raw: editor.isEmpty ? '' : editor.getHTML(),
                    clean: value,
                    edited: false,
                  });
                else if (source.edited) {
                  editor.commands.setContent(clean(sourceValue), {
                    emitUpdate: false,
                  });
                  const html = editor.isEmpty ? '' : clean(editor.getHTML());
                  lastEmitted.current = html;
                  onChange(html);
                }
                setMode(item);
              }}
            >
              {t('editor.' + item)}
            </button>
          ))}
        </div>
        {mode === 'visual' && (
          <div
            role="group"
            aria-label={t('editor.formatting')}
            className="flex flex-wrap gap-1"
          >
            {toolbar
              .filter(
                ([key]) =>
                  profile !== 'page' ||
                  !['heading2', 'heading3', 'code'].includes(key),
              )
              .map(([key, mark, command]) => (
                <button
                  type="button"
                  key={key}
                  className="button-secondary"
                  disabled={disabled || !editor}
                  aria-pressed={
                    editor?.isActive(
                      mark,
                      key === 'heading2'
                        ? { level: 2 }
                        : key === 'heading3'
                          ? { level: 3 }
                          : {},
                    ) ?? false
                  }
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={command}
                >
                  {t('editor.' + key)}
                </button>
              ))}
            <button
              type="button"
              className="button-secondary"
              disabled={disabled || !editor}
              onClick={() => {
                const url = window.prompt(
                  t(
                    profile === 'page'
                      ? 'editor.page_link_prompt'
                      : 'editor.link_prompt',
                  ),
                  editor?.getAttributes('link').href ?? '',
                );
                if (url === null) return;
                if (url.trim() === '')
                  editor
                    ?.chain()
                    .focus()
                    .extendMarkRange('link')
                    .unsetLink()
                    .run();
                else if (
                  (profile === 'page' ? isPageLink : isRichTextLink)(url.trim())
                )
                  editor
                    ?.chain()
                    .focus()
                    .extendMarkRange('link')
                    .setLink({ href: url.trim() })
                    .run();
              }}
            >
              {t('editor.link')}
            </button>
            {profile === 'page' && editor && (
              <PageTools editor={editor} disabled={disabled} />
            )}
            <button
              type="button"
              className="button-secondary"
              disabled={disabled || !editor?.can().undo()}
              onClick={() => editor?.chain().focus().undo().run()}
            >
              {t('editor.undo')}
            </button>
            <button
              type="button"
              className="button-secondary"
              disabled={disabled || !editor?.can().redo()}
              onClick={() => editor?.chain().focus().redo().run()}
            >
              {t('editor.redo')}
            </button>
          </div>
        )}
      </div>
      {mode === 'visual' ? (
        <EditorContent editor={editor} />
      ) : (
        <>
          <textarea
            id={id}
            aria-label={label}
            aria-describedby={[describedBy, id + '-html-help']
              .filter(Boolean)
              .join(' ')}
            aria-invalid={invalid}
            maxLength={maxLength}
            className="field min-h-64 rounded-none border-0 p-4 font-mono text-sm"
            spellCheck={false}
            disabled={disabled}
            value={sourceValue}
            onChange={(event) => {
              const raw = event.target.value;
              const html = clean(raw);
              setSource({ raw, clean: html, edited: true });
              lastEmitted.current = html;
              onChange(html);
            }}
          />
          <p
            id={id + '-html-help'}
            className="px-4 pb-3 text-sm text-slate-500"
          >
            {t(
              profile === 'page' ? 'editor.page_html_help' : 'editor.html_help',
            )}
          </p>
        </>
      )}
    </div>
  );
}

/** Extra controls of the page profile: text style, alignment, colour, line, table, image, video, clear. */
function PageTools({
  editor,
  disabled,
}: {
  editor: Editor;
  disabled: boolean;
}) {
  const t = useTranslation();
  const chain = () => editor.chain().focus();
  const level = ([1, 2, 3, 4] as const).find((l) =>
    editor.isActive('heading', { level: l }),
  );
  const inTable = editor.isActive('table');
  const button = (
    key: string,
    onClick: () => void,
    pressed?: boolean,
    enabled = true,
  ) => (
    <button
      type="button"
      key={key}
      className="button-secondary"
      disabled={disabled || !enabled}
      aria-pressed={pressed}
      onMouseDown={(event) => event.preventDefault()}
      onClick={onClick}
    >
      {t('editor.' + key)}
    </button>
  );
  return (
    <>
      <select
        aria-label={t('editor.block_format')}
        className="field h-9 w-40 py-1 text-sm"
        disabled={disabled}
        value={level ? String(level) : 'p'}
        onChange={(event) => {
          const value = event.target.value;
          if (value === 'p') chain().setParagraph().run();
          else
            chain()
              .setHeading({ level: Number(value) as 1 | 2 | 3 | 4 })
              .run();
        }}
      >
        <option value="p">{t('editor.paragraph')}</option>
        <option value="1">{t('editor.heading1')}</option>
        <option value="2">{t('editor.heading2')}</option>
        <option value="3">{t('editor.heading3')}</option>
        <option value="4">{t('editor.heading4')}</option>
      </select>
      {(['left', 'center', 'right', 'justify'] as const).map((align) =>
        button(
          'align_' + align,
          () => chain().setTextAlign(align).run(),
          editor.isActive({ textAlign: align }),
        ),
      )}
      <label className="button-secondary inline-flex items-center gap-2">
        <span>{t('editor.text_color')}</span>
        <input
          type="color"
          aria-label={t('editor.text_color')}
          className="h-5 w-7 cursor-pointer border-0 bg-transparent p-0"
          disabled={disabled}
          value={
            (editor.getAttributes('textStyle').color as string | undefined) ??
            '#000000'
          }
          onChange={(event) => chain().setColor(event.target.value).run()}
        />
      </label>
      {button('rule', () => chain().setHorizontalRule().run())}
      {button('table', () =>
        chain().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(),
      )}
      {inTable && (
        <>
          {button('table_row', () => chain().addRowAfter().run())}
          {button('table_column', () => chain().addColumnAfter().run())}
          {button('table_delete_row', () => chain().deleteRow().run())}
          {button('table_delete_column', () => chain().deleteColumn().run())}
          {button('table_delete', () => chain().deleteTable().run())}
        </>
      )}
      {button('image', () => {
        const src = window.prompt(t('editor.image_prompt'), '');
        if (!src || !isPageMedia(src.trim())) return;
        const alt = window.prompt(t('editor.image_alt_prompt'), '') ?? '';
        chain().setImage({ src: src.trim(), alt: alt.trim() }).run();
      })}
      {button('video', () => {
        const src = window.prompt(t('editor.video_prompt'), '');
        if (src?.trim()) chain().setYoutubeVideo({ src: src.trim() }).run();
      })}
      {button('clear', () => chain().unsetAllMarks().clearNodes().run())}
    </>
  );
}
