import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../api';

/**
 * Hover-to-edit control for a single report field.
 *
 * Renders its children (the displayed value) with a hover-revealed "edit"
 * button. Clicking opens an anchored popover offering a manual text edit and
 * AI-generated replacement suggestions. Saving (manual) or picking a suggestion
 * writes to the backend via api.updateField, then calls onSaved() so the parent
 * can re-analyze the report.
 *
 * @param {object}   props
 * @param {string}   props.field          Field key (post_title, seo_title, alt, …)
 * @param {number}   props.postId
 * @param {number}  [props.attachmentId]  Required for alt / image_title.
 * @param {string}   props.value          Current value (prefills the input).
 * @param {string}   props.label          Human label, e.g. "SEO Title".
 * @param {boolean} [props.multiline]     Use a textarea instead of an input.
 * @param {boolean} [props.disabled]      Hide the edit affordance.
 * @param {string}  [props.disabledReason]
 * @param {Function} props.onSaved        Called after a successful save.
 * @param {React.ReactNode} props.children
 */
export default function EditPopover({
  field, postId, attachmentId = 0, value = '', label,
  multiline = false, disabled = false, disabledReason = '',
  onSaved, children,
}) {
  const [open, setOpen]               = useState(false);
  const [draft, setDraft]             = useState(value);
  const [suggestions, setSuggestions] = useState([]);
  const [loadingAI, setLoadingAI]     = useState(false);
  const [saving, setSaving]           = useState(false);
  const [error, setError]             = useState('');

  const rootRef  = useRef(null);
  const abortRef = useRef(null);

  // Reset draft whenever a fresh value flows in (e.g. after re-analyze).
  useEffect(() => { setDraft(value); }, [value]);

  const close = useCallback(() => {
    setOpen(false);
    setSuggestions([]);
    setError('');
    abortRef.current?.abort();
  }, []);

  // Close on outside-click / Escape while open.
  useEffect(() => {
    if (!open) return;
    const onDown = e => { if (rootRef.current && !rootRef.current.contains(e.target)) close(); };
    const onKey  = e => { if (e.key === 'Escape') close(); };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, close]);

  const payload = extra => ({ field, post_id: postId, attachment_id: attachmentId, ...extra });

  const fetchSuggestions = useCallback(async () => {
    setLoadingAI(true);
    setError('');
    setSuggestions([]);
    abortRef.current = new AbortController();
    try {
      const data = await api.suggestField(payload(), abortRef.current.signal);
      setSuggestions(Array.isArray(data?.suggestions) ? data.suggestions : []);
      if (!data?.suggestions?.length) setError('No suggestions returned.');
    } catch (err) {
      if (err.name !== 'AbortError') setError(err.message || 'Could not load suggestions.');
    } finally {
      setLoadingAI(false);
    }
  }, [field, postId, attachmentId]); // eslint-disable-line react-hooks/exhaustive-deps

  const save = useCallback(async (newValue) => {
    setSaving(true);
    setError('');
    try {
      await api.updateField(payload({ value: newValue }));
      close();
      onSaved?.();
    } catch (err) {
      setError(err.message || 'Could not save.');
      setSaving(false);
    }
  }, [field, postId, attachmentId, onSaved, close]);

  if (disabled) {
    return (
      <span className="pa-edit-wrap pa-edit-disabled" title={disabledReason || 'Not editable'}>
        {children}
      </span>
    );
  }

  return (
    <span className="pa-edit-wrap" ref={rootRef}>
      {children}
      <button
        type="button"
        className="pa-edit-btn"
        aria-label={`Edit ${label}`}
        title={`Edit ${label}`}
        onClick={() => { setOpen(o => !o); setDraft(value); }}
      >
        ✦
      </button>

      {open && (
        <div className="pa-edit-pop" role="dialog" aria-label={`Edit ${label}`}>
          <div className="pa-edit-pop-title">Edit {label}</div>

          {multiline
            ? <textarea className="pa-edit-input" rows={3} value={draft} onChange={e => setDraft(e.target.value)} autoFocus />
            : <input className="pa-edit-input" type="text" value={draft} onChange={e => setDraft(e.target.value)} autoFocus />
          }

          <div className="pa-edit-actions">
            <button type="button" className="pa-edit-save" disabled={saving} onClick={() => save(draft)}>
              {saving ? 'Saving…' : 'Save'}
            </button>
            <button type="button" className="pa-edit-ai" disabled={loadingAI || saving} onClick={fetchSuggestions}>
              {loadingAI ? 'Thinking…' : '✨ AI suggestions'}
            </button>
            <button type="button" className="pa-edit-cancel" onClick={close}>Cancel</button>
          </div>

          {error && <div className="pa-edit-error">{error}</div>}

          {suggestions.length > 0 && (
            <ul className="pa-edit-suggestions">
              {suggestions.map((s, i) => (
                <li key={i}>
                  <button
                    type="button"
                    className="pa-edit-suggestion"
                    disabled={saving}
                    title="Apply &amp; save"
                    onClick={() => save(s)}
                  >
                    {s}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </span>
  );
}
