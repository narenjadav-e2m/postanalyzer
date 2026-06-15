/**
 * PostAnalyzer – centralized API client
 * All fetch calls go through here for consistent headers, nonce, and error handling.
 */

const base   = () => window.postanalyzerWP?.restUrl ?? '';
const nonce  = () => window.postanalyzerWP?.nonce   ?? '';

const headers = (extra = {}) => ({
  'Content-Type': 'application/json',
  'X-WP-Nonce': nonce(),
  ...extra,
});

async function request(method, path, body, signal) {
  const url = base() + path;
  const opts = {
    method,
    credentials: 'same-origin',
    headers: headers(),
    signal,
  };
  if (body !== undefined) opts.body = JSON.stringify(body);

  const res = await fetch(url, opts);

  if (!res.ok) {
    const text = await res.text().catch(() => '');
    let msg = `HTTP ${res.status}`;
    try { msg = JSON.parse(text)?.message || msg; } catch { msg = text || msg; }
    throw new Error(msg);
  }

  return res.json();
}

export const api = {
  getPosts:        (signal)          => request('GET', 'posts?per_page=0', undefined, signal),
  getUsers:        (signal)          => request('GET', 'users',            undefined, signal),
  getSettings:     (signal)          => request('GET', 'get-settings',     undefined, signal),
  analyzePost:     (post_id, signal) => request('POST','analyze-post', { post_id }, signal),
  saveSettings:    (data)            => request('POST','save-settings', data),
  validateKey:     (platform, api_key) => request('POST','validate-key', { platform, api_key }),
  suggestField:    (payload, signal) => request('POST','suggest-field', payload, signal),
  updateField:     (payload)         => request('POST','update-field', payload),
};
