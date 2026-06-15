export default function ErrorBanner({ message, onDismiss }) {
  if (!message) return null;
  return (
    <div className="error-banner" role="alert" aria-live="assertive">
      <span>{message}</span>
      {onDismiss && (
        <button onClick={onDismiss} className="error-dismiss" aria-label="Dismiss error">✕</button>
      )}
    </div>
  );
}
