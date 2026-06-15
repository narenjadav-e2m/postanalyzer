import { useEffect, useRef, useState, useCallback } from 'react';
import NiceSelect from 'nice-select2';
import 'nice-select2/dist/css/nice-select2.css';
import { api } from '../api';

const PLATFORMS = {
  groq: {
    label: 'Groq',
    helpUrl: 'https://console.groq.com',
    helpSteps: [
      { text: 'Visit console.groq.com', href: 'https://console.groq.com' },
      { text: 'Sign up or log in' },
      { text: 'Navigate to API Keys' },
      { text: 'Click "Create API Key" and copy it' },
    ],
  },
  openai: {
    label: 'OpenAI (ChatGPT)',
    helpUrl: 'https://platform.openai.com/api-keys',
    helpSteps: [
      { text: 'Visit platform.openai.com/api-keys', href: 'https://platform.openai.com/api-keys' },
      { text: 'Log in or sign up' },
      { text: 'Click "Create new secret key"' },
      { text: 'Copy and save the key' },
    ],
  },
  gemini: {
    label: 'Gemini (Google)',
    helpUrl: 'https://aistudio.google.com/app/apikey',
    helpSteps: [
      { text: 'Visit aistudio.google.com/app/apikey', href: 'https://aistudio.google.com/app/apikey' },
      { text: 'Sign in with your Google account' },
      { text: 'Click "Create API Key"' },
      { text: 'Copy the generated key' },
    ],
  },
};

