import { useRef, useState } from "react";
import { TUTORIALS, TUTORIALS_FOR } from "../lib/tutorials";

const clock = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`;

/*
  Video guides on the Help page. The video does not start by itself: the person presses Play.
  Each chapter button jumps to that part. No sound: the steps are written in the video.
*/
export default function TutorialVideos({ role }) {
  const keys = TUTORIALS_FOR[role] || [];
  const [active, setActive] = useState(keys[0]);
  const [now, setNow] = useState(0);
  const video = useRef(null);

  if (!keys.length) return null;
  const guide = TUTORIALS[active];

  function jump(seconds) {
    const v = video.current;
    if (!v) return;
    v.currentTime = seconds;
    v.play().catch(() => {});
  }

  function choose(key) {
    setActive(key);
    setNow(0);
  }

  const current = [...guide.chapters].reverse().find((c) => now >= c.t);

  return (
    <section className="card tutorial-card" aria-labelledby="tutorial-title">
      <div className="card-header">
        <h2 id="tutorial-title">Video guide</h2>
        {keys.length > 1 && (
          <div className="tutorial-tabs" role="tablist" aria-label="Choose a video">
            {keys.map((key) => (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={active === key}
                className={`chip${active === key ? " active" : ""}`}
                onClick={() => choose(key)}
              >
                {TUTORIALS[key].title}
              </button>
            ))}
          </div>
        )}
      </div>
      <p className="muted small">
        {guide.description} {guide.length} · no sound: the steps are written on the screen. Press a chapter to jump to it.
      </p>

      <div className="tutorial-body">
        <video
          key={guide.src}
          ref={video}
          className="tutorial-video"
          poster={guide.poster}
          controls
          preload="metadata"
          playsInline
          onTimeUpdate={(event) => setNow(Math.floor(event.currentTarget.currentTime))}
        >
          {/* WebM for Chrome, Edge and Firefox; MP4 for Safari and older browsers. */}
          <source src={guide.src.replace(".mp4", ".webm")} type="video/webm" />
          <source src={guide.src} type="video/mp4" />
          Your browser cannot play this video.
        </video>

        <ol className="tutorial-chapters" aria-label="Chapters">
          {guide.chapters.map((chapter) => (
            <li key={chapter.t}>
              <button
                type="button"
                className={current === chapter ? "active" : ""}
                aria-current={current === chapter ? "step" : undefined}
                onClick={() => jump(chapter.t)}
              >
                <span className="tutorial-time">{clock(chapter.t)}</span>
                {chapter.title}
              </button>
            </li>
          ))}
        </ol>
      </div>
      <p className="muted small">The people and programs in the video are fictional demo data.</p>
    </section>
  );
}
