import { useCallback, useEffect, useReducer, useRef } from 'react';
import { api } from './api';
import useFancybox from './useFancybox';

import LoadingSpinner from './components/LoadingSpinner';
import EmptyState     from './components/EmptyState';
import SettingsModal  from './components/SettingsModal';
import PostSelector   from './components/PostSelector';
import ReportView     from './components/ReportView';
import ErrorBanner    from './components/ErrorBanner';

// ── State machine ────────────────────────────────────────────────────────────

const initialState = {
  posts:        [],
  users:        [],
  postId:       '',
  loadingPosts: false,
  analyzing:    false,
  report:       null,
  error:        null,
  showSettings: false,
};

function reducer(state, action) {
  switch (action.type) {
    case 'LOADING_POSTS':      return { ...state, loadingPosts: true,  error: null };
    case 'POSTS_LOADED':       return { ...state, loadingPosts: false, posts: action.posts };
    case 'USERS_LOADED':       return { ...state, users: action.users };
    case 'SET_POST_ID':        return { ...state, postId: action.id,  error: null };
    case 'ANALYZING':          return { ...state, analyzing: true, report: null, error: null };
    case 'REPORT_READY':       return { ...state, analyzing: false, report: action.report };
    case 'ERROR':              return { ...state, analyzing: false, loadingPosts: false, error: action.message };
    case 'CLEAR_ERROR':        return { ...state, error: null };
    case 'RESET':              return { ...initialState, posts: state.posts, users: state.users };
    case 'OPEN_SETTINGS':      return { ...state, showSettings: true };
    case 'CLOSE_SETTINGS':     return { ...state, showSettings: false };
    default:                   return state;
  }
}

// ── Component ────────────────────────────────────────────────────────────────

export default function PostAnalyzer() {
  const [state, dispatch] = useReducer(reducer, initialState);
  const wrapperRef   = useRef(null);
  const abortRef     = useRef(null);

  useFancybox(wrapperRef);

  // ── Load posts + users on mount ──────────────────────────────────────────
  useEffect(() => {
    const ctrl = new AbortController();
    abortRef.current = ctrl;

    dispatch({ type: 'LOADING_POSTS' });

    // The /users endpoint (for the settings author selector) is admin-only, so
    // only request it for admins — non-admins would get a 403 and break the page.
    const admin = window.postanalyzerWP?.user_level === 'admin';

    api.getPosts(ctrl.signal)
      .then(posts => {
        dispatch({ type: 'POSTS_LOADED', posts: posts ?? [] });
        if (admin) {
          api.getUsers(ctrl.signal)
            .then(users => dispatch({ type: 'USERS_LOADED', users: users ?? [] }))
            .catch(() => {}); // non-fatal: author selector just stays empty
        }
      })
      .catch(err => {
        if (err.name !== 'AbortError') {
          dispatch({ type: 'ERROR', message: 'Could not load posts. Please refresh. ' + err.message });
        }
      });

    return () => ctrl.abort();
  }, []);

  // ── Analyze ───────────────────────────────────────────────────────────────
  const analyzePost = useCallback(async () => {
    if (!state.postId) {
      dispatch({ type: 'ERROR', message: 'Please select a post.' });
      return;
    }
    const ctrl = new AbortController();
    abortRef.current = ctrl;

    dispatch({ type: 'ANALYZING' });

    try {
      const data = await api.analyzePost(parseInt(state.postId, 10), ctrl.signal);
      if (data?.error) throw new Error(typeof data.error === 'string' ? data.error : 'Analysis failed.');
      dispatch({ type: 'REPORT_READY', report: data });
    } catch (err) {
      if (err.name !== 'AbortError') {
        dispatch({ type: 'ERROR', message: err.message || 'Analysis failed.' });
      }
    }
  }, [state.postId]);

  const abort = useCallback(() => {
    abortRef.current?.abort();
    dispatch({ type: 'ERROR', message: null });
    dispatch({ type: 'CLEAR_ERROR' });
    dispatch({ type: 'RESET' });
  }, []);

  const reset = useCallback(() => {
    abortRef.current?.abort();
    dispatch({ type: 'RESET' });
  }, []);

  // ── Derived ───────────────────────────────────────────────────────────────
  const isAdmin    = window.postanalyzerWP?.user_level === 'admin';
  const hasPosts   = state.posts.length > 0;
  const canAnalyze = !!state.postId && !state.analyzing;

  return (
    <div
      className={`postanalyzer-wrapper${state.showSettings ? ' modal-open' : ''}`}
      ref={wrapperRef}
    >
      {/* ── Header ─────────────────────────────────────────────────── */}
      <header className="header-wrapper">
        <h1 className="head-title">Post Analyzer</h1>
        {isAdmin && (
          <button
            onClick={() => dispatch({ type: 'OPEN_SETTINGS' })}
            className="settings-button"
            title="Settings"
            aria-label="Open Settings"
            disabled={state.loadingPosts}
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <circle cx="12" cy="12" r="3"/>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
          </button>
        )}
      </header>

      {/* ── Subtitle ───────────────────────────────────────────────── */}
      <p className="head-text">
        {state.loadingPosts
          ? 'Loading available posts…'
          : hasPosts
            ? 'Choose a post to generate an automated QA & SEO report.'
            : 'No posts are currently available for analysis.'}
      </p>

      {/* ── Empty state ────────────────────────────────────────────── */}
      {!state.loadingPosts && !hasPosts && !state.error && (
        <EmptyState />
      )}

      {/* ── Post selector + action buttons ─────────────────────────── */}
      {(state.loadingPosts || hasPosts) && (
        <PostSelector
          posts={state.posts}
          postId={state.postId}
          loading={state.analyzing}
          loadingPosts={state.loadingPosts}
          canAnalyze={canAnalyze}
          onSelect={id => dispatch({ type: 'SET_POST_ID', id })}
          onAnalyze={analyzePost}
          onReset={reset}
          onAbort={abort}
        />
      )}

      {/* ── Error banner ───────────────────────────────────────────── */}
      {state.error && (
        <ErrorBanner message={state.error} onDismiss={() => dispatch({ type: 'CLEAR_ERROR' })} />
      )}

      {/* ── Settings modal ─────────────────────────────────────────── */}
      <SettingsModal
        isOpen={state.showSettings}
        onClose={() => dispatch({ type: 'CLOSE_SETTINGS' })}
        users={state.users}
      />

      {/* ── Report / Spinner ───────────────────────────────────────── */}
      {state.analyzing && <LoadingSpinner />}
      {!state.analyzing && state.report && (
        <ReportView report={state.report} postId={state.postId} onRefresh={analyzePost} />
      )}
    </div>
  );
}
