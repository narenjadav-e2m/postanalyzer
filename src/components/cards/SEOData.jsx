import MetaRow from '../MetaRow';

const ScoreBadge = ({ score }) => {
  if (score === undefined || score === null) return null;
  const color = score >= 80 ? 'score-good' : score >= 50 ? 'score-ok' : 'score-bad';
  return <span className={`seo-score ${color}`}>{score}/100</span>;
};

export default function SEOData({ report, postId, onSaved }) {
  if (!report) return null;

  return (
    <section className="card">
      <div className="card-title-row">
        <h3 className="card-title">SEO Data</h3>
        <ScoreBadge score={report.score} />
      </div>

      <MetaRow label="SEO Title" value={report.title}
        edit={{ field: 'seo_title', postId, value: report.title, label: 'SEO Title', onSaved }} />
      <MetaRow label="Meta Description" value={report.description}
        edit={{ field: 'seo_description', postId, value: report.description, label: 'Meta Description', multiline: true, onSaved }} />
      <MetaRow label="Keywords" value={report.keywords}
        edit={{ field: 'focus_keyword', postId, value: report.focus_keyword ?? '', label: 'Focus Keyword', onSaved }} />
      <MetaRow label="Noindex"          value={report.is_noindex} />
      <MetaRow label="Nofollow"         value={report.is_nofollow} />

      {report.issues?.length > 0 && (
        <div className="seo-issues">
          <strong className="seo-issues-label">Issues ({report.issues.length})</strong>
          <ul className="seo-issues-list">
            {report.issues.map((issue, i) => (
              <li key={i} className="seo-issue-item">
                <span className="issue-icon" aria-hidden="true">⚠</span>
                {issue}
              </li>
            ))}
          </ul>
        </div>
      )}

      {report.issues?.length === 0 && (
        <p className="text-sm text-green-600 font-medium mt-2">✓ No SEO issues detected.</p>
      )}
    </section>
  );
}
