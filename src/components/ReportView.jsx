import BasicInfo from './cards/BasicInfo';
import SEOData from './cards/SEOData';
import FeaturedImage from './cards/FeaturedImage';
import AttachedImages from './cards/AttachedImages';
import SuggestionsCard from './cards/SuggestionsCard';

/**
 * Full analysis report layout.
 */
export default function ReportView({ report, postId, onRefresh }) {
  if (!report) return null;

  return (
    <div className="result-container">
      <div className="title-wrapper">
        <h2 className="title">Analysis Report</h2>
        {report.url && (
          <a
            href={report.url}
            className="report-post-link"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="View post"
          >
            View Post ↗
          </a>
        )}
      </div>

      <div className="report-wrapper">

        <BasicInfo report={report} postId={postId} onSaved={onRefresh} />

        <SEOData report={report.seo} postId={postId} onSaved={onRefresh} />

        {report.featured_image
          ? <FeaturedImage featured_image={report.featured_image} postId={postId} onSaved={onRefresh} />
          : (
            <section className="card card-full">
              <h3 className="card-title">Featured Image</h3>
              <p className="text-sm text-amber-600 font-medium">⚠ No featured image set.</p>
            </section>
          )
        }

        <AttachedImages attached_images={report.attached_images} postId={postId} onSaved={onRefresh} />

        <SuggestionsCard
          title="URL & Slug Suggestions"
          items={report.url_suggestions}
          ordered
          emptyMessage="No URL suggestions generated."
          renderItem={url => (
            <a href={url} target="_blank" rel="noopener noreferrer" className="suggestion-link">{url}</a>
          )}
        />

        <SuggestionsCard
          title="AI Suggestions"
          items={report.ai_suggestions}
          emptyMessage="No AI suggestions returned."
          renderItem={(s, i) => (
            <span key={i} dangerouslySetInnerHTML={{ __html: s }} />
          )}
        />
      </div>
    </div>
  );
}
