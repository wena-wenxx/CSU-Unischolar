import { useCallback, useEffect, useState } from "react";
import api, { errMsg } from "../../services/api";
import { formatDateTime } from "../../lib/format";
import { useToast } from "../../lib/toast";
import PageHeader from "../../components/PageHeader";
import EmptyState from "../../components/EmptyState";
import Loading from "../../components/Loading";

/*
  Contact OAS (students): the office's hours and contact details, a form to
  send a question, and every message sent with OAS's reply.
*/
const EMPTY = { topic: "", subject: "", message: "" };

const STATUS = {
  open: ["Waiting for OAS", "warning"],
  answered: ["Answered", "success"],
  closed: ["Closed", "neutral"],
};

export default function ContactPage() {
  const toast = useToast();

  const [office, setOffice] = useState(null);
  const [topics, setTopics] = useState({});
  const [messages, setMessages] = useState(null);
  const [form, setForm] = useState(EMPTY);
  const [sending, setSending] = useState(false);

  // .then() (not await) so React's lint rule sees the state is set later.
  const load = useCallback(
    () =>
      Promise.all([api.get("/settings/public"), api.get("/student/messages")])
        .then(([settings, mine]) => {
          setOffice(settings.data);
          setTopics(mine.data.topics);
          setMessages(mine.data.messages);
        })
        .catch((err) => {
          toast.error(errMsg(err, "Unable to load this page."));
          setMessages([]);
        }),
    [toast]
  );

  useEffect(() => {
    load();
  }, [load]);

  async function send(event) {
    event.preventDefault();
    setSending(true);

    try {
      const { data } = await api.post("/student/messages", {
        topic: form.topic,
        subject: form.subject.trim(),
        message: form.message.trim(),
      });
      toast.success(data.message);
      setForm(EMPTY);
      await load();
    } catch (err) {
      toast.error(errMsg(err, "Unable to send the message."));
    } finally {
      setSending(false);
    }
  }

  if (!messages) return <Loading />;

  const field = (key) => (event) => setForm({ ...form, [key]: event.target.value });

  return (
    <div>
      <PageHeader title="Contact OAS" subtitle="Ask the Office of Admission and Scholarship a question" />

      <div className="dashboard-grid">
        <section className="card contact-card">
          <h2>Office of Admission and Scholarship</h2>
          <dl className="contact-list">
            {office?.oas_location && (
              <div>
                <dt>📍 Location</dt>
                <dd>{office.oas_location}</dd>
              </div>
            )}
            {office?.oas_office_hours && (
              <div>
                <dt>🕘 Office hours</dt>
                <dd>{office.oas_office_hours}</dd>
              </div>
            )}
            {office?.oas_email && (
              <div>
                <dt>✉️ E-mail</dt>
                <dd>
                  <a href={`mailto:${office.oas_email}`}>{office.oas_email}</a>
                </dd>
              </div>
            )}
            {office?.oas_phone && (
              <div>
                <dt>📞 Phone</dt>
                <dd>
                  <a href={`tel:${office.oas_phone.replace(/[^\d+]/g, "")}`}>{office.oas_phone}</a>
                </dd>
              </div>
            )}
          </dl>
          <p className="muted small">
            For questions about your own application, you can also open it from My Applications and read OAS's
            remarks there.
          </p>
        </section>

        <section className="card">
          <h2>Send a message</h2>
          <form onSubmit={send}>
            <label htmlFor="msg-topic">Topic</label>
            <select id="msg-topic" value={form.topic} onChange={field("topic")} required>
              <option value="">Choose a topic</option>
              {Object.entries(topics).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </select>

            <label htmlFor="msg-subject">Subject</label>
            <input
              id="msg-subject"
              value={form.subject}
              onChange={field("subject")}
              maxLength={150}
              required
              placeholder="e.g. My barangay clearance is not ready yet"
            />

            <label htmlFor="msg-body">Message</label>
            <textarea id="msg-body" value={form.message} onChange={field("message")} maxLength={3000} required rows={5} />
            <p className="muted small">{form.message.length} / 3000 · OAS replies here and your bell shows it.</p>

            <button className="button button-primary" disabled={sending}>
              {sending ? "Sending..." : "Send to OAS"}
            </button>
          </form>
        </section>
      </div>

      <section className="card">
        <h2>My messages</h2>

        {messages.length === 0 ? (
          <EmptyState message="You have not sent any messages yet." />
        ) : (
          <ul className="message-list">
            {messages.map((item) => {
              const [label, tone] = STATUS[item.status] || STATUS.open;
              return (
                <li key={item.id} className="message-item">
                  <div className="message-head">
                    <strong>{item.subject}</strong>
                    <span className={`status status-${tone}`}>{label}</span>
                  </div>
                  <small className="muted">
                    {topics[item.topic] || item.topic} · sent {formatDateTime(item.created_at)}
                  </small>
                  <p className="message-body">{item.message}</p>

                  {item.reply && (
                    <div className="message-reply">
                      <strong>OAS replied</strong>
                      <small className="muted">
                        {" "}
                        · {item.replier?.name || "OAS"} · {formatDateTime(item.replied_at)}
                      </small>
                      <p>{item.reply}</p>
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </section>
    </div>
  );
}
