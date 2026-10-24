import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import AnnouncementImage from "./AnnouncementImage";
import { formatDate, preview } from "../lib/format";

/*
  Latest announcements, one at a time (round 12). It moves to the next one
  every 7 seconds; it stops while the mouse or keyboard focus is on it, and
  never moves by itself for people who turned on "reduce motion".
  Arrows and dots let the reader choose.
*/
const DELAY = 7000;

export default function AnnouncementCarousel({ items, basePath }) {
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const count = items.length;
  const current = index % Math.max(count, 1);

  useEffect(() => {
    const reduce = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
    if (count < 2 || paused || reduce) return undefined;
    const timer = setTimeout(() => setIndex((i) => (i + 1) % count), DELAY);
    return () => clearTimeout(timer);
  }, [current, count, paused]);

  if (!count) return null;
  const go = (i) => setIndex((i + count) % count);

  return (
    <div
      className="carousel"
      aria-roledescription="carousel"
      aria-label="Latest announcements"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
    >
      <div className="carousel-track" style={{ transform: `translateX(-${current * 100}%)` }}>
        {items.map((item, i) => (
          <Link
            key={item.id}
            className="carousel-slide"
            to={`${basePath}/${item.id}`}
            aria-roledescription="slide"
            aria-label={`${i + 1} of ${count}: ${item.title}`}
            aria-hidden={i !== current}
            tabIndex={i === current ? 0 : -1}
          >
            <AnnouncementImage item={item} className="carousel-image" />
            <div className="carousel-text">
              <small className="muted">{formatDate(item.posted_at)}</small>
              <strong>{item.title}</strong>
              <p>{preview(item.body, 220)}</p>
              <span className="read-more">Read more →</span>
            </div>
          </Link>
        ))}
      </div>

      {count > 1 && (
        <div className="carousel-controls">
          <button type="button" className="carousel-arrow" onClick={() => go(current - 1)} aria-label="Previous announcement">
            ‹
          </button>
          <div className="carousel-dots">
            {items.map((item, i) => (
              <button
                key={item.id}
                type="button"
                className={i === current ? "active" : ""}
                onClick={() => go(i)}
                aria-label={`Show announcement ${i + 1}`}
                aria-current={i === current}
              />
            ))}
          </div>
          <button type="button" className="carousel-arrow" onClick={() => go(current + 1)} aria-label="Next announcement">
            ›
          </button>
        </div>
      )}
    </div>
  );
}
