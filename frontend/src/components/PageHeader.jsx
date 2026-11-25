import { useLocation } from "react-router-dom";
import BackButton from "./BackButton";

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