export default function SettingsModal({ isOpen, onClose, users = [] }) {
  const [platform,     setPlatform]     = useState('groq');
  const [apiKeys,      setApiKeys]      = useState({ groq: '', openai: '', gemini: '' });
  // Which platforms already have a key stored server-side. Inputs stay empty;
  // an empty field on save means "keep the existing key" rather than overwrite it.
  const [savedKeys,    setSavedKeys]    = useState({});
  const [authorId,     setAuthorId]     = useState('');
  const [showHelp,     setShowHelp]     = useState(false);
  const [showKey,      setShowKey]      = useState(false);
  const [saving,       setSaving]       = useState(false);
  const [validating,   setValidating]   = useState(false);
  const [status,       setStatus]       = useState({ type: '', message: '' });

  const authorSelectRef  = useRef(null);
  const authorNiceRef    = useRef(null);
  const handlerRef       = useRef(null);

  // Lock body scroll when open.
  useEffect(() => {
    document.body.style.overflow = isOpen ? 'hidden' : '';
    return () => { document.body.style.overflow = ''; };
  }, [isOpen]);

  // Load saved settings when modal opens.
  useEffect(() => {
    if (!isOpen) return;
    setStatus({ type: '', message: '' });

    api.getSettings().then(data => {
      if (data.ai_platform) setPlatform(data.ai_platform);
      if (data.author_id)   setAuthorId(String(data.author_id));
      // Reset inputs and record which platforms have a stored key. We never load
      // the actual key value (the server no longer exposes it).
      setApiKeys({ groq: '', openai: '', gemini: '' });
      setSavedKeys(data.api_keys_saved || {});
    }).catch(() => {});
  }, [isOpen]);

  // Author NiceSelect lifecycle.
  useEffect(() => {
    if (!isOpen || users.length === 0) return;
    const el = authorSelectRef.current;
    if (!el) return;

    const init = () => {
      if (handlerRef.current) el.removeEventListener('change', handlerRef.current);
      try { authorNiceRef.current?.destroy(); } catch {}
      el.nextElementSibling?.classList?.contains('nice-select') && el.nextElementSibling.remove();

      authorNiceRef.current = new NiceSelect(el, { searchable: true, placeholder: 'Select an author…' });
      handlerRef.current = e => setAuthorId(e.target.value || '');
      el.addEventListener('change', handlerRef.current);

      if (authorId) { el.value = authorId; authorNiceRef.current.update(); }
    };

    const t = setTimeout(init, 80);
    return () => {
      clearTimeout(t);
      if (handlerRef.current) el.removeEventListener('change', handlerRef.current);
      try { authorNiceRef.current?.destroy(); } catch {}
    };
  }, [isOpen, users]); // eslint-disable-line react-hooks/exhaustive-deps

  // Sync author select value.
  useEffect(() => {
    const el = authorSelectRef.current;
    if (!el || !authorNiceRef.current || el.value === authorId) return;
    el.value = authorId;
    authorNiceRef.current.update();
  }, [authorId]);

  const currentKey = apiKeys[platform] ?? '';

  const handleSave = useCallback(async () => {
    // A blank field is fine as long as a key is already stored for this platform
    // (e.g. the user only wants to change the default author).
    if (!currentKey.trim() && !savedKeys[platform]) {
      setStatus({ type: 'error', message: `Please enter your ${PLATFORMS[platform].label} API key.` });
      return;
    }

    const reValidating = !!currentKey.trim();
    setSaving(true);
    setValidating(reValidating);
    setStatus(reValidating
      ? { type: 'info', message: `Validating ${PLATFORMS[platform].label} API key…` }
      : { type: 'info', message: 'Saving…' });

    try {
      const data = await api.saveSettings({
        ai_platform: platform,
        api_keys: {
          groq:   apiKeys.groq.trim(),
          openai: apiKeys.openai.trim(),
          gemini: apiKeys.gemini.trim(),
        },
        author_id: authorId ? parseInt(authorId, 10) : 0,
      });

      if (data.success) {
        // Mark any newly-entered keys as stored, then clear the inputs so the
        // masked-placeholder round-trip can't happen.
        setSavedKeys(prev => ({
          ...prev,
          ...Object.fromEntries(
            Object.entries(apiKeys).filter(([, v]) => v.trim()).map(([k]) => [k, true])
          ),
        }));
        setApiKeys({ groq: '', openai: '', gemini: '' });
        setStatus({ type: 'success', message: data.message || 'Settings saved.' });
        setTimeout(() => { setStatus({ type: '', message: '' }); }, 3000);
      } else {
        throw new Error(data.message || 'Save failed.');
      }
    } catch (err) {
      setStatus({ type: 'error', message: err.message || 'Error saving settings.' });
    } finally {
      setSaving(false);
      setValidating(false);
    }
  }, [platform, apiKeys, authorId, currentKey, savedKeys]);

  // Keyboard close.
  useEffect(() => {
    if (!isOpen) return;
    const handler = e => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const statusClass = {
    success: 'settings-message-success',
    error:   'settings-message-error',
    info:    'settings-message-info',
  }[status.type] || '';

  return (
    <div
      className="settings-modal-overlay"
      role="dialog"
      aria-modal="true"
      aria-label="Post Analyzer Settings"
      onClick={e => e.target === e.currentTarget && onClose()}
    >
      <div className="settings-modal-container">
        {/* Close */}
        <button onClick={onClose} className="settings-modal-close" aria-label="Close settings">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
            <path d="M18 6L6 18M6 6l12 12" />
          </svg>
        </button>

        <h2 className="settings-modal-title">Post Analyzer Settings</h2>

        {/* Platform selector */}
        <div className="settings-form-group">
          <label className="settings-form-label">AI Platform</label>
          <div className="settings-radio-group">
            {Object.entries(PLATFORMS).map(([key, plat]) => (
              <label key={key} className={`settings-radio-label${platform === key ? ' selected' : ''}`}>
                <input
                  type="radio"
                  name="ai-platform"
                  value={key}
                  checked={platform === key}
                  onChange={() => { setPlatform(key); setShowHelp(false); setShowKey(false); }}
                  className="settings-radio-input"
                />
                <span className="settings-radio-text">{plat.label}</span>
                {(savedKeys[key] || apiKeys[key]) && <span className="settings-key-dot" title="Key saved" />}
              </label>
            ))}
          </div>
        </div>

        {/* API key input */}
        <div className="settings-form-group">
          <label htmlFor="pa-api-key" className="settings-form-label">
            {PLATFORMS[platform].label} API Key
          </label>
          <div className="settings-input-wrapper">
            <input
              type={showKey ? 'text' : 'password'}
              id="pa-api-key"
              value={currentKey}
              onChange={e => setApiKeys(prev => ({ ...prev, [platform]: e.target.value }))}
              placeholder={savedKeys[platform]
                ? 'Key saved — leave blank to keep, or enter a new one'
                : `Enter your ${PLATFORMS[platform].label} API key`}
              className="settings-form-input"
              autoComplete="off"
              spellCheck={false}
            />
            <button
              type="button"
              onClick={() => setShowKey(v => !v)}
              className="settings-toggle-visibility"
              aria-label={showKey ? 'Hide key' : 'Show key'}
              title={showKey ? 'Hide key' : 'Show key'}
            >
              {showKey ? '🙈' : '👁'}
            </button>
            <button
              type="button"
              onClick={() => setShowHelp(v => !v)}
              className="settings-help-button"
              aria-label="How to get API key"
            >
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
              </svg>
            </button>
          </div>

          {showHelp && (
            <div className="settings-help-popover">
              <h4 className="settings-help-title">How to get a {PLATFORMS[platform].label} API key:</h4>
              <ol className="settings-help-list">
                {PLATFORMS[platform].helpSteps.map((step, i) => (
                  <li key={i}>
                    {step.href
                      ? <a href={step.href} target="_blank" rel="noopener noreferrer" className="settings-help-link">{step.text}</a>
                      : step.text}
                  </li>
                ))}
              </ol>
              <button onClick={() => setShowHelp(false)} className="settings-help-close">Close</button>
            </div>
          )}
        </div>

        {/* Author select */}
        {users.length > 0 && (
          <div className="settings-form-group">
            <label htmlFor="pa-author" className="settings-form-label">Default Author</label>
            <select
              ref={authorSelectRef}
              id="pa-author"
              value={authorId}
              onChange={e => setAuthorId(e.target.value)}
              className="settings-form-select small hidden"
              aria-label="Select default author"
            >
              <option value="" disabled>Select an author…</option>
              {users.map(u => (
                <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
              ))}
            </select>
          </div>
        )}

        {/* Status */}
        {status.message && (
          <div className={`settings-message ${statusClass}`} role="status">
            {(validating) && (
              <svg className="settings-spinner-icon" viewBox="0 0 24 24" aria-hidden="true">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
            )}
            {status.message}
          </div>
        )}

        {/* Actions */}
        <div className="settings-actions">
          <button onClick={handleSave} disabled={saving} className="settings-button-save">
            {saving ? 'Saving…' : 'Save Settings'}
          </button>
          <button onClick={onClose} className="settings-button-cancel">Cancel</button>
        </div>
      </div>
    </div>
  );
}
