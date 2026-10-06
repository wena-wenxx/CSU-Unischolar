# Putting CSU UniScholar online (deployment guide)

This guide puts the three parts of the system on the internet so the panel
can open it from any browser:

| Part | Where it goes | Cost |
|---|---|---|
| Frontend (React) | **Vercel** (Hobby plan) | Free |
| Backend (Laravel) + MySQL database | **Railway** | $5 one-time trial credit, then Hobby $5/month (see "Costs") |
| AI service (FastAPI + PaddleOCR) | **Hugging Face Space** (Docker, CPU basic) | Free |

Why this mix: Vercel is free and made for React/Vite sites. Railway runs
Laravel *and* MySQL in one project and keeps uploaded files on a volume.
Hugging Face gives the AI service 16 GB of RAM for free, which PaddleOCR
needs and which would be expensive anywhere else.

> **Status:** the files this guide uses (`backend/Dockerfile`,
> `backend/docker/start.sh`, `ai-service/Dockerfile`, `frontend/vercel.json`)
> were written for this project but have **not** been test-deployed yet.
> Do a full rehearsal at least one week before the defense (Nov 5, 2026).

---

## 0. Before you start

1. Push the latest code to `main` on GitHub.
2. Make three free accounts (sign in with GitHub on each — it is easiest):
   - https://vercel.com
   - https://railway.com
   - https://huggingface.co
3. On your computer, make a Laravel app key and **save it in a notepad**:
   ```powershell
   cd C:\Projects\CSU-Unischolar\backend
   php artisan key:generate --show
   ```
   You should see one line like `base64:AbCd...=`. Copy all of it.

Deploy in this order: **AI → Backend → Frontend**, because each one needs the
web address of the one before it.

---

## 1. AI service on Hugging Face

1. Go to https://huggingface.co/new-space.
2. Fill in:
   - **Space name:** `csu-unischolar-ai`
   - **License:** leave empty or pick "other"
   - **Select the Space SDK:** click **Docker** → **Blank**
   - **Space hardware:** **CPU basic · 2 vCPU · 16 GB · FREE**
   - **Public** (a private Space would need a login token from Laravel)
3. Click **Create Space**.
4. Upload the files. On the Space page click **Files** → **+ Contribute** →
   **Upload files**, then drag in everything from your `ai-service` folder
   **except** `.venv`, `__pycache__` and `tests`:
   `Dockerfile`, `.dockerignore`, `main.py`, `ocr_engine.py`, `validation.py`,
   `requirements.txt`, `requirements-ocr.txt`.
5. Upload `HF_SPACE_README.md` too, then rename it: open it on the Space,
   click **Edit**, and save it as **`README.md`** (replace the README the
   Space created). The top block (`sdk: docker`, `app_port: 7860`) tells
   Hugging Face how to run it.
6. Click **Commit changes to main**. Open the **App** tab — it shows
   "Building". The first build takes 10–20 minutes (PaddlePaddle is big).
7. When it says **Running**, your AI address is:
   `https://<your-hf-username>-csu-unischolar-ai.hf.space`
   Test it by opening that address + `/docs` in the browser. You should see
   the FastAPI page with `POST /validate-document`.

**Save this address** — it is your `AI_SERVICE_URL`.

Notes:
- The free Space **sleeps** when nobody uses it for a while. Open the
  address 5 minutes before the demo to wake it up.
- The first document check after waking is slow (PaddleOCR loads its models).
  Text-based PDFs (like the demo files) skip OCR and are fast.
- Its disk is not permanent. That is fine: the AI service does not store
  anything.

---

## 2. Backend + MySQL on Railway

### 2a. Create the project and database

1. Go to https://railway.com/new.
2. Click **Deploy from GitHub repo** → pick `wena-wenxx/CSU-Unischolar`.
   (If Railway asks, click **Configure GitHub App** and allow the repo.)
3. Railway creates a service from the repo. Click it, open **Settings**:
   - **Root Directory:** `backend`
   - Railway will find `backend/Dockerfile` and use it.
4. Go back to the project canvas. Click **+ Create** (or **+ New**) →
   **Database** → **MySQL**. Wait until it is green.

### 2b. Give uploads a permanent home (volume)

1. Right-click the backend service (or open it and use the command palette)
   → **Attach volume**.
2. **Mount path:** `/app/storage/app/public`

Without this, uploaded documents disappear every time Railway restarts.

### 2c. Environment variables

Open the backend service → **Variables** → **Raw Editor** and paste this,
then replace the three `<...>` parts:

```
APP_NAME="CSU UniScholar"
APP_ENV=production
APP_DEBUG=false
APP_KEY=<the base64:... key from step 0>
APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
DB_CONNECTION=mysql
DB_URL=${{MySQL.MYSQL_URL}}
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
LOG_CHANNEL=stderr
AI_SERVICE_URL=<https://your-hf-username-csu-unischolar-ai.hf.space>
FRONTEND_URL=<fill in after step 3, e.g. https://csu-unischolar.vercel.app>
SEED_DEMO_DATA=true
```

- `${{MySQL.MYSQL_URL}}` is typed exactly like that. Railway replaces it with
  the database's real address. (If your database service has another name,
  use that name instead of `MySQL`.)
