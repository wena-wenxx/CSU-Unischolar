import { useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { ROLE_LABELS, useAuth } from "../lib/auth";
import { courseShort } from "../lib/format";
import Avatar from "./Avatar";

/*
  Who is logged in, at the top of the sidebar, laid out like the CSU MySchool
  portal: a big photo (or a plain person shape), the name in capitals, then
  "Student ID | Course - Year" (students) or the role (staff, admin). Click it for View Profile / Help /
  Sign Out. The photo is uploaded on My Profile.
*/

export default function SidebarProfile() {
  const { user, logout } = useAuth();
  const location = useLocation();
  const boxRef = useRef(null);
  const [openOn, setOpenOn] = useState(null); // closes by itself on another page
  const open = openOn === location.pathname;

  useEffect(() => {
    if (!open) return undefined;
    function onClick(event) {
      if (boxRef.current && !boxRef.current.contains(event.target)) setOpenOn(null);
    }
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, [open]);

  const student = user.student;
  const middle = student?.middle_name ? ` ${student.middle_name.charAt(0)}.` : "";
  const name = student ? `${student.first_name}${middle} ${student.last_name}` : user.name;
  const year = student?.year_level ? String(student.year_level).match(/\d/)?.[0] : "";
  const course = student ? [courseShort(student.course), year].filter(Boolean).join(" - ") : "";
  // Like MySchool: "221-00462 | BSIT - 4" under the name.
  const detail = student ? [student.student_id, course].filter(Boolean).join(" | ") : ROLE_LABELS[user.role] || user.role;

  return (
    <div className="sidebar-profile" ref={boxRef}>
      <button
        type="button"
        className="profile-trigger"
        onClick={() => setOpenOn(open ? null : location.pathname)}
        aria-expanded={open}
        aria-controls="profile-menu"
      >
        <Avatar user={user} name={name} silhouette />
        <span className="profile-text">
          <strong title={name}>{name}</strong>
          <small className="profile-role">{detail}</small>
        </span>
        <span className="profile-caret" aria-hidden="true">
          ▾
        </span>
      </button>

      {open && (
        <div className="profile-menu" id="profile-menu" role="menu">
          <Link role="menuitem" to={`/${user.role}/profile`}>
            View Profile
          </Link>
          <Link role="menuitem" to={`/${user.role}/help`}>
            Help
          </Link>
          <button role="menuitem" type="button" onClick={logout}>
            Sign Out
          </button>
        </div>
      )}
    </div>
  );
}
