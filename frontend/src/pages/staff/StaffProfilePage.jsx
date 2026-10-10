import { Link } from "react-router-dom";
import { ROLE_LABELS, useAuth } from "../../lib/auth";
import { formatDateTime, initials } from "../../lib/format";
import PageHeader from "../../components/PageHeader";
import ProfileItem from "../../components/ProfileItem";

/* Staff and admin account details. Name and e-mail are changed by the System Admin. */
export default function StaffProfilePage() {
  const { user } = useAuth();
  const admin = user.role === "admin";

  return (
    <div>
      <PageHeader title="My Profile" subtitle={admin ? "Your administrator account" : "Your OAS staff account"} />

      <section className="card profile-hero">
        <span className="avatar avatar-large" aria-hidden="true">
          {initials(user.name)}
        </span>

        <div className="profile-grid">
          <ProfileItem label="Name" value={user.name} />
          <ProfileItem label="Email (login)" value={user.email} />
          <ProfileItem label="Role" value={ROLE_LABELS[user.role]} />
          <ProfileItem label="Office" value="Office of Admission and Scholarship" />
          {user.last_login_at && <ProfileItem label="Last login" value={formatDateTime(user.last_login_at)} />}
        </div>
      </section>

      <section className="card">
        <div className="card-header">
          <h2>Password</h2>
          <Link className="button button-secondary" to="/account/password">
            Change password
          </Link>
        </div>
        <p className="muted small">Use at least 8 characters with letters and numbers. Do not share it with anyone.</p>
      </section>

      <p className="muted small">
        {admin
          ? "To change your own name or e-mail, use Manage Staff."
          : "Accounts are created by the System Admin. To change your name or e-mail, contact the administrator."}
      </p>
    </div>
  );
}
