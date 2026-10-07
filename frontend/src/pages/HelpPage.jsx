import { useAuth } from "../lib/auth";
import { HELP } from "../lib/help";
import { STATUS_HELP } from "../lib/format";
import { BANNER, MOTTO, UNIVERSITY } from "../lib/brand";
import { showWelcomeAgain } from "../components/WelcomeModal";
import PageHeader from "../components/PageHeader";
import StatusBadge from "../components/StatusBadge";

/* Step-by-step help for the logged-in role (students and staff see different text). */
export default function HelpPage() {
  const { user } = useAuth();
  const sections = HELP[user.role] || [];

  return (
    <div>
      <PageHeader
        title="Help"
        subtitle={user.role === "staff" ? "How to use ScholarGuide at the OAS" : "How to apply and follow your scholarship"}
        actions={
          <button className="button button-secondary" onClick={showWelcomeAgain}>
            Show the welcome guide again
          </button>
        }
      />

      <figure className="help-banner">
        <img
          src={BANNER}
          alt={`${UNIVERSITY}: Creating futures, empowering communities. Vision, mission and core values: ${MOTTO}`}
        />
      </figure>

      <nav className="card help-contents" aria-label="Help topics">
        <strong>On this page</strong>
        <ol>
          {sections.map((section, index) => (
            <li key={section.title}>
              <a href={`#help-${index}`}>{section.title}</a>
            </li>
          ))}
        </ol>
      </nav>

      {sections.map((section, index) => (
        <section className="card help-section" id={`help-${index}`} key={section.title}>
          <h2>{section.title}</h2>

          {section.statusHelp ? (
            <dl className="status-help">
              {Object.entries(STATUS_HELP).map(([status, info]) => (
                <div key={status}>
                  <dt>
                    <StatusBadge status={status} label={info.title} />
                  </dt>
                  <dd>{info.text}</dd>
                </div>
              ))}
            </dl>
          ) : (
            <ol className="help-steps">
              {section.steps.map((step) => (
                <li key={step}>{step}</li>
              ))}
            </ol>
          )}
        </section>
      ))}

      <p className="muted help-footer">
        Still stuck? Visit or message the Office of Admission and Scholarship. All accounts and records in this
        prototype are fictional.
      </p>
    </div>
  );
}
