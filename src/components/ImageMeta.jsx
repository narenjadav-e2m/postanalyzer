import MetaRow from './MetaRow';

function formatBytes(bytes) {
  if (!bytes) return null;
  if (bytes < 1024)        return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

export default function ImageMeta({ img, featured = false, postId, onSaved }) {
  if (!img) return null;

  const missingAlt = !img.alt;
  // alt / title are editable only for media-library images (have an attachment id).
  const editable = !!img.id;
  const disabledReason = 'External images are not in the media library and cannot be edited here.';

  return (
    <div className={featured ? 'featured-img' : 'attached-img'}>

      {/* ── Thumbnail ── */}
      <a
        className="img-thumb-link"
        href={img.src}
        data-fancybox={featured ? 'featured' : 'attached'}
        data-caption={img.caption || img.title || ''}
      >
        {img.src && (
          <img
            src={img.src}
            alt={img.alt || ''}
            className="card-img"
            loading="lazy"
            decoding="async"
          />
        )}
        {img.type === 'external' && (
          <span className="img-badge external">External</span>
        )}
        {missingAlt && (
          <span className="img-badge missing-alt" title="Missing alt text">No Alt</span>
        )}
      </a>

      {/* ── Metadata ── */}
      <div className="img-meta">
        <MetaRow label="Title" value={img.title}
          edit={{ field: 'image_title', postId, attachmentId: img.id, value: img.title ?? '', label: 'Image Title', disabled: !editable, disabledReason, onSaved }} />
        <MetaRow label="Alt" value={img.alt}
          edit={{ field: 'alt', postId, attachmentId: img.id, value: img.alt ?? '', label: 'Alt Text', multiline: true, disabled: !editable, disabledReason, onSaved }} />
        <MetaRow label="Caption"    value={img.caption} />
        <MetaRow label="Caption"    value={img.caption} />
        <MetaRow label="Filename"   value={img.filename} />
        <MetaRow label="Dimensions" value={img.width && img.height ? `${img.width} × ${img.height}` : null} />
        <MetaRow label="File Size"  value={formatBytes(img.file_size)} />
        <MetaRow label="MIME Type"  value={img.mime_type} />
        <MetaRow label="Source"     value={img.type} />
      </div>
    </div>
  );
}
