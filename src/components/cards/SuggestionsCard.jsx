/**
 * Generic ordered/unordered suggestions card.
 * Used for both URL suggestions and AI suggestions.
 */
export default function SuggestionsCard({
  title,
  items = [],
  ordered = false,
  emptyMessage = 'No suggestions.',
  renderItem,
}) {
  const List = ordered ? 'ol' : 'ul';
  const listClass = ordered ? 'suggestions-list ordered' : 'suggestions-list';

  return (
    <section className="card">
      <h3 className="card-title">{title}</h3>
      {items && items.length > 0 ? (
        <List className={listClass}>
          {items.map((item, i) => (
            <li key={i} className="suggestion-item">
              {renderItem ? renderItem(item, i) : item}
            </li>
          ))}
        </List>
      ) : (
        <p className="text-sm text-gray-500">{emptyMessage}</p>
      )}
    </section>
  );
}
