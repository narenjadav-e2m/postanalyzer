const STEPS = [
  'Gathering post metadata…',
  'Running SEO checks…',
  'Scanning images…',
  'Generating AI suggestions…',
];

import { useEffect, useState } from 'react';

export default function LoadingSpinner() {
  const [step, setStep] = useState(0);

  useEffect(() => {
    const t = setInterval(() => setStep(s => Math.min(s + 1, STEPS.length - 1)), 2500);
    return () => clearInterval(t);
  }, []);

  return (
    <div className="loading-spinner-wrap" role="status" aria-live="polite">
      <svg className="pa-spinner" fill="none" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
        <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
      </svg>
      <p className="loading-title">Analyzing post…</p>
      <p className="loading-text">{STEPS[step]}</p>
      <div className="loading-steps">
        {STEPS.map((s, i) => (
          <div key={i} className={`loading-step${i <= step ? ' active' : ''}`} />
        ))}
      </div>
    </div>
  );
}
