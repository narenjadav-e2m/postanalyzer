import MetaRow from './../MetaRow';
import ImageMeta from './../ImageMeta';

export default function BasicInfo({ title, report }) {
    if (!report) return null;

    return (
        <>
            <section className="card">
                <h3 className="card-title">{title}</h3>

                <MetaRow label="Title" value={report.title} />
                <MetaRow label="URL" value={`<a href="${report.url}" target="_blank" rel="noopener noreferrer">${report.url}</a>`} isHtml />
                <MetaRow label="Author" value={report.author} />
                <MetaRow label="Published Date" value={report.published_date} />
                <MetaRow label="Modified Date" value={report.updated_date} />
                <MetaRow label="Categories" value={report.categories} isHtml />
                <MetaRow label="Tags" value={report.tags} isHtml />
                <MetaRow label="Word Count" value={report.word_count} />

                {title === 'Featured Image' && (
                    report.featured_image ? <ImageMeta img={report.featured_image} featured /> : <div className="text-sm text-gray-600">No featured image set.</div>
                )}

                {/* …you can branch on the title or create a more generic API… */}
            </section>
        </>
    );
}