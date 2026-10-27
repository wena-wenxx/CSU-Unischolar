# How to set up and run ScholarGuide on a new computer

For: **Gleslimie** (or anyone who pulls the project for the first time). Windows 10 / 11.
Follow the parts in order. Part A is done only once per computer.

Words used here:
- **PowerShell** = the blue/black window where you type commands. In VS Code: menu **Terminal → New Terminal**.
- **Run** = type (or paste) the line and press **Enter**. Wait until it finishes before the next line.
- The project folder is `C:\Projects\CSU-Unischolar`.

---

## Part A — Install the programs (once)

Download and install each one. Keep the default choices unless written otherwise.

| # | Program | Where to download | Notes |
|---|---|---|---|
| 1 | **Git** | git-scm.com → Download for Windows | Default choices |
| 2 | **VS Code** | code.visualstudio.com | Tick "Add to PATH" |
| 3 | **XAMPP** (PHP 8.2 + MySQL) | apachefriends.org → XAMPP for Windows **8.2** | Install to `C:\xampp` |
| 4 | **Composer** | getcomposer.org → Composer-Setup.exe | When asked for PHP, choose `C:\xampp\php\php.exe` |
| 5 | **Node.js** | nodejs.org → the **LTS** button (version 22) | Default choices |
| 6 | **Python 3.11** | python.org → Downloads → Windows → **Python 3.11** (not 3.13) | On the first screen tick **"Add python.exe to PATH"** |
| 7 | **Ollama** (optional) | ollama.com → Download for Windows | Only for "Read the program memo with AI" |

**Turn on two PHP settings:**
1. Open the file `C:\xampp\php\php.ini` with Notepad.
2. Press `Ctrl + F` and find `;extension=zip`. Delete the `;` in front so it reads `extension=zip`.
3. Do the same for `;extension=gd` → `extension=gd`.
4. Save and close.
5. **Restart the computer** (so every program is found).

Check that everything is installed. Open PowerShell and run each line; each should print a version number:
```powershell
git --version
php -v
composer --version
node -v
python --version
php -m | findstr zip
```
If one says "is not recognized", that program is not installed or the computer was not restarted.

---

## Part B — Get the project (once)

1. Open PowerShell and run:
```powershell
mkdir C:\Projects
cd C:\Projects
git clone https://github.com/wena-wenxx/CSU-Unischolar.git
```
   (If Git asks you to sign in, sign in with your GitHub account. Wena must add you as a collaborator first.)
2. Open VS Code → **File → Open Folder** → choose `C:\Projects\CSU-Unischolar`.

---

## Part C — Prepare the database (once)

1. Open **XAMPP Control Panel** (Start menu → XAMPP).
2. Press **Start** next to **Apache** and next to **MySQL** (both turn green).
3. Press **Admin** next to MySQL. A web page (phpMyAdmin) opens.
4. On the left click **New**. Database name: `csu_unischolar`. Press **Create**.

Keep XAMPP's MySQL running whenever you use the system.

---

## Part D — Set up the backend (once)

In VS Code open a terminal (**Terminal → New Terminal**) and run:
```powershell
cd C:\Projects\CSU-Unischolar\backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan storage:link
php artisan test
```
- `composer install` downloads the Laravel parts (a few minutes).
- `.env` holds this computer's settings. **It is never uploaded to GitHub.** The database settings already match
  XAMPP (`csu_unischolar`, user `root`, no password).
- `migrate:fresh --seed` makes the tables and loads the **fictional demo data**.
- `php artisan test` should end with **56 passed**.

**E-mail (optional):** by default e-mails are only written to a log file. To send real e-mails, Wena can show you the
Gmail **App Password** steps. Use your own Gmail and App Password, never a normal password, and never send `.env` to anyone.

---

## Part E — Set up the website (once)

```powershell
cd C:\Projects\CSU-Unischolar\frontend
npm install
```
(a few minutes). Nothing else to change.

---

## Part F — Set up the AI service (once)

```powershell
cd C:\Projects\CSU-Unischolar\ai-service
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
python -m pytest
```
- If `.venv\Scripts\activate` shows a red error about "running scripts is disabled", run this once, then try again:
  `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned` (answer **Y**).
- Optional, bigger downloads:
  - `pip install -r requirements-ocr.txt` — PaddleOCR, to read **scanned photos** (about 1 GB). Without it, typed PDFs still work.
  - `pip install -r requirements-ai.txt` — the field-fit model for AI Recommendations. Without it, a simpler method is used.
- Optional, Ollama (memo reader): open the Ollama app once, then run `ollama pull qwen2.5:1.5b` (about 1 GB).

---

## Part G — Start the system (every time)

Open **3 terminals** in VS Code (press the **+** in the terminal panel three times). One block per terminal:

**Terminal 1 — backend**
```powershell
cd C:\Projects\CSU-Unischolar\backend
php artisan serve
```
**Terminal 2 — AI service**
```powershell
cd C:\Projects\CSU-Unischolar\ai-service
.venv\Scripts\activate
uvicorn main:app --port 8001
```
**Terminal 3 — website**
```powershell
cd C:\Projects\CSU-Unischolar\frontend
npm run dev
```
Then open **http://localhost:5173** in Chrome. (XAMPP MySQL must be green.)

Sign in with the demo accounts (fictional, for testing only):

| Role | E-mail | Password |
|---|---|---|
| OAS staff | oas.staff@carsu.edu.ph | Staff@12345 |
| System admin | admin@carsu.edu.ph | Admin@12345 |
| Student | student1@carsu.edu.ph | Student@12345 |

New to the system? Open **Help** and press Play on the **Video guide**.

To stop: click each terminal and press `Ctrl + C`.

---

## Part H — When Wena pushes new changes (every time)

```powershell
cd C:\Projects\CSU-Unischolar
git pull
cd backend
composer install
php artisan migrate
cd ..\frontend
npm install
cd ..\ai-service
.venv\Scripts\activate
pip install -r requirements.txt
```
Then start the system (Part G).
- `php artisan migrate` keeps your data and adds only what is new.
- For a clean demo rehearsal only: `php artisan migrate:fresh --seed` (this **erases** everything and loads the demo data again).

## Part I — When you change something and want to share it

```powershell
cd C:\Projects\CSU-Unischolar
git status
```
Check the list: `.env` must **not** be there. Then:
```powershell
git add -A
git commit -m "Short description of what you changed"
git pull
git push
```
Always `git pull` before `git push`, so you don't overwrite Wena's work. If Git says there is a **conflict**, stop and ask Wena.

---

## If something goes wrong

| What you see | What to do |
|---|---|
| `'php' is not recognized` | Restart the computer. If still wrong, reinstall XAMPP and Composer (Part A). |
| `SQLSTATE[HY000] [2002]` (connection refused) | XAMPP MySQL is not started (Part C, step 2). |
| `could not find driver` | In `C:\xampp\php\php.ini` make sure `extension=pdo_mysql` has no `;` in front, then restart the terminals. |
| `Unknown database 'csu_unischolar'` | Create the database (Part C, step 4). |
| `Class "ZipArchive" not found` / Word or Excel download fails | `extension=zip` in php.ini (Part A), then restart the terminals. |
| The website opens but sign-in says "network error" | Terminal 1 (backend) is not running. |
| "AI service is not running" on a document check | Terminal 2 is not running. Typed Word/Excel lists still work without it. |
| `cd ..\ai-service` goes to the wrong folder | Always use the full path: `cd C:\Projects\CSU-Unischolar\ai-service`. |
| Anything else in red | Copy the red text and send it to Wena. |
