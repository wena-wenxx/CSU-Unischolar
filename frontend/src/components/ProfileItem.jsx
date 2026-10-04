// One "label / value" box, used in profile and detail grids.
export default function ProfileItem({ label, value }) {
  return (
    <div className="profile-item">
      <span>{label}</span>

      <strong>{value || "—"}</strong>
    </div>
  );
}
