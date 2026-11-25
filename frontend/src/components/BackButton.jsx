import { Link, useLocation, useNavigate } from "react-router-dom";
import { homePathFor, useAuth } from "../lib/auth";

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
