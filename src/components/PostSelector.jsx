import { useEffect, useRef } from 'react';
import NiceSelect from 'nice-select2';
import 'nice-select2/dist/css/nice-select2.css';

/**
 * Searchable post dropdown + Analyze / Reset buttons.
 * Wraps nice-select2 with proper React lifecycle management.
 */
export default function PostSelector({
  posts, postId, loading, loadingPosts,
  canAnalyze, onSelect, onAnalyze, onReset, onAbort,
}) {
  const selectRef    = useRef(null);
  const niceRef      = useRef(null);
  const handlerRef   = useRef(null);

  // Group posts by status.
  const grouped = posts.reduce((acc, post) => {
    if (!acc[post.status]) acc[post.status] = [];
    acc[post.status].push(post);
    return acc;
  }, {});

  // Init / reinit NiceSelect when posts load.
  useEffect(() => {
    const el = selectRef.current;
    if (!el || posts.length === 0) return;

    // Teardown previous instance.
    if (handlerRef.current) el.removeEventListener('change', handlerRef.current);
    if (niceRef.current) { try { niceRef.current.destroy(); } catch {} }
    el.nextElementSibling?.classList?.contains('nice-select') && el.nextElementSibling.remove();

    niceRef.current = new NiceSelect(el, { searchable: true, placeholder: 'Select a post…' });

    handlerRef.current = e => onSelect(e.target.value || '');
    el.addEventListener('change', handlerRef.current);

    return () => {
      if (handlerRef.current) el.removeEventListener('change', handlerRef.current);
      try { niceRef.current?.destroy(); } catch {}
    };
  }, [posts]); // eslint-disable-line react-hooks/exhaustive-deps

  // Keep UI in sync with external postId resets.
  useEffect(() => {
    const el = selectRef.current;
    if (!el || !niceRef.current) return;
    if (el.value !== (postId || '')) {
      el.value = postId || '';
      niceRef.current.update();
    }
  }, [postId]);

  return (
    <div className="input-wrapper">
      <select
        ref={selectRef}
        value={postId}
        onChange={e => onSelect(e.target.value)}
        className="post-select small hidden"
        aria-label="Select post to analyze"
        name="postanalyzer-post"
        id="postanalyzer-post"
        disabled={loading || loadingPosts}
      >
        <option data-display="Select a post…" disabled value="">
          {loadingPosts ? 'Loading posts…' : 'Select a post…'}
        </option>

        {Object.entries(grouped).map(([status, groupPosts]) => (
          <optgroup key={status} label={status}>
            {groupPosts.map(p => (
              <option key={p.id} value={p.id}>
                {p.title}
              </option>
            ))}
          </optgroup>
        ))}
      </select>

      <div className="action-wrapper">
        <button
          onClick={onAnalyze}
          disabled={!canAnalyze}
          className="analyze-post-button"
          aria-busy={loading}
        >
          {loading ? 'Analyzing…' : 'Analyze Post'}
        </button>

        <button
          onClick={loading ? onAbort : onReset}
          className={`reset-btn${loading ? ' abort' : ''}`}
        >
          {loading ? 'Abort' : 'Reset'}
        </button>
      </div>
    </div>
  );
}
