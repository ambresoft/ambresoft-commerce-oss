import { useEffect, useRef } from 'react';
import { EditorState } from '@codemirror/state';
import {
  EditorView,
  keymap,
  lineNumbers,
  highlightActiveLine,
  highlightActiveLineGutter,
  drawSelection,
} from '@codemirror/view';
import {
  defaultKeymap,
  history,
  historyKeymap,
  indentWithTab,
} from '@codemirror/commands';
import {
  bracketMatching,
  HighlightStyle,
  foldGutter,
  foldKeymap,
  indentOnInput,
  syntaxHighlighting,
} from '@codemirror/language';
import { search, searchKeymap, openSearchPanel } from '@codemirror/search';
import { javascript } from '@codemirror/lang-javascript';
import { css } from '@codemirror/lang-css';
import { tags } from '@lezer/highlight';

export default function ThemeSourceEditor({
  path,
  initial,
  onChange,
  onSave,
  searchRef,
  label,
}: {
  path: string;
  initial: string;
  onChange: (value: string) => void;
  onSave: () => void;
  searchRef: React.RefObject<(() => void) | null>;
  label: string;
}) {
  const host = useRef<HTMLDivElement>(null);
  const callbacks = useRef({ onChange, onSave });
  useEffect(() => {
    callbacks.current = { onChange, onSave };
  }, [onChange, onSave]);
  useEffect(() => {
    const view = new EditorView({
      parent: host.current!,
      state: EditorState.create({
        doc: initial,
        extensions: [
          lineNumbers(),
          highlightActiveLine(),
          highlightActiveLineGutter(),
          drawSelection(),
          history(),
          indentOnInput(),
          bracketMatching(),
          foldGutter(),
          syntaxHighlighting(
            HighlightStyle.define([
              {
                tag: [tags.keyword, tags.operator, tags.modifier],
                color: 'var(--code-keyword)',
              },
              {
                tag: [tags.string, tags.attributeValue],
                color: 'var(--code-string)',
              },
              {
                tag: [tags.number, tags.bool, tags.null],
                color: 'var(--code-number)',
              },
              {
                tag: [
                  tags.function(tags.variableName),
                  tags.typeName,
                  tags.className,
                  tags.tagName,
                ],
                color: 'var(--code-name)',
              },
              {
                tag: [tags.comment, tags.meta],
                color: 'var(--color-muted)',
                fontStyle: 'italic',
              },
            ]),
          ),
          search({ top: true }),
          path.endsWith('.css')
            ? css()
            : javascript({
                jsx: /\.[jt]sx$/.test(path),
                typescript: /\.tsx?$/.test(path),
              }),
          keymap.of([
            {
              key: 'Mod-s',
              run: () => {
                callbacks.current.onSave();
                return true;
              },
            },
            indentWithTab,
            ...defaultKeymap,
            ...historyKeymap,
            ...searchKeymap,
            ...foldKeymap,
          ]),
          EditorView.contentAttributes.of({
            'aria-label': label,
            'aria-describedby': 'theme-editor-keyboard',
          }),
          EditorView.updateListener.of((update) => {
            if (update.docChanged)
              callbacks.current.onChange(update.state.doc.toString());
          }),
          EditorView.theme({
            '&': {
              height: '65vh',
              minHeight: '320px',
              color: 'var(--color-ink)',
              backgroundColor: 'var(--color-surface)',
              fontSize: '13px',
            },
            '.cm-scroller': {
              overflow: 'auto',
              fontFamily:
                'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
            },
            '.cm-gutters': {
              backgroundColor: 'var(--color-canvas)',
              color: 'var(--color-muted)',
              borderColor: 'var(--color-divider)',
            },
            '.cm-activeLine, .cm-activeLineGutter': {
              backgroundColor: 'var(--color-primary-tint)',
            },
            '.cm-cursor': { borderLeftColor: 'var(--color-ink)' },
            '.cm-panels': {
              backgroundColor: 'var(--color-canvas)',
              color: 'var(--color-ink)',
            },
            '.cm-textfield, .cm-button': {
              background: 'var(--color-surface)',
              color: 'var(--color-ink)',
              border: '1px solid var(--color-divider)',
            },
          }),
        ],
      }),
    });
    searchRef.current = () => {
      openSearchPanel(view);
    };
    return () => {
      searchRef.current = null;
      view.destroy();
    };
    // A file/discard remount provides initial content; saving must preserve cursor and undo history.
  }, [path, initial, searchRef, label]);
  return (
    <div ref={host} className="theme-source-editor min-w-0 overflow-hidden" />
  );
}
