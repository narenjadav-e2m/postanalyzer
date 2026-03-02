import ImageMeta from './../ImageMeta';

export default function AttachedImages({ title, attached_images }) {
    if (!attached_images) return null;

    return (
        <>
            <section className="card">
                <h3 className="card-title">{title} ({Object.keys(attached_images || {}).length})</h3>
                {(!attached_images || attached_images.length === 0) && <div className="text-sm text-gray-600">No attached images found.</div>}
                {attached_images && Object.keys(attached_images).length > 0 && (
                    <>
                        <div className="attached-images">
                            {Object.values(attached_images).map((img, i) => (
                                <ImageMeta key={img.id || i} img={img} featured={false} />
                            ))}
                        </div>
                    </>
                )}
            </section>
        </>
    );
}
