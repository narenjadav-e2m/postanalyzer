import ImageMeta from './../ImageMeta';

export default function FeaturedImage({ title, featured_image }) {
    if (!featured_image) return null;

    return (
        <>
            <section className="card">
                <h3 className="card-title">{title}</h3>
                {featured_image && <ImageMeta img={featured_image} featured={true} />}
                {!featured_image && <div className="text-sm text-gray-600">No featured image set.</div>}
            </section>
        </>
    );
}