import { Link, useLocation, useNavigate } from "react-router-dom";
import { homePathFor, useAuth } from "../lib/auth";

/*
  "← Back" link shown at the top of a page.

    <BackButton />                         -> previous page (like the browser Back button)
    <BackButton to="/student/scholarships" label="All scholarships" />  -> a fixed page

  If the page was opened directly (typed address, refresh, new tab) there
  is no previous page inside the system, so it goes to the dashboard.
*/
export default function BackButton({ to, label = "Back" }) {
  const navigate = useNavigate();
  const location = useLocation();
  const { user } = useAuth();

  if (to) {
    return (
      <Link className="back-link" to={to}>
        ← {label}
      </Link>
    );
  }

  const hasHistory = location.key !== "default";

  return (
    <button
      type="button"
      className="back-link"
      onClick={() => (hasHistory ? navigate(-1) : navigate(homePathFor(user)))}
    >
      ← {label}
    </button>
  );
}
