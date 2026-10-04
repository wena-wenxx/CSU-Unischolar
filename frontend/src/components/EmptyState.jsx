export default function EmptyState({ message, action }) {
  return (
    <div className="empty-state">
      <p>{message}</p>

      {action}
    </div>
  );
}
