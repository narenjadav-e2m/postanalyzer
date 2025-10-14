import { useEffect, useRef, useState } from 'react';

import useFancybox from './useFancybox';

import NiceSelect from 'nice-select2';
import 'nice-select2/dist/css/nice-select2.css';

import LoadingSpinner from './components/LoadingSpinner';
import EmptyState from './components/EmptyState';
import SettingsModal from './components/SettingsModal';
import MetaRow from './components/MetaRow';
import ImageMeta from './components/ImageMeta';

function PostAnalyzer() {

  const fancyboxRootRef = useRef(null);
  useFancybox(fancyboxRootRef);

  const [postId, setPostId] = useState('');
  const [posts, setPosts] = useState([]);
  const [loadingPosts, setLoadingPosts] = useState(false);

  const [loading, setLoading] = useState(false);
  const [report, setReport] = useState(null);
  const [error, setError] = useState(null);

  const [showSettings, setShowSettings] = useState(false);
  const [users, setUsers] = useState([]);

  const selectRef = useRef(null);
  const niceSelectRef = useRef(null);
  const abortControllerRef = useRef(null);

  const isEmpty = !postId;
  const isButtonDisabled = loading || isEmpty;
  const hasPosts = posts.length > 0;

  useEffect(() => {
    const links = document.querySelectorAll('#postanalyzer-root a');
    links.forEach(link => {
      if (!link.hasAttribute('skipBlank')) {
        link.setAttribute('target', '_blank');
        link.setAttribute('rel', 'noopener noreferrer');
      }
    });
  }, []);


  // Load posts on mount
  useEffect(() => {
    let mounted = true;
    const loadPosts = async () => {
      setLoadingPosts(true);
      try {
        const res = await fetch(postanalyzerWP.restUrl + 'posts?per_page=0', {
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': postanalyzerWP.nonce }
        });

        if (!res.ok) {
          const text = await res.text().catch(() => '');
          throw new Error(text || `Failed to load posts (HTTP ${res.status})`);
        }

        const data = await res.json();
        if (mounted) setPosts(data || []);
      } catch (e) {
        if (mounted) setError('Could not load posts. Refresh the page. ' + (e.message || ''));
      } finally {
        if (mounted) setLoadingPosts(false);
      }
    };

    const loadUsers = async () => {
      try {
        const res = await fetch(postanalyzerWP.restUrl + 'users', {
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': postanalyzerWP.nonce }
        });
        const data = await res.json();

        if (mounted) setUsers(data);
      } catch (e) {
        console.error('Failed to load users:', e);
      }
    };

    loadPosts();
    loadUsers();

    return () => { mounted = false; };
  }, []);

  // Function to initialize NiceSelect
  const initNiceSelect = () => {
    const select = selectRef.current;
    if (!select) return;

    // Destroy previous instance if any
    if (niceSelectRef.current) {
      try {
        niceSelectRef.current.destroy();
      } catch (e) {
        console.error('Error destroying NiceSelect:', e);
      }
      niceSelectRef.current = null;
    }

    // Remove any existing nice-select markup
    const existingNice = select.nextElementSibling;
    if (existingNice && existingNice.classList.contains('nice-select')) {
      existingNice.remove();
    }

    // Initialize new instance
    niceSelectRef.current = new NiceSelect(select, {
      searchable: true,
      placeholder: 'Select a post...'
    });

    // Sync React state when user changes selection
    const onChangeHandler = (e) => setPostId(e.target.value || '');
    select.addEventListener('change', onChangeHandler);

    // Store the handler for cleanup
    select._changeHandler = onChangeHandler;
  };

  // Initialize NiceSelect2 when posts list changes
  useEffect(() => {
    if (posts.length > 0) {
      initNiceSelect();
    }

    return () => {
      const select = selectRef.current;
      if (select && select._changeHandler) {
        select.removeEventListener('change', select._changeHandler);
        delete select._changeHandler;
      }
      if (niceSelectRef.current) {
        try {
          niceSelectRef.current.destroy();
        } catch (e) {
          console.error('Error destroying NiceSelect in cleanup:', e);
        }
        niceSelectRef.current = null;
      }
    };
  }, [posts, loadingPosts]);

  // Keep nice-select UI in sync with React value
  useEffect(() => {
    const select = selectRef.current;
    if (select && niceSelectRef.current && select.value !== (postId || '')) {
      select.value = postId || '';
      niceSelectRef.current.update();
    }
  }, [postId]);

  const analyzePost = async () => {
    setError(null);

    if (isEmpty) {
      setError('Please select a post from the dropdown.');
      return;
    }

    setLoading(true);
    setReport(null);

    const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    // Create new AbortController for this request
    abortControllerRef.current = new AbortController();

    try {
      const res = await fetch(postanalyzerWP.restUrl + 'analyze-post', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': postanalyzerWP.nonce
        },
        body: JSON.stringify({ post_id: parseInt(postId, 10) }),
        signal: abortControllerRef.current.signal
      });

      if (!res.ok) {
        const txt = await res.text();
        throw new Error(txt || 'Server error');
      }

      const data = await res.json();

      await delay(1000);

      if (data?.error) {
        setError(typeof data.error === 'string' ? data.error : 'Analysis failed.');
      } else {
        setReport(data);
      }
    } catch (err) {
      if (err.name === 'AbortError') {
        console.log('Analysis request was aborted');
        setError(null); // Don't show error for aborted requests
      } else {
        setError(err.message || 'Failed to analyze post');
      }
    } finally {
      setLoading(false);
      abortControllerRef.current = null;
    }
  };

  const handleReset = () => {
    // Abort any ongoing request
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
    }

    setPostId('');
    setReport(null);
    setError(null);
    setLoading(false);

    // Force reinitialize NiceSelect to ensure proper reset
    setTimeout(() => {
      initNiceSelect();
    }, 0);
  };

  const handleSettingsClick = () => {
    setShowSettings(true);
  };

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      // Abort any ongoing request when component unmounts
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    };
  }, []);

  return (
    <div className={`postanalyzer-wrapper${showSettings ? ' modal-open' : ''}`} ref={fancyboxRootRef}>
      <div className="header-wrapper">
        <h1 className="head-title">Post Analyzer</h1>
        {postanalyzerWP.user_level === 'admin' && (
          <button onClick={handleSettingsClick} className="settings-button" title="Settings" aria-label="Open Settings" disabled={loadingPosts}>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
          </button>
        )}
      </div>

      <p className="head-text">{loadingPosts ? 'Loading available posts...' : hasPosts ? 'Choose a post to generate an automated QA & SEO report.' : 'No posts are currently available for analysis.'}</p>

      {/* Show empty state when no posts are available */}
      {!loadingPosts && !hasPosts && !error && (
        <EmptyState loading={loadingPosts} />
      )}

      {/* Only show controls when posts are available */}
      {hasPosts && (
        <>
          <div className="input-wrapper">
            <select ref={selectRef} value={postId} onChange={(e) => { setPostId(e.target.value); if (error) setError(null); }} className="post-select small hidden" aria-label="Select post to analyze" name="postanalyzer-post" id="postanalyzer-post" disabled={loading} >
              <option data-display="Select a post…" disabled>{loadingPosts ? 'Loading posts…' : 'Select'}</option>
              {Object.entries(
                posts.reduce((groups, post) => {
                  if (!groups[post.status]) groups[post.status] = [];
                  groups[post.status].push(post);
                  return groups;
                }, {})
              ).map(([status, groupPosts]) => (
                <optgroup key={status} label={status.charAt(0).toUpperCase() + status.slice(1)}>
                  {groupPosts.map(p => (
                    <option key={p.id} value={p.id}>{p.title}</option>
                  ))}
                </optgroup>
              ))}
            </select>

            <div className='action-wrapper'>
              <button onClick={analyzePost} disabled={isButtonDisabled} className="analyze-post-button">{loading ? 'Analyzing...' : 'Analyze Post'}</button>
              <button onClick={handleReset} className={`${loading ? 'reset-btn abort' : 'reset-btn'}`}
              >{loading ? 'Abort' : 'Reset'}</button>
            </div>
          </div>
        </>
      )}

      {error && <div className="mb-1 text-red-600" role="alert">{error}</div>}

      {/* Settings Modal */}
      <SettingsModal isOpen={showSettings} onClose={() => setShowSettings(false)} users={users} />

      {
        loading ? (
          <LoadingSpinner />
        ) : (
          report && (
            <div className="result-container">
              <div className="title-wrapper">
                <h2 className="title">Analysis Report</h2>
              </div>

              {/* Basic Post Info */}
              <section className="card">
                <h3 className="card-title">Basic Post Info</h3>
                <MetaRow label="Title" value={report.title} />
                <MetaRow label="URL" value={`<a href="${report.url}" target="_blank" rel="noopener noreferrer">${report.url}</a>`} isHtml={true} />
                <MetaRow label="Author" value={report.author} />
                <MetaRow label="Published Date" value={report.published_date} />
                <MetaRow label="Modified Date" value={report.updated_date} />
                <MetaRow label="Categories" value={report.categories} isHtml={true} />
                <MetaRow label="Tags" value={report.tags} isHtml={true} />
                <MetaRow label="Word Count" value={report.word_count} />
              </section>

              {/* SEO Data */}
              <section className="card">
                <h3 className="card-title">SEO Data</h3>
                <MetaRow label="SEO Title" value={report.seo?.title} />
                <MetaRow label="Meta Description" value={report.seo?.description} />
                <MetaRow label="Keywords" value={report.seo?.keywords} />
                {report.seo?.issues?.length > 0 && (
                  <div className="mt-2">
                    <strong>Issues:</strong>
                    <ul className="list-disc ml-5 mt-1">
                      {report.seo.issues.map((it, i) => <li key={i}>{it}</li>)}
                    </ul>
                  </div>
                )}
              </section>

              {/* Featured Image */}
              <section className="card">
                <h3 className="card-title">Featured Image</h3>
                {report.featured_image && <ImageMeta img={report.featured_image} featured={true} />}
                {!report.featured_image && <div className="text-sm text-gray-600">No featured image set.</div>}
              </section>

              {/* Attached Images */}
              <section className="card">
                <h3 className="card-title">Attached Images ({Object.keys(report.attached_images || {}).length})</h3>
                {(!report.attached_images || report.attached_images.length === 0) && <div className="text-sm text-gray-600">No attached images found.</div>}
                {report.attached_images && Object.keys(report.attached_images).length > 0 && (
                  <div className="attached-images">
                    {Object.values(report.attached_images).map((img, i) => (
                      <ImageMeta key={img.id || i} img={img} featured={false} />
                    ))}
                  </div>
                )}

              </section>

              {/* URL & Slug Suggestions */}
              <section className="card">
                <h3 className="card-title">URL & Slug Suggestions</h3>
                {report.url_suggestions && report.url_suggestions.length > 0 ? (
                  <ol className="list-decimal ml-5">
                    {report.url_suggestions.map((s, i) => <li key={i} className="mb-1">{s}</li>)}
                  </ol>
                ) : <div className="text-sm text-gray-600">No suggestions generated.</div>}
              </section>

              {/* AI Suggestions */}
              <section className="card">
                <h3 className="card-title">AI Suggestions</h3>
                {report.ai_suggestions && report.ai_suggestions.length > 0 ? (
                  <ul className="list-disc ml-5">
                    {report.ai_suggestions.map((s, i) => <li key={i}>{s}</li>)}
                  </ul>
                ) : <div className="text-gray-600">No AI suggestions returned.</div>}
              </section>
            </div>
          )
        )
      }
    </div >
  );
}

export default PostAnalyzer;