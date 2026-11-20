# How to set up and run ScholarGuide on a new computer

For: **Gleslimie** (Git Bash) and **Wena** (PowerShell), or anyone who pulls the project for the first time.
Windows 10 / 11. Follow the parts in order. Part A is done only once per computer.

Words used here:
- **Git Bash** = the black window where you type commands. It comes with Git.
  - Open it alone: Start menu → type **Git Bash** → Enter.
  - Or inside VS Code: **Terminal → New Terminal**, then click the small **⌄** next to the **+** and choose **Git Bash**.
- **Run** = type (or paste) the line and press **Enter**. Wait until it finishes before the next line.
  To paste in Git Bash: **right-click → Paste**, or `Shift + Insert`.
- In Git Bash the `C:` drive is written `/c/`, so `C:\Projects\CSU-Unischolar` is **`/c/Projects/CSU-Unischolar`**.

**Using PowerShell instead of Git Bash?** Everything is the same except these:

| Git Bash | PowerShell |
|---|---|
| `cd /c/Projects/CSU-Unischolar/backend` | `cd C:\Projects\CSU-Unischolar\backend` |
| `cp .env.example .env` | `copy .env.example .env` |
| `source .venv/Scripts/activate` | `.venv\Scripts\activate` |
| `php -m \| grep zip` | `php -m \| findstr zip` |
| `cd ../frontend` | `cd ..\frontend` |

---

## Part A — Install the programs (once)

| # | Program | Where to download | Notes |
|---|---|---|---|
| 1 | **Git** (includes Git Bash) | git-scm.com → Download for Windows | Default choices |
| 2 | **VS Code** | code.visualstudio.com | Tick "Add to PATH" |
| 3 | **XAMPP** (PHP 8.2 + MySQL) | apachefriends.org → XAMPP for Windows **8.2** | Install to `C:\xampp` |
| 4 | **Composer** | getcomposer.org → Composer-Setup.exe | When asked for PHP, choose `C:\xampp\php\php.exe` |
| 5 | **Node.js** | nodejs.org → the **LTS** button | Default choices |
| 6 | **Python 3.11** | python.org → Downloads → Windows → **Python 3.11** (not 3.13) | Tick **"Add python.exe to PATH"** |
| 7 | **Ollama** (optional) | ollama.com → Download for Windows | Only for "Read the program memo with AI" |

**Turn on two PHP settings:** open `C:\xampp\php\php.ini` in Notepad, find `;extension=zip` and delete the `;`,
do the same for `;extension=gd`, save, then **restart the computer**.

Check (each line prints a version; the last prints `zip`):
```bash
git --version
php -v
composer --version
node -v
python --version
php -m | grep zip
```

---

## Part B — Get the project (once)

```bash
mkdir -p /c/Projects
cd /c/Projects
git clone https://github.com/wena-wenxx/CSU-Unischolar.git
git config --global user.name "Your Name"
git config --global user.email "your-github-email@example.com"
```
Then VS Code → **File → Open Folder** → `C:\Projects\CSU-Unischolar`.

## Part C — Prepare the database (once)

XAMPP Control Panel → **Start** Apache and MySQL → **Admin** next to MySQL → **New** → name `csu_unischolar` → **Create**.

## Part D — Backend (once)

```bash
cd /c/Projects/CSU-Unischolar/backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan storage:link
php artisan test
```
`php artisan test` should end with **71 passed**. `.env` is never uploaded to GitHub.

## Part E — Website (once)

```bash
cd /c/Projects/CSU-Unischolar/frontend
npm install
```

## Part F — AI service (once)

```bash
cd /c/Projects/CSU-Unischolar/ai-service
python -m venv .venv
source .venv/Scripts/activate
pip install -r requirements.txt
pip install -r requirements-ocr.txt
python -m pytest
```
- After `source .venv/Scripts/activate` the line starts with **`(.venv)`**.
- `requirements-ocr.txt` installs **PaddleOCR** (about 1 GB). It is needed to read **scans and photos**:
  scanned CORs and the **scanned CHED TES billing file**. Without it, typed PDFs, Word, Excel and CSV still work,
  but a scanned TES billing file can only be read from the scanner's own (bad) text, and the page says so.
- If PaddleOCR will not install, **Tesseract** also works for the TES billing file: install it from
  github.com/UB-Mannheim/tesseract/wiki, then `pip install pytesseract`. PaddleOCR is used first when both exist.
- Optional: `pip install -r requirements-ai.txt` (field-fit model) and `ollama pull qwen2.5:1.5b` (memo reader).

## Part G — Start the system (every time)

Three terminals (the **+** in VS Code's terminal panel, three times):
```bash
# Terminal 1
cd /c/Projects/CSU-Unischolar/backend && php artisan serve
# Terminal 2
cd /c/Projects/CSU-Unischolar/ai-service && source .venv/Scripts/activate && uvicorn main:app --port 8001
# Terminal 3
cd /c/Projects/CSU-Unischolar/frontend && npm run dev
```
Open **http://localhost:5173**. Demo accounts: staff `oas.staff@carsu.edu.ph` / `Staff@12345`,
admin `admin@carsu.edu.ph` / `Admin@12345`, student `student1@carsu.edu.ph` / `Student@12345`.

## Part H — When your partner pushes changes (every time)

Stop the 3 terminals (`Ctrl + C`), then:
```bash
cd /c/Projects/CSU-Unischolar
git status
git pull
cd backend && composer install && php artisan migrate && php artisan test
cd ../frontend && npm install
cd ../ai-service && source .venv/Scripts/activate && pip install -r requirements.txt
```
- If `git status` lists your own changes, commit them first (Part I) or `git pull` may refuse.
- `php artisan migrate` keeps your data. `php artisan migrate:fresh --seed` **erases** everything and loads the demo data.
- A screen asking for a "merge message": type `:wq` and press Enter. "CONFLICT": stop and ask your partner.

## Part I — Share your changes

```bash
cd /c/Projects/CSU-Unischolar
git status          # .env and real student files must NOT be in the list
git add -A
git commit -m "Short description of what you changed"
git pull
git push
```

## Real files with students' data

The real TES billing file, real CORs and the survey answers have real names, IDs, e-mails and phone numbers
(Data Privacy Act, RA 10173). Keep them in a folder **outside** the project, or in `private-test-data/` inside it
(that folder is in `.gitignore`, so Git never uploads it). Never put them in the demo data, tests or screenshots.

## If something goes wrong

| What you see | What to do |
|---|---|
| `php: command not found` | Restart the computer. Still wrong: `echo 'export PATH="/c/xampp/php:$PATH"' >> ~/.bashrc`, reopen Git Bash. |
| `SQLSTATE[HY000] [2002]` | XAMPP MySQL is not started. |
| `could not find driver` | In `php.ini`, remove the `;` before `extension=pdo_mysql`, reopen the terminals. |
| `Class "ZipArchive" not found` | `extension=zip` in `php.ini`, reopen the terminals. |
| `.venv/Scripts/activate: No such file` | Wrong folder, or Part F `python -m venv .venv` was skipped. |
| "AI service is not running" | Terminal 2 is not running. Word/Excel/CSV lists still work without it. |
| Reading a scanned TES file takes long | Normal: every page is read with OCR. The 8-page file took about 1¼ minutes with Tesseract on our test machine; PaddleOCR on your laptop was not timed yet. Wait; do not press again. |
| "no OCR program is installed" on a scanned list | Part F: install PaddleOCR (or Tesseract), restart Terminal 2. |
