import { useEffect, useRef, useState } from 'react';

import NiceSelect from 'nice-select2';
import 'nice-select2/dist/css/nice-select2.css';

export default function SettingsModal({ isOpen, onClose, users = [] }) {
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

                <h2 className="settings-modal-title">Post Analyzer Settings</h2>

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