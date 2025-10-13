import React, { useEffect, useRef, useState } from 'react';
import useFancybox from './useFancybox';
import NiceSelect from 'nice-select2';
import 'nice-select2/dist/css/nice-select2.css';

// Loading Spinner Component
function LoadingSpinner() {
  return (
    <div className="flex flex-col items-center justify-center p-12 text-amber-800">
      <svg className="pa-spinner w-12 h-12 text-blue-600" fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
        <path
          className="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
        ></path>
      </svg>
      <p className="mt-4 !text-lg text-gray-600 !my-1">Analyzing post...</p>
      <p className="mt-2 !text-base text-gray-500 !my-1">Please wait while we generate your report</p>
    </div>
  );
}

// Empty State Component
function EmptyState({ loading }) {
  return (
    <div className="flex flex-col items-center justify-center p-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
      <svg className="w-16 h-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
      </svg>
      {loading ? (
        <>
          <h3 className="text-lg font-medium text-gray-900 mb-1">Loading Posts...</h3>
          <p className="text-gray-500">Please wait while we fetch available posts.</p>
        </>
      ) : (
        <>
          <h3 className="text-lg font-medium text-gray-900 mb-1">No Posts Available</h3>
          <p className="text-gray-500">There are no posts available to analyze at this time.</p>
          <p className="text-gray-500 mt-2">Please create or publish some posts first.</p>
        </>
      )}
    </div>
  );
}

function MetaRow({ label, value, isHtml = false }) {
  if (value === undefined || value === null || (Array.isArray(value) && value.length === 0))
    return null;

  const content = Array.isArray(value) ? value.join(', ') : String(value);

  return (
    <div className="mb-1">
      <strong className="mr-2">{label}:</strong>
      {isHtml ? (<span dangerouslySetInnerHTML={{ __html: content }} />) : (<span>{content}</span>)}
    </div>
  );
}

function ImageMeta({ img, featured = false }) {
  if (!img) return null;

  return (
    <div className={featured ? 'featured-img' : 'attached-img'}>
      <div>
        <MetaRow label="Title" value={img.title} />
        <MetaRow label="Alt" value={img.alt} />
        <MetaRow label="Caption" value={img.caption} />
        <MetaRow label="Description" value={img.description} />
        <MetaRow label="Filename" value={img.filename || img.src} />
        <MetaRow label="Dimensions" value={img.width && img.height ? `${img.width} \u00D7 ${img.height}` : undefined} />
      </div>

      <a href={img.src} data-fancybox={featured ? "featured" : "attached"} data-caption={img.caption || img.title || ''} skipBlank >
        {img.src && (<img src={img.src} alt={img.alt || ''} className="card-img" />)}
      </a>
    </div>
  );
}