- Until you have the Vercel address, you may put `FRONTEND_URL=*`.

Click **Update Variables**, then **Deploy**.

### 2d. Get the backend's public address

1. Backend service → **Settings** → **Networking** → **Generate Domain**.
   If it asks for a port, type **8080**.
2. You get something like `https://csu-unischolar-production.up.railway.app`.
3. Open that address + `/api/scholarships` in the browser.
   You should see `{"message":"Unauthenticated."}` — that is **good**: the
   backend is running and is asking for a login.

### 2e. What happens on every start

`backend/docker/start.sh` runs automatically:

1. `php artisan migrate --force` — creates/updates tables.
2. `php artisan db:seed --force` — loads demo data. Every seeder skips data
   that already exists, so restarts do not duplicate anything. After the
   first good deploy you can set `SEED_DEMO_DATA=false`.
3. `php artisan storage:link` — makes `/storage/...` file links work.
4. Starts the web server on Railway's port.

Check it in **Deployments** → latest → **View logs**. You should see lines
like `Running migrations`, `Seeding: Database\Seeders\BulkDemoSeeder` and
`Server running on [http://0.0.0.0:8080]`.

---

## 3. Frontend on Vercel

1. Go to https://vercel.com/new → **Import** `wena-wenxx/CSU-Unischolar`.
2. On the setup screen:
   - **Framework Preset:** Vite
   - **Root Directory:** click **Edit** → choose `frontend`
   - **Environment Variables:** add
     - Key: `VITE_API_URL`
     - Value: `https://<your-railway-domain>/api` (note the `/api` at the end)
3. Click **Deploy**. After 1–2 minutes you get an address like
   `https://csu-unischolar.vercel.app`.
4. `frontend/vercel.json` makes page refreshes on `/staff/...` and
   `/student/...` work (without it, refreshing shows a 404).

### Connect the frontend back to the backend

1. Copy the Vercel address (no slash at the end).
2. Railway → backend → **Variables** → set
   `FRONTEND_URL=https://csu-unischolar.vercel.app` → it redeploys.
   (This is the CORS setting in `backend/config/cors.php`. Several
   addresses can be separated with commas.)

---

## 4. Final test (do this every time you deploy)

1. Open the Vercel address. You should see the green CSU login page.
2. Log in as `oas.staff@carsu.edu.ph` / `Staff@12345`.
   The dashboard should show non-zero numbers (130 applicants, 560
   applications, 61 active scholars, 225 AI flags...).
3. Open **Applications** → any application → click a document's **View**.
   The PDF should open (this tests the volume + storage link).
4. Log in as `student5@carsu.edu.ph` / `Student@12345` (Liza), apply to
   **CSU Cultural Grant (Choir)**, upload one PDF from the demo kit, and run
   the AI check from the staff side. You should get a result in a few
   seconds (or ~1 minute if the Space was asleep).

If something fails, look here first:

| What you see | Most likely cause | Fix |
|---|---|---|
| Login page says "Network Error" | wrong `VITE_API_URL`, or `FRONTEND_URL` not set | check both, then **Redeploy** on Vercel (Vite reads env vars at build time) |
| Refreshing a page gives 404 on Vercel | `vercel.json` missing / Root Directory not `frontend` | check Settings → General |
| Railway logs: `No application encryption key` | `APP_KEY` missing | paste the key from step 0 |
| Railway logs: `SQLSTATE[HY000] [2002]` | database not linked | check `DB_URL=${{MySQL.MYSQL_URL}}` |
| Document "View" gives 404 | no volume or storage link | step 2b; redeploy |
| AI check says "AI service unavailable" | Space asleep or wrong `AI_SERVICE_URL` | open the Space, wait for **Running**, try again |

---

## 5. Costs (checked October 2026 — prices can change)

- **Vercel Hobby:** free for personal, non-commercial projects.
- **Hugging Face CPU basic Space:** free; sleeps when unused.
- **Railway:** new accounts get a one-time **$5 trial credit**. After that,
  the **Hobby plan is $5/month and includes $5 of usage**. Usage is billed by
  resources (RAM about $10 per GB per month, CPU about $20 per vCPU per month,
  volume about $0.15 per GB per month). A Laravel container plus MySQL often
  uses around 0.5–1 GB of RAM together, so the bill can be roughly **$5–$10
  per month**. Watch **Project → Usage** during the first week. You can
  delete the project after the defense to stop charges.

**Total for the defense period (Oct–Nov 2026): about $0–$10.**

### Free-only alternative (more limits)

- Backend on **Render** free web service (Docker, root `backend`) and
  database on **Aiven for MySQL** free tier (1 GB, no credit card;
  paste its connection URI as `DB_URL`).
- Downsides: Render's free service **sleeps after 15 minutes** (first page
  load takes about a minute) and has **no permanent disk**, so uploaded
  documents are lost whenever it restarts. Aiven may power off a free
  database that is not used for a long time. Fine for a quick test; risky
  for the defense.

---

## 6. Keeping it safe

- Never commit a real `.env` file. Secrets live only in the Railway / Vercel
  / Hugging Face variable screens.
- All demo accounts and documents are fictional. Do not upload real student
  documents to the public demo.
- `APP_DEBUG=false` in production so error pages do not show secrets.
