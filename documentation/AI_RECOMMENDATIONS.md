# AI Recommendations (rounds 11–12)

ScholarGuide tells OAS staff which students **may be eligible** for each scholarship, with a score from 0% to
100%. This includes students who have **not applied**. It only recommends: staff decide, and the agency still makes
the final decision.

## 1. Which "AI" it is, and why

| Option | Verdict | Why |
|---|---|---|
| A. Rule-based scoring from each scholarship's rules | **Used** | Every score can be explained and checked by hand. It needs no training data. |
| B. Machine-learning classifier trained on past results | Not used | There is no real historical data. The ~300 records in the demo are fictional, so a model would only learn the seeder's random numbers, and any accuracy figure would be made up. |
| C. A combination of both | Not used | The ML half would have the same problem as B. |

In the paper, call it a **rule-based recommender** (a decision-support system), not machine learning.

**Round 12 adds two real AI parts that need no training data** (both pretrained, free, and run on the OAS
computer, so no student data leaves it). The rules above still decide **eligibility**; the AI only **ranks,
explains and suggests**. Nothing is approved by the AI.

| AI part | Model | What it does | Without it |
|---|---|---|---|
| **Field fit** (`ai-service/fit_matcher.py`) | Sentence embeddings, **BAAI/bge-small-en-v1.5** through **fastembed** (pretrained, ~70 MB, CPU) | Compares each student (course, college, year, and their optional *My field and goals*) with each program's description, and tags both with fields of study (Agriculture, Engineering, Computing, …). Programs for the student's field are listed first, and agency-direct programs for their field are shown as *Also for your field*. | TF-IDF word matching (built in). Same fields, rougher similarity. |
| **Memo reader** (`ai-service/rule_reader.py`) | A local large language model through **Ollama** (it uses the model you installed: **qwen2.5:1.5b** or llama3.2:3b) | Staff paste or upload a scholarship memo; the model suggests the GWA, income ceiling, year levels, colleges, one-scholarship rule, documents, deadline and amount, **each with the sentence it came from**. Values whose sentence is not in the memo are marked *check*. Staff tick what is right; it goes into the form; staff press Save. | Simple pattern reading (GWA 2.25, ₱300,000, "first year", document names…). |

Fit levels: **Matches the field** (a shared field), **Close to the field** (similar text, no shared field), **Other
fields**, **Open to all fields** (the program names no field, e.g. TES: this is not a "low fit"). The similarity
number is relative (compare programs for one student); it is **not** a chance of approval.

Why not a model that predicts approval? It would need many real past decisions to learn from. ScholarGuide has none
(the demo data is fictional), so any accuracy figure would be made up.

**Honest limits of the AI parts**
- The fit is only as good as the program descriptions. Before round 12 the demo descriptions were generic, so the
  agency-direct programs now say who they are for, taken from the agencies' own pages (DOST-SEI, ACEF, Landbank,
  NGCP; sources in `DemoDataSeeder::FOCUS`). OAS should update them from each new memo (the memo reader adds a
  *For students in: …* line).
- A field match does not check the program's other conditions (e.g. Iskolar ng Landbank is for children and
  grandchildren of agrarian reform beneficiaries, farmers and fishers). Students are told to check each program.
- A language model can misread a memo. That is why every value shows its sentence, unverified values are marked,
  documents are never ticked automatically, and nothing is saved without staff.
- Tested here with the built-in fallbacks (TF-IDF and patterns): the embedding model and Ollama could not be
  installed in Claude's environment, so check them once on the OAS computer (TEST_PLAN Part P).

### Setting up the two models (once, with internet)

```
cd ai-service
pip install -r requirements-ai.txt        # fastembed; the model downloads on first use (~70 MB)
```
Ollama: install it from https://ollama.com (Windows installer), then in PowerShell:
```
ollama pull qwen2.5:1.5b                  # about 1 GB; runs on a normal 8 GB laptop
```
Keep Ollama running (it starts with Windows after install). Optional settings in the AI service environment:
`OLLAMA_URL` (default `http://127.0.0.1:11434`), `OLLAMA_MODEL` (empty = use the installed model, qwen2.5:1.5b first), `FIT_MODEL`.
Check: `http://127.0.0.1:8001/fit-status` says `"method": "embeddings"` when the model is in use.

The existing AI parts keep their jobs:
- **PaddleOCR** reads scanned documents and lists.
- **RapidFuzz** compares names (document check and External Grantees).

