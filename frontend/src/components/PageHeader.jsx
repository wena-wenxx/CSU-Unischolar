import { useLocation } from "react-router-dom";
import BackButton from "./BackButton";

/*
  Page title row.
    back={false}                         no Back link (dashboards)
    back                                 "← Back" to the previous page (default)
    back={{ to: "/x", label: "All x" }}  "← All x" to a fixed page
  Pages opened from the menu (/staff/payroll, /student/profile, ...) do not
  show the default Back link: the menu is already there. Detail pages
  (/student/applications/12) keep it.
*/
const MENU_PAGE = /^\/(student|staff|admin)\/[a-z-]+\/?$/;

export default function PageHeader({ title, subtitle, actions, back = true }) {
  const { pathname } = useLocation();
  const showBack = typeof back === "object" || (back === true && !MENU_PAGE.test(pathname));

  return (
    <div className="page-header-wrap">
      {showBack && <BackButton {...(typeof back === "object" ? back : {})} />}

      <div className="page-header">
        <div>
          <h1>{title}</h1>

          {subtitle && <div className="muted page-subtitle">{subtitle}</div>}
        </div>

        {actions && <div className="button-row">{actions}</div>}
      </div>
    </div>
  );
}
