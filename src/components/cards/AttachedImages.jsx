import ImageMeta from '../ImageMeta';

export default function AttachedImages({ attached_images, postId, onSaved }) {
  const images = attached_images
    ? (Array.isArray(attached_images) ? attached_images : Object.values(attached_images))
    : [];

  const missingAlt = images.filter(img => !img.alt).length;

  return (
    <section className="card card-full">
      <div className="card-title-row">
        <h3 className="card-title">Content Images ({images.length})</h3>
        {missingAlt > 0 && (
          <span className="badge badge-warn" title="Images missing alt text">
            {missingAlt} missing alt
          </span>
        )}
      </div>

      {images.length === 0 ? (
        <p className="text-sm text-gray-500">No content images found.</p>
      ) : (
        <div className="attached-images">
          {images.map((img, i) => (
            <ImageMeta key={img.id ?? i} img={img} featured={false} postId={postId} onSaved={onSaved} />
          ))}
        </div>
      )}
    </section>
  );
}
