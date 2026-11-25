export default function ProfileItem({ label, value }) {
  return (
    <div className="profile-item">
      <span>{label}</span>

      <strong>{value || "—"}</strong>
    </div>
  );
}
