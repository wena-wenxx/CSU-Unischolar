import BackButton from "./BackButton";

/*
  Page title row.
    back={false}                         no Back link (dashboards)
    back                                 "← Back" to the previous page (default)
    back={{ to: "/x", label: "All x" }}  "← All x" to a fixed page
*/
export default function PageHeader({ title, subtitle, actions, back = true }) {
  return (
    <div className="page-header-wrap">
      {back && <BackButton {...(typeof back === "object" ? back : {})} />}

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
