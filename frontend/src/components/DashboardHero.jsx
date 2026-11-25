import { CAMPUS_PHOTO, OFFICE, TAGLINE, UNIVERSITY } from "../lib/brand";

export default function DashboardHero({ title, subtitle }) {
  const today = new Date().toLocaleDateString("en-PH", {
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
  });

  return (
    <section className="dashboard-hero" style={{ backgroundImage: `url(${CAMPUS_PHOTO})` }}>
      <div className="dashboard-hero-text">
        <span className="hero-eyebrow">
          {UNIVERSITY} · {OFFICE}
        </span>
        <h1>{title}</h1>
        {subtitle && <p>{subtitle}</p>}
        <small>
          {today} · <em>{TAGLINE}</em>
        </small>
      </div>
    </section>
  );
}
