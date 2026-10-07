import { useAuth } from "../../lib/auth";
import { initials } from "../../components/SidebarProfile";
import PageHeader from "../../components/PageHeader";
import ProfileItem from "../../components/ProfileItem";

/* Staff account details (read-only; accounts are set up by the administrator). */
export default function StaffProfilePage() {
  const { user } = useAuth();

  return (
    <div>
      <PageHeader title="My Profile" subtitle="Your OAS staff account" />

      <section className="card profile-hero">
        <span className="avatar avatar-large" aria-hidden="true">
          {initials(user.name)}
        </span>

        <div className="profile-grid">
          <ProfileItem label="Name" value={user.name} />
          <ProfileItem label="Email (login)" value={user.email} />
          <ProfileItem label="Role" value="OAS Staff" />
          <ProfileItem label="Office" value="Office of Admission and Scholarship" />
        </div>
      </section>

      <p className="muted small">
        Staff accounts are created by the system administrator. To change your name or e-mail, contact the administrator.
      </p>
    </div>
  );
}
