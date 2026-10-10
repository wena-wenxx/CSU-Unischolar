import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import api, { errMsg } from "../services/api";
import { useAuth } from "../lib/auth";
import { formatDate } from "../lib/format";
import PageHeader from "../components/PageHeader";
import AnnouncementImage from "../components/AnnouncementImage";
import EmptyState from "../components/EmptyState";
import Loading from "../components/Loading";

/* One announcement: full-size picture and the whole message. */
export default function AnnouncementDetailPage() {
  const { id } = useParams();
  const { user } = useAuth();
  const [item, setItem] = useState(null);
  const [error, setError] = useState("");

  useEffect(() => {
    api
      .get(`/announcements/${id}`)
      .then((response) => setItem(response.data))
      .catch((err) => setError(errMsg(err, "This announcement is no longer available.")));
  }, [id]);

  const back =
    user.role === "student"
      ? { to: "/student/dashboard", label: "Dashboard" }
      : { to: "/staff/announcements", label: "Announcements" };

  if (error) {
    return (
      <div className="card">
        <EmptyState
          message={error.includes("No query results") ? "This announcement is no longer available." : error}
          action={
            <Link className="button button-secondary" to={back.to}>
              Back to {back.label}
            </Link>
          }
        />
      </div>
    );
  }

  if (!item) return <Loading />;

  return (
    <div>
      <PageHeader
        back={back}
        title={item.title}
        subtitle={`Posted ${formatDate(item.posted_at)} by ${item.author?.name || "OAS"}${
          item.expires_at ? ` · shown until ${formatDate(item.expires_at)}` : ""
        }`}
      />

      <article className="card announcement-detail">
        <AnnouncementImage item={item} className="large" />
        <div className="announcement-text">
          {item.body.split(/\n+/).map((paragraph, index) => (
            <p key={index}>{paragraph}</p>
          ))}
        </div>
      </article>
    </div>
  );
}