**Honest limits:**
- The engine is only as good as the rules OAS types in.
- It does not "learn".
- The demo rules are **samples**, not the official CHED or CSU criteria (see section 6).

## 2. Where the criteria come from

Before round 11, `scholarship_requirements` held only **documents to submit**. The `students` table had no GWA or
income. Counting "met requirements" on those tables would only measure how many documents a student has uploaded.
That is readiness, not eligibility.

Round 11 adds the following.

**Scholarship rules** (staff enter them in Scholarships → Manage → *Eligibility rules*; empty = no rule):
- `max_gwa`: highest GWA allowed (1.00 is the best grade)
- `max_family_income`: highest annual family income
- `year_levels`: allowed year levels
- `colleges`: allowed colleges
- `one_scholarship_only`: the student must not hold another active scholarship (on by default)

**Student data** (the student types it on My Profile; OAS checks it):
- `gwa` and `family_income`
- `eligibility_verified_at` and `eligibility_verified_by`: when OAS checked them against the Certificate of Grades
  and the Parents' ITR, and who did

Changing the values removes the check, until OAS checks them again.

## 3. How the score is calculated

For each active scholarship that has at least one rule or required document, and each active student account, the
engine (`backend/app/Support/EligibilityEngine.php`) runs these checks:

| Check | Must-have? | Met when |
|---|:-:|---|
| GWA | yes | student's GWA ≤ highest GWA allowed |
| Family income | yes | income ≤ the ceiling |
| Year level | yes | the year level is in the list |
| College | yes | the college is in the list |
| No other scholarship | yes | no active grantee record in ScholarGuide **and** no active outside scholarship (External Grantees) |
| Each required document | no | not expired **and** either checked by the AI with no problem found (*validated*) or marked *verified* by OAS staff. A file that is only uploaded (not checked yet), flagged, or *needs review* does **not** count |

**Score = checks met ÷ all checks × 100.** Every check has equal weight. A check with no data counts as not met.

**Verdict** (the label staff see):
- **Meets the rules**: every must-have rule is met. The score then shows how ready the student is (documents).
- **Fails a rule**: at least one must-have rule is not met, even if the score is high.
- **Not enough data**: a must-have rule could not be checked (for example, no GWA entered).

Example: the student's income is under the ceiling and they hold no other scholarship. They have uploaded 0 of
the 8 required documents. The result is "2 of 10 checks met (2 of 2 must-have rules, 0 of 8 documents) = 20%",
with the verdict *Meets the rules*.

**Weights.** Equal weights are used on purpose. They are simple to explain, and a panel can check them by hand.
Custom weights per rule are left as **future work**: they need OAS policy decisions and testing that cannot be
done before the defense.

**What does 100% mean?** (changed in round 12) It means *every check the program has was met with the data in
ScholarGuide*. It does **not** mean the AI is 100% accurate, and it does not mean the student will be approved:
- The score is a count, not a prediction. No accuracy figure has been measured, so none is claimed.
- GWA and family income are typed by the student. Rows show **GWA/income not checked** until OAS staff mark them
  checked (after seeing the Grades and the ITR). Tick **Only GWA/income checked by OAS** to hide unchecked students.
- Before round 12 a document counted as soon as it was uploaded. Now it counts only after the AI check (validated)
  or a staff check (verified). The Why? window says "not AI-checked yet" for the others.
- The agency still makes the final decision.

**What is never checked:** sex, name, or anything the scholarship does not ask for. Two students with the same data
always get the same score.

## 3b. What to do next (round 12)

`backend/app/Support/NextSteps.php` turns the checks into advice. It is plain rules, nothing is predicted.

**A low % with "Meets the rules" usually means documents are missing**, not that the student is unlikely. So the
advice lists the actual missing items instead of only a number.

| Result | Staff (Why? window, *What to do next*) | Student (dashboard, *Scholarships that fit you*) |
|---|---|---|
| Fails a must-have rule | Which rule failed; do not invite; Dismiss, or Mark as wrong if the data is wrong | Not shown |
| Not enough data | Ask the student to enter the GWA / income; then Recalculate | "Needs your profile info" + *Enter your GWA on My Profile* |
| Meets the rules, 90–100% | Check GWA/income → Send invitation or Add to scholarship | "Ready to apply" + Apply |
| Meets the rules, 50–89% | Invite and name the missing documents | "N things left" + the checklist |
| Meets the rules, below 50% | Qualifies, most documents missing; an invitation is optional | "N things left" + the checklist |

