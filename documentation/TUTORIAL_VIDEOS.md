# Video guides (Help page)

Two short videos are on the **Help** page:

| Video | Who sees it | Length | File |
|---|---|---|---|
| Student guide | students, staff, admin | 3 min 24 s | `frontend/public/tutorials/student-guide.webm` / `.mp4` |
| OAS staff guide | staff, admin | 5 min 38 s | `frontend/public/tutorials/staff-guide.webm` / `.mp4` |

They were recorded from the **real system** with **fictional demo data** (`migrate:fresh --seed`). A gold arrow,
a highlight and a caption show each step. There is no sound, so they work in a quiet office or a lab. The chapter
list under each video (`frontend/src/lib/tutorials.js`) jumps to that part. The videos start only when the person
presses Play.

Two formats are kept on purpose: **WebM** plays in Chrome, Edge and Firefox; **MP4** plays in Safari and older
browsers. The browser picks the one it can play.

## Re-recording after the screens change

The recorder drives the website in a browser, draws the arrows and captions, and saves a video.

One-time setup (in the project folder):
```powershell
cd C:\Projects\CSU-Unischolar\tools\tutorials
npm init -y
npm install playwright
npx playwright install chromium
winget install Gyan.FFmpeg          # to convert the videos
```

Record (the system must be running: backend, AI service, `npm run dev`):
```powershell
cd C:\Projects\CSU-Unischolar\backend
php artisan migrate:fresh --seed     # ERASES the database and loads the demo data again
cd ..\tools\tutorials
node student.cjs out                 # first: Juan applies for TDP-SUC
node staff.cjs out                   # then: staff process Juan's application
```
Each script prints where it saved its `.webm` and writes `out\student-chapters.json` / `out\staff-chapters.json`
(chapter times). Convert and copy:
```powershell
ffmpeg -i out\raw-student\<file>.webm -vf "scale=1280:-2,fps=24" -c:v libvpx-vp9 -b:v 0 -crf 42 -an ..\..\frontend\public\tutorials\student-guide.webm
ffmpeg -i out\raw-student\<file>.webm -vf "scale=1280:-2,fps=24" -c:v libx264 -crf 30 -pix_fmt yuv420p -movflags +faststart -an ..\..\frontend\public\tutorials\student-guide.mp4
```
(the same for `staff`), then copy the chapter times into `frontend/src/lib/tutorials.js`.

To change what a caption says, edit the text in `student.cjs` / `staff.cjs` and record again.
