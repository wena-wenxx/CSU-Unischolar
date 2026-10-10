import { announcementImage } from "../lib/format";
import { LOGO } from "../lib/brand";

/* The announcement's picture, or a CSU placeholder when it has none. */
export default function AnnouncementImage({ item, className = "" }) {
  const src = announcementImage(item);

  if (!src) {
    return (
      <div className={`announcement-image placeholder ${className}`} aria-hidden="true">
        <img src={LOGO} alt="" />
      </div>
    );
  }

  return <img className={`announcement-image ${className}`} src={src} alt="" loading="lazy" />;
}