Staff also see **Other programs for this student** in the Why? window (best first; click one to open it).

**Students never see a percentage.** A % could be read as a chance of approval, and it could tempt a student to
type a better GWA. They see a checklist (upload this, press Check with AI, enter your income), and the note that it
is a suggestion, not an approval. Programs they fail, programs they already applied for, and matches OAS marked
*wrong* are not listed. The card is hidden when there is nothing to show and for students who already hold a
scholarship. `GET /api/student/matches` recalculates that one student first, so a new upload counts at once.

## 4. Where it appears

**Staff → AI Recommendations**
- Program tabs, with filters for: Show (verdict or decision), applied / not applied, score range, and name or
  Student ID.
- Sorted by score, highest first by default (click the Score column to reverse).
- Each row shows the score, which checks are met and which are not, the application status, the staff decision,
  **Why?** and **Review** (opens the student's full record in the Data Bank).

**Why?** shows:
- the exact calculation
- every check, with the rule, the student's data used, and the result
- whether the GWA and income are checked by OAS
- the decision buttons and the history

**Staff decisions** (the AI never decides):
- **Send invitation**: e-mails the student that they *may be eligible*. Their bell shows it.
- **Add to scholarship**: after a confirm window, starts a **draft application**. The student still attaches
  documents and submits; OAS reviews it and the agency decides. It is refused when:
  - the program is applied for at the agency, or
  - the program is closed, or
  - the student already applied, or
  - the student already holds a scholarship.
- **Dismiss**: not suitable or not now.
- **Mark as wrong**: needs a note saying what is wrong. Listed under *Show: Marked wrong* for review.
- **Restore**: back to *New*.

Going against the AI (inviting or adding a student who does not meet the rules) also needs a written reason.

**Students:**
- My Profile → *Grades and family income*
- the bell shows "OAS thinks you may be eligible for …"

## 5. How OAS can be sure a recommendation is good (safeguards)

1. **It only recommends.** Nothing is approved. "Add to scholarship" makes only a draft application.
2. **Transparent.** Every recommendation shows the exact calculation, every rule checked, the student's value used,
   and each result.
3. **Data is checked by people.** A GWA or income typed by a student is labelled *typed by the student, not yet
   verified* until OAS marks it checked against the Grades and the ITR.
4. **Missing data is never treated as a pass or a zero.** It shows *Not enough data*.
5. **Overrides need a reason.** Staff can go against the AI, but must write why.
6. **Errors can be reported.** *Mark as wrong* (with a note) collects bad recommendations for review.
7. **Everything is logged** (`recommendation_logs`):
   - every recommendation **shown** to staff (one row per row on the screen), with the score at that moment
   - every **decision** (invited, added, dismissed, wrong, restored), with the staff member and note
   - Reports → *AI Recommendation Log* exports the decisions; the Activity Logs also record each action.
8. **Same rules for everyone.** Equal weights, and no personal traits that the scholarship does not ask for.

A simple way to show the panel that it works: take 10 students, work out their scores by hand from the rules and
their data, and compare with the screen. Do not report an "accuracy %" unless you actually measure it against
real OAS decisions.

## 6. Demo data

`DemoDataSeeder::SAMPLE_RULES` gives the OAS programs **sample** rules (for example, SA: GWA 2.50 or better and
income PHP 250,000 or less) so the demo shows both outcomes. **Replace them with the official criteria** from each
program's memo before real use.

The fictional students' GWA and income are the values printed on their sample Grades and ITR. Wena's are left
empty, so she can enter them during testing.

## 7. Files

| Part | Files |
|---|---|
| Database | `2026_10_23_000001_add_eligibility_rules_and_student_data`, `2026_10_23_000002_create_ai_recommendations_tables` |
| Engine | `app/Support/EligibilityEngine.php`, `php artisan recommendations:run` |
| API | `RecommendationController` (`/staff/recommendations…`, `/staff/students/{id}/eligibility`), `StudentController::updateEligibility` (`PATCH /profile/eligibility`) |
| E-mail | `ScholarshipInvitationMail`, `SendInvitationEmail`, `emails/scholarship-invitation.blade.php` |
| Pages | `pages/staff/RecommendationsPage.jsx`, `components/EligibilityRules.jsx`, My Profile, Data Bank, Reports, bell |
| Tests | `tests/Feature/EligibilityEngineTest.php` |
