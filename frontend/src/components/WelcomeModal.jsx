import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../lib/auth";
import { WELCOME_EVENT, WELCOME_STEPS } from "../lib/help";
import Modal from "./Modal";

/*
  Five short steps shown the first time someone logs in on this browser.
  It is remembered (per user) as soon as it appears, so it shows only once,
  even if the person moves to another page without closing it.
  The Help page has a button that shows it again.
*/
const key = (user) => `scholarguide-welcome-seen-${user.id}`;

function markSeen(user) {
  try {
    window.localStorage.setItem(key(user), "yes");
  } catch {
    // Private browsing: it will simply show again next time.
  }
}

function alreadySeen(user) {
  try {
    return window.localStorage.getItem(key(user)) === "yes";
  } catch {
    return false;
  }
}

export default function WelcomeModal() {
  const { user } = useAuth();
  const [open, setOpen] = useState(() => !alreadySeen(user));
  const [step, setStep] = useState(0);

  useEffect(() => {
    if (open) markSeen(user);
  }, [open, user]);

  useEffect(() => {
    function show() {
      setStep(0);
      setOpen(true);
    }

    window.addEventListener(WELCOME_EVENT, show);
    return () => window.removeEventListener(WELCOME_EVENT, show);
  }, []);

  if (!open) return null;

  const steps = WELCOME_STEPS[user.role] || [];
  const last = step === steps.length - 1;

  function close() {
    markSeen(user);
    setOpen(false);
  }

  return (
    <Modal
      title={step === 0 ? `Welcome to ScholarGuide` : "Quick guide"}
      subtitle={<p className="muted">Step {step + 1} of {steps.length}</p>}
      onClose={close}
      footer={
        <>
          <Link className="button button-secondary" to={`/${user.role}/help`} onClick={close}>
            Video guide &amp; Help
          </Link>

          {step > 0 && (
            <button className="button button-secondary" onClick={() => setStep(step - 1)}>
              Back
            </button>
          )}

          <button className="button button-primary" onClick={() => (last ? close() : setStep(step + 1))} autoFocus>
            {last ? "Got it" : "Next"}
          </button>
        </>
      }
    >
      <div className="welcome-step">
        <span className="welcome-number">{step + 1}</span>

        <div>
          <h3>{steps[step].title}</h3>
          <p>{steps[step].text}</p>
        </div>
      </div>

      <div className="welcome-dots" aria-hidden="true">
        {steps.map((item, index) => (
          <span key={item.title} className={index === step ? "active" : ""} />
        ))}
      </div>
    </Modal>
  );
}
