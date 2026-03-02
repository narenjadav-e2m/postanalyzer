import MetaRow from './../MetaRow';

export default function SEOData({ title, report }) {
    if (!report) return null;

    return (
        <>

            <section className="card">
                <h3 className="card-title">{title}</h3>
                <MetaRow label="SEO Title" value={report?.title} />
                <MetaRow label="Meta Description" value={report?.description} />
                <MetaRow label="Keywords" value={report?.keywords} />
                {report?.issues?.length > 0 && (
                    <div className="mt-2">
                        <strong>Issues:</strong>
                        <ul className="list-disc ml-5 mt-1">
                            {report.issues.map((it, i) => <li key={i}>{it}</li>)}
                        </ul>
                    </div>
                )}
            </section>
        </>
    );
}