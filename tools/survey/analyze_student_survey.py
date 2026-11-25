import csv
import re
import statistics
import sys
from collections import Counter

AGREE = {"strongly disagree": 1, "disagree": 2, "neutral": 3, "agree": 4, "strongly agree": 5}
IMPORT = {"not important": 1, "slightly important": 2, "moderately important": 3, "important": 4, "very important": 5}
AGREE_WORDS = ["Strongly Disagree", "Disagree", "Neutral", "Agree", "Strongly Agree"]
IMPORT_WORDS = ["Not Important", "Slightly Important", "Moderately Important", "Important", "Very Important"]

GROUPS = [
    ("Perceived Usefulness (PU)", range(40, 46)),
    ("Perceived Ease of Use (PEOU)", range(46, 52)),
    ("System Quality", [52, 53, 57]),
    ("Information Quality", [54, 55]),
    ("Service Quality", [56]),
    ("User Satisfaction", [58]),
    ("Intention to Use", [59]),
]

THEMES = {
    37: [("Knowing which scholarships they qualify for / where to start",
          r"qualif|eligib|which scholarship|available|where to start|how to start|information|asking about"),
         ("Submitting in person / several trips to the office", r"ftf|in person|face|trips|balik|come at the oas|bureaucracy"),
         ("Gathering and preparing the requirements", r"requirement|document|gather|prepar|application form"),
         ("Not knowing the application status", r"status|track|progress"),
         ("Time and deadlines", r"time|deadline|when to submit|short period"),
         ("No difficulty / not applicable", r"^(none|n/?a|not applicable|easy|i dont|i don't think|i did not apply|i dont tried)")],
    38: [("Online application and submission", r"online|system|website|email|soft copy|upload"),
         ("Clear, complete requirements and instructions", r"clear|instruction|requirement|announce|inform|posting"),
         ("Knowing eligibility", r"eligib|qualify|choose"),
         ("Status tracking / updates", r"status|track|monitor|update")],
    60: [("Easy to use / navigate", r"easy|user.?friendly|navigate|beginner|less confusing|readable|convenien"),
         ("Online submission", r"online|submission"),
         ("Knowing eligibility", r"eligib|qualif"),
         ("Tracking", r"track"),
         ("Design / look", r"\bui\b|color|theme|organized|similar"),
         ("All features", r"^(all|everything|tanan)|feature|useful|need")],
    61: [("Nothing to improve", r"^(none|nons|nonw|nothing|n/?a|no more|wala|nonetheless|for me, none|i don't|i dont)"),
         ("Design / easier to find features", r"\bui\b|color|design|hard to find|simpler|unnecessary|overload"),
         ("More guidance and information", r"guidance|instruction|information|schedule"),
         ("Speed / bugs / sign-in", r"lag|bug|sign in|cite|system")],
    62: [("Nothing more / satisfied", r"^(none|nothing|n/?a|no |noo|good|all|it already|i think all|so far|wala|no idea|ill think)"),
         ("FAQ / help", r"faq|beginner"),
         ("Application / grantee tracking", r"track"),
         ("Communication with the office", r"communication|access to student"),
         ("Other school information in one place", r"schedule|grades|announcement")],
}


def interpret(mean, words):
    for limit, word in zip((1.80, 2.60, 3.40, 4.20, 5.00), words):
        if mean <= limit + 1e-9:
            return word
    return words[-1]


def item_stats(rows, col, scale):
    values = [scale[x[col].strip().lower()] for x in rows if x[col].strip().lower() in scale]
    return values, (statistics.mean(values) if values else None)


def short(header):
    m = re.search(r"\[(.*)\]", header)
    return (m.group(1) if m else header).strip()


def theme_counts(rows, col):
    counts = Counter()
    answered = 0
    for x in rows:
        text = x[col].strip().lower()
        if not text:
            continue
        answered += 1
        label = next((name for name, pattern in THEMES[col] if re.search(pattern, text)), "Other")
        counts[label] += 1
    return answered, counts


def main(path):
    with open(path, encoding="utf-8-sig") as f:
        data = list(csv.reader(f))
    head, rows = data[0], data[1:]
    out = print
    out(f"# Student survey results\n\n{len(rows)} responses. Names, e-mails and ID numbers are left out.\n")

    out("## Respondents\n")
    for col, title in ((10, "Scholarship status"), (8, "College"), (13, "Device used most"), (14, "Internet access"),
                       (25, "Where they learn about scholarships"), (26, "Biggest difficulty (one choice)")):
        out(f"**{title}**\n\n| Answer | n | % |\n|---|---:|---:|")
        for answer, n in Counter(x[col].strip() for x in rows if x[col].strip()).most_common():
            out(f"| {answer} | {n} | {100 * n / len(rows):.1f} |")
        out("")

    def table(cols, scale, words, title):
        out(f"## {title}\n\nColumns 1–5: " + ", ".join(f"{i} = {w}" for i, w in enumerate(words, 1)) + ".\n")
        out("| # | Statement | n | 1 | 2 | 3 | 4 | 5 | Mean | Interpretation |")
        out("|---|---|---:|" + "---:|" * len(words) + "---:|---|")
        means = []
        for col in cols:
            values, mean = item_stats(rows, col, scale)
            if mean is None:
                continue
            means.append(mean)
            dist = Counter(values)
            out(f"| {col} | {short(head[col])} | {len(values)} | " + " | ".join(str(dist.get(k, 0)) for k in range(1, 6))
                + f" | {mean:.2f} | {interpret(mean, words)} |")
        out("")
        return means

    table(range(15, 25), AGREE, AGREE_WORDS, "Before the system: experience of applying (agreement)")
    out("Statements 18–20 describe problems (it takes a lot of time, submitting the same documents again, going to "
        "the office in person): a HIGH mean there means the problem is common.\n")
    table(range(27, 37), IMPORT, IMPORT_WORDS, "Importance of each planned feature")

    out("## Evaluation of the student portal (TAM and IS Success)\n")
    out("| Group | Items | Responses | Mean of item means | Interpretation |\n|---|---|---:|---:|---|")
    for name, cols in GROUPS:
        means, n = [], 0
        for col in cols:
            values, mean = item_stats(rows, col, AGREE)
            if mean is not None:
                means.append(mean)
                n = max(n, len(values))
        grand = statistics.mean(means)
        out(f"| {name} | {', '.join(str(c) for c in cols)} | {n} | {grand:.2f} | {interpret(grand, AGREE_WORDS)} |")
    out("")
    table(range(40, 60), AGREE, AGREE_WORDS, "Evaluation items one by one")

    out("## Open-ended answers, grouped into themes\n")
    out("Each answer is counted once, under the first theme its words match (keyword rules in this script). "
        "Read the answers yourself before quoting a theme.\n")
    for col in THEMES:
        answered, counts = theme_counts(rows, col)
        out(f"**{head[col].strip()}** ({answered} answered)\n\n| Theme | n |\n|---|---:|")
        for label, n in counts.most_common():
            out(f"| {label} | {n} |")
        out("")


if __name__ == "__main__":
    main(sys.argv[1])
