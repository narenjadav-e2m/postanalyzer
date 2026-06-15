import ImageMeta from '../ImageMeta';

export default function FeaturedImage({ featured_image, postId, onSaved }) {
  if (!featured_image) return null;

  return (
    <section className="card card-full">
      <h3 className="card-title">Featured Image</h3>
      <ImageMeta img={featured_image} featured postId={postId} onSaved={onSaved} />
    </section>
  );
}
