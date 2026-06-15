import MetaRow from '../MetaRow';

export default function BasicInfo({ report, postId, onSaved }) {
  if (!report) return null;

  return (
    <section className="card">
      <h3 className="card-title">Basic Post Info</h3>
      <MetaRow label="Title" value={report.title}
        edit={{ field: 'post_title', postId, value: report.title, label: 'Post Title', onSaved }} />
      <MetaRow label="URL"            value={report.url ? `<a href="${report.url}" target="_blank" rel="noopener noreferrer">${report.url}</a>` : null} isHtml />
      <MetaRow label="Slug" value={report.slug}
        edit={{ field: 'slug', postId, value: report.slug, label: 'Slug', onSaved }} />
      <MetaRow label="Excerpt" value={report.excerpt}
        edit={{ field: 'post_excerpt', postId, value: report.excerpt, label: 'Excerpt', multiline: true, onSaved }} />
      <MetaRow label="Author"         value={report.author} />
      <MetaRow label="Post Type"      value={report.post_type} />
      <MetaRow label="Status"         value={report.post_status} />
      <MetaRow label="Published"      value={report.published_date} />
      <MetaRow label="Last Modified"  value={report.updated_date} />
      <MetaRow label="Categories"     value={report.categories} isHtml />
      <MetaRow label="Tags"           value={report.tags} isHtml />
      <MetaRow label="Word Count"     value={report.word_count ? `${report.word_count.toLocaleString()} words` : null} />
    </section>
  );
}
