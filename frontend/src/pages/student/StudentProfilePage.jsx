import { useEffect, useState } from "react";
import api from "../../services/api";
import PageHeader from "../../components/PageHeader";
import ProfileItem from "../../components/ProfileItem";
import Loading from "../../components/Loading";

export default function StudentProfilePage() {
  const [profile, setProfile] = useState(null);

  useEffect(() => {
    api
      .get("/profile")
      .then((response) => setProfile(response.data))
      .catch(() => {});
  }, []);

  const student = profile?.student;

  return (
    <div>
      <PageHeader title="My Profile" subtitle="Your student information" />

      <div className="card">
        {!student ? (
          <Loading />
        ) : (
          <div className="profile-grid">
            <ProfileItem label="Student ID" value={student.student_id} />
            <ProfileItem label="First Name" value={student.first_name} />
            <ProfileItem label="Middle Name" value={student.middle_name} />
            <ProfileItem label="Last Name" value={student.last_name} />
            <ProfileItem label="Course" value={student.course} />
            <ProfileItem label="Year Level" value={student.year_level} />
            <ProfileItem label="College" value={student.college} />
            <ProfileItem label="Contact Number" value={student.contact_number} />
            <ProfileItem label="Email" value={profile.email} />
          </div>
        )}
      </div>
    </div>
  );
}