// Settings Modal Component
function SettingsModal({ isOpen, onClose, users = [] }) {
  const [selectedPlatform, setSelectedPlatform] = useState('groq');
  const [apiKeys, setApiKeys] = useState({
    chatgpt: '',
    gemini: '',
    groq: ''
  });
  const [selectedAuthor, setSelectedAuthor] = useState('');
  const [showApiKeyHelp, setShowApiKeyHelp] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savedMessage, setSavedMessage] = useState('');

  const authorSelectRef = useRef(null);
  const authorNiceSelectRef = useRef(null);

  // Platform configurations
  const platforms = {
    chatgpt: {
      label: 'ChatGPT',
      helpSteps: [
        'Visit platform.openai.com',
        'Sign up or log in to your account',
        'Go to API Keys section',
        'Click "Create new secret key"',
        'Name your key and copy it'
      ],
      helpUrl: 'https://platform.openai.com/api-keys'
    },
    gemini: {
      label: 'Gemini',
      helpSteps: [
        'Visit aistudio.google.com/app/api-keys',
        'Sign in with your Google account',
        'Click "Create API Key"',
        'Select your project or create a new one',
        'Copy the generated API key'
      ],
      helpUrl: 'https://aistudio.google.com/app/api-keys'
    },
    groq: {
      label: 'Groq',
      helpSteps: [
        'Visit console.groq.com',
        'Sign up or log in to your account',
        'Navigate to API Keys section',
        'Click "Create API Key"',
        'Copy the generated key and paste it here'
      ],
      helpUrl: 'https://console.groq.com'
    }
  };

  // Handle body scroll when modal opens/closes
  useEffect(() => {
    const wrapper = document.querySelector('.postanalyzer-wrapper');

    if (isOpen && wrapper) {
      // Disable scroll on wrapper
      wrapper.style.overflow = 'hidden';
      // Optionally, you can also disable scroll on body
      document.body.style.overflow = 'hidden';
    } else if (wrapper) {
      // Re-enable scroll
      wrapper.style.overflow = '';
      document.body.style.overflow = '';
    }

    // Cleanup function
    return () => {
      if (wrapper) {
        wrapper.style.overflow = '';
      }
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  // Initialize NiceSelect for author dropdown when modal opens
  useEffect(() => {
    if (isOpen && users.length > 0) {
      const select = authorSelectRef.current;
      if (!select) return;

      // Small delay to ensure modal is fully rendered
      setTimeout(() => {
        // Destroy previous instance if any
        if (authorNiceSelectRef.current) {
          try {
            authorNiceSelectRef.current.destroy();
          } catch (e) {
            console.error('Error destroying author NiceSelect:', e);
          }
          authorNiceSelectRef.current = null;
        }

        // Remove any existing nice-select markup
        const existingNice = select.nextElementSibling;
        if (existingNice && existingNice.classList.contains('nice-select')) {
          existingNice.remove();
        }

        // Initialize new instance
        authorNiceSelectRef.current = new NiceSelect(select, {
          searchable: true,
          placeholder: 'Select an author...'
        });

        // Sync React state when user changes selection
        const onChangeHandler = (e) => setSelectedAuthor(e.target.value || '');
        select.addEventListener('change', onChangeHandler);

        // Store the handler for cleanup
        select._changeHandler = onChangeHandler;

        // Update the nice-select value if there's a saved value
        if (selectedAuthor) {
          select.value = selectedAuthor;
          authorNiceSelectRef.current.update();
        }
      }, 100);
    }

    // Cleanup when modal closes
    return () => {
      const select = authorSelectRef.current;
      if (select && select._changeHandler) {
        select.removeEventListener('change', select._changeHandler);
        delete select._changeHandler;
      }
      if (authorNiceSelectRef.current) {
        try {
          authorNiceSelectRef.current.destroy();
        } catch (e) {
          console.error('Error destroying author NiceSelect in cleanup:', e);
        }
        authorNiceSelectRef.current = null;
      }
    };
  }, [isOpen, users, selectedAuthor]);

  // Keep nice-select UI in sync with React value
  useEffect(() => {
    const select = authorSelectRef.current;
    if (select && authorNiceSelectRef.current && select.value !== (selectedAuthor || '')) {
      select.value = selectedAuthor || '';
      authorNiceSelectRef.current.update();
    }
  }, [selectedAuthor]);

  const handleSave = async () => {
    // Validation
    const currentApiKey = apiKeys[selectedPlatform];
    if (!currentApiKey || currentApiKey.trim() === '') {
      setSavedMessage(`Please enter ${platforms[selectedPlatform].label} API key.`);
      return;
    }

    if (!selectedAuthor) {
      setSavedMessage('Please select an author to validate.');
      return;
    }

    setSaving(true);
    setSavedMessage('');

    try {
      // First show validation message
      setSavedMessage(`Validating ${platforms[selectedPlatform].label} API key...`);

      // Save to WordPress options via API
      const res = await fetch(postanalyzerWP.restUrl + 'save-settings', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': postanalyzerWP.nonce
        },
        body: JSON.stringify({
          ai_platform: selectedPlatform,
          api_keys: {
            chatgpt: apiKeys.chatgpt.trim(),
            gemini: apiKeys.gemini.trim(),
            groq: apiKeys.groq.trim()
          },
          author_id: parseInt(selectedAuthor, 10)
        })
      });

      const data = await res.json();

      if (!res.ok) {
        throw new Error(data.message || `Server error (${res.status})`);
      }

      if (data.success) {
        // Show saving message after successful validation
        setSavedMessage('API key validated! Saving settings...');

        // Small delay to show the saving message
        await new Promise(resolve => setTimeout(resolve, 500));

        // Show final success message
        setSavedMessage(data.message || 'Settings saved successfully!');

        setTimeout(() => {
          setSavedMessage('');
        }, 2000);
      } else {
        throw new Error(data.message || 'Failed to save settings');
      }
    } catch (error) {
      console.error('Error saving settings:', error);

      // Check if it's a validation error
      if (error.message.includes('Invalid') || error.message.includes('API key')) {
        setSavedMessage(`Validation failed: ${error.message}`);
      } else {
        setSavedMessage(error.message || 'Error saving settings. Please try again.');
      }
    } finally {
      setSaving(false);
    }
  };

  // Load saved settings when modal opens
  useEffect(() => {
    if (isOpen) {
      loadSavedSettings();
    }
  }, [isOpen]);

  const loadSavedSettings = async () => {
    try {
      // Get settings from the server (including masked API keys)
      const res = await fetch(postanalyzerWP.restUrl + 'get-settings', {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': postanalyzerWP.nonce }
      });

      if (res.ok) {
        const data = await res.json();
        if (data.ai_platform) {
          setSelectedPlatform(data.ai_platform);
        }
        if (data.author_id) {
          setSelectedAuthor(String(data.author_id));
        }

        // Set masked API keys in the form
        // Since the server returns masked keys, we show them as placeholders
        if (data.api_keys_masked) {
          setApiKeys({
            chatgpt: data.api_keys_masked.chatgpt || '',
            gemini: data.api_keys_masked.gemini || '',
            groq: data.api_keys_masked.groq || ''
          });
        }
      }

      // Also load from localStorage for platform and author selection
      const localSettingsStr = localStorage.getItem('postanalyzer_settings');
      if (localSettingsStr) {
        try {
          const localSettings = JSON.parse(localSettingsStr);
          if (localSettings.ai_platform) {
            setSelectedPlatform(localSettings.ai_platform);
          }
          if (localSettings.author_id) {
            setSelectedAuthor(String(localSettings.author_id));
          }
        } catch (e) {
          console.error('Error parsing local settings:', e);
        }
      }

      setSavedMessage('');
    } catch (error) {
      console.error('Error loading settings:', error);

      // Fallback to localStorage only for platform and author
      const localSettingsStr = localStorage.getItem('postanalyzer_settings');
      if (localSettingsStr) {
        try {
          const localSettings = JSON.parse(localSettingsStr);
          if (localSettings.ai_platform) {
            setSelectedPlatform(localSettings.ai_platform);
          }
          if (localSettings.author_id) {
            setSelectedAuthor(String(localSettings.author_id));
          }
        } catch (e) {
          console.error('Error parsing local settings:', e);
        }
      }
    }
  };

  const handleApiKeyChange = (value) => {
    setApiKeys(prev => ({
      ...prev,
      [selectedPlatform]: value
    }));
  };

  if (!isOpen) return null;

  return (
    <div className="settings-modal-overlay">
      <div className="settings-modal-container">
        {/* Close button */}
        <button onClick={onClose} className="settings-modal-close" aria-label="Close settings">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M18 6L6 18M6 6l12 12" />
          </svg>
        </button>

        <h2 className="settings-modal-title">PostAnalyzer Settings</h2>

        {/* AI Platform Selection */}
        <div className="settings-form-group">
          <label className="settings-form-label">Select AI Platform</label>
          <div className="settings-radio-group">
            {Object.entries(platforms).map(([key, platform]) => (
              <label key={key} className="settings-radio-label">
                <input
                  type="radio"
                  name="ai-platform"
                  value={key}
                  checked={selectedPlatform === key}
                  onChange={(e) => {
                    setSelectedPlatform(e.target.value);
                    setShowApiKeyHelp(false); // Reset help when platform changes
                  }}
                  className="settings-radio-input"
                />
                <span className="settings-radio-text">{platform.label}</span>
              </label>
            ))}
          </div>
        </div>

        {/* API Key Field */}
        <div className="settings-form-group">
          <label htmlFor={`${selectedPlatform}-api-key`} className="settings-form-label">
            {platforms[selectedPlatform].label} API Key
          </label>
          <div className="settings-input-wrapper">
            <input
              type="text"
              id={`${selectedPlatform}-api-key`}
              value={apiKeys[selectedPlatform]}
              onChange={(e) => handleApiKeyChange(e.target.value)}
              placeholder={`Enter your ${platforms[selectedPlatform].label} API key`}
              className="settings-form-input"
            />
            <button
              type="button"
              onClick={() => setShowApiKeyHelp(!showApiKeyHelp)}
              className="settings-help-button"
              aria-label="How to get API key"
            >
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
              </svg>
            </button>
          </div>

          {/* API Key Help Popover */}
          {showApiKeyHelp && (
            <div className="settings-help-popover">
              <h4 className="settings-help-title">How to get {platforms[selectedPlatform].label} API Key:</h4>
              <ol className="settings-help-list">
                {platforms[selectedPlatform].helpSteps.map((step, index) => (
                  <li key={index}>
                    {index === 0 ? (
                      <>Visit <a href={platforms[selectedPlatform].helpUrl} target="_blank" rel="noopener noreferrer" className="settings-help-link">{step.replace('Visit ', '')}</a></>
                    ) : (
                      step
                    )}
                  </li>
                ))}
              </ol>
              <button onClick={() => setShowApiKeyHelp(false)} className="settings-help-close">Close help</button>
            </div>
          )}
          {apiKeys[selectedPlatform] && apiKeys[selectedPlatform].includes('*') && (
            <p className="settings-help-text">
              API key is saved. Enter a new key to update it.
            </p>
          )}
        </div>

        {/* Author Select Field */}
        <div className="settings-form-group-last">
          <label htmlFor="author-validate" className="settings-form-label">
            Author to Validate
          </label>
          <select
            ref={authorSelectRef}
            id="author-validate"
            value={selectedAuthor}
            onChange={(e) => setSelectedAuthor(e.target.value)}
            className="settings-form-select small hidden"
            aria-label="Select author to validate"
          >
            <option disabled>Select an author...</option>
            {users.map(user => (
              <option key={user.id} value={user.id}>
                {user.name} ({user.email})
              </option>
            ))}
          </select>
        </div>

        {/* Save Message */}
        {savedMessage && (
          <div className={`settings-message ${savedMessage.includes('success') || savedMessage.includes('saved')
            ? 'settings-message-success'
            : savedMessage.includes('Validating') || savedMessage.includes('Saving')
              ? 'settings-message-info'
              : 'settings-message-error'
            }`}>
            {savedMessage}
            {(savedMessage.includes('Validating') || savedMessage.includes('Saving')) && (
              <span className="settings-message-spinner">
                <svg className="settings-spinner-icon" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none"></circle>
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
              </span>
            )}
          </div>
        )}

        {/* Action Buttons */}
        <div className="settings-actions">
          <button onClick={handleSave} disabled={saving} className="settings-button-save">
            {saving ? 'Saving...' : 'Save Settings'}
          </button>
          <button onClick={onClose} className="settings-button-cancel">Cancel</button>
        </div>
      </div>
    </div>
  );
}

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
    <div className={`postanalyzer-wrapper ${showSettings ? 'modal-open' : ''}`} ref={fancyboxRootRef}>
      <div className="flex items-center justify-between mb-4">
        <div>
          <h1 className="head-title">PostAnalyzer</h1>
        </div>
        {postanalyzerWP.user_level === 'admin' && (
          <div>
            <button onClick={handleSettingsClick} className="settings-button" title="Settings" aria-label="Open Settings" disabled={loadingPosts}>
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            </button>
          </div>
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
          <div className="flex items-center gap-3 mb-2 max-w-3xl">
            <select ref={selectRef} value={postId} onChange={(e) => { setPostId(e.target.value); if (error) setError(null); }} className="small border p-2 rounded flex-1 !text-base px-4 py-1 hidden max-w-[600px]" aria-label="Select post to analyze" name="postanalyzer-post" id="postanalyzer-post" disabled={loading} >
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

            <button onClick={analyzePost} disabled={isButtonDisabled} className="bg-blue-600 hover:not-disabled:bg-blue-500 text-white px-4 py-2 text-base rounded cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed max-w-[200px] min-w-[130px]">{loading ? 'Analyzing...' : 'Analyze Post'}</button>

            <button onClick={handleReset} className={`${loading ? 'bg-red-600 hover:bg-red-500 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800'} px-4 py-2 text-base rounded cursor-pointer max-w-[100px] min-w-[80px] transition-colors`}
            >{loading ? 'Abort' : 'Reset'}</button>
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
            <div className="mt-4 result-container">
              <div className="flex justify-between items-start mb-3">
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
                <h3 className="card-title">Attached Images ({report.attached_images?.length || 0})</h3>
                {(!report.attached_images || report.attached_images.length === 0) && <div className="text-sm text-gray-600">No attached images found.</div>}
                {report.attached_images && report.attached_images.length > 0 && (
                  <div className="attached-images">
                    {report.attached_images?.map((img, i) => (
                      <ImageMeta key={i} img={img} featured={false} />
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