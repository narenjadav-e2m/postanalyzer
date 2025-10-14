import MetaRow from "./MetaRow";

export default function ImageMeta({ img, featured = false }) {
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