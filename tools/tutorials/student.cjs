// Student video guide (fictional student Juan, demo data). Usage: node student.cjs <outDir>
const { chromium } = require('playwright');
const { make } = require('./tut-lib.cjs');
const OUT = process.argv[2] || 'out', BASE = process.env.BASE || 'http://localhost:5173';
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1366, height: 768 }, recordVideo: { dir: OUT + '/raw-student', size: { width: 1366, height: 768 } } });
  const p = await ctx.newPage(); const t0 = Date.now(); const chapters = [];
  const T = make(p, chapters, t0);
  const nav = (label) => p.locator('aside nav a', { hasText: new RegExp(`^${label}$`) });

  await p.goto(BASE + '/login'); await p.waitForTimeout(800);
  await T.card('<div class="gold">Caraga State University · Office of Admission and Scholarship</div><h1>ScholarGuide</h1><p>Video guide for students: find a scholarship, prepare your documents, apply, and follow your application.</p>', 4200);

  T.chapter('Sign in');
  await T.type(p.locator('#email'), 'student1@carsu.edu.ph', 'Sign in with the e-mail and password the OAS gave you.');
  await T.type(p.locator('#password'), 'Student@12345');
  await T.click(p.locator('form button[type=submit]'), 'Press Sign In. (The first time, you will be asked to choose your own password.)');
  await p.waitForSelector('aside nav'); await p.waitForTimeout(1200);

  T.chapter('Welcome guide');
  if (await p.locator('.modal').count()) {
    await T.point(p.locator('.modal').last(), 'The first time you sign in, a short welcome guide explains the main steps.');
    await T.click(p.locator('.modal button.close-button'), 'Close it when you are ready. You can show it again from Help.');
  }

  T.chapter('Your dashboard');
  await T.point(p.locator('.matches-card'), 'Your dashboard shows the scholarships that fit you, based on your profile and documents.');
  await T.point(p.locator('.matches-card .match-row').first(), 'Each program shows what is left to do and its deadline.');
  if (await p.locator('.matches-card button:has-text("Show list")').count()) {
    await T.click(p.locator('.matches-card button:has-text("Show list")'), 'Press Show list to see which documents are meant.');
    await p.waitForTimeout(1200);
  }
  await T.say('This is a suggestion, not an approval: the OAS checks your documents, and the agency decides.');

  T.chapter('My Documents');
  await T.click(nav('My Documents'), 'Open My Documents. Upload each document once and reuse it for every application.');
  await p.waitForTimeout(1200);
  await T.point(p.locator('.doc-card').first(), 'Each card is one document: the file, until when it is valid, and the AI check result.');
  await T.point(p.locator('.doc-card input[type=file]').first(), 'To upload or replace a file, choose it here (PDF, JPG or PNG), then press Upload.');
  await T.say('For a new file, press "Check with AI": the AI reads it and points out problems, like a different name. Files already sent with an application are checked by the OAS.');

  T.chapter('Find a scholarship and apply');
  await T.click(nav('Scholarships'), 'Open Scholarships to see the programs that are open now.');
  await p.waitForTimeout(1200);
  const card = p.locator('.card, article, li', { hasText: /State Universities and Colleges/ }).first();
  await T.point(card, 'Each card shows the amount, the deadline and how many documents are needed.');
  await T.click(card.locator('a', { hasText: 'Details' }), 'Press Details to read the requirements.');
  await p.waitForTimeout(1200);
  await T.click(p.locator('main button', { hasText: 'Apply for this scholarship' }), 'Press "Apply for this scholarship". Your application starts as a draft.');
  await p.waitForTimeout(1500);

  T.chapter('Attach documents and submit');
  await T.point(p.locator('main .card').first(), 'Your draft lists every required document.');
  if (await p.locator('button', { hasText: 'Use my saved documents' }).count()) {
    await T.click(p.locator('button', { hasText: 'Use my saved documents' }), 'Press "Use my saved documents" to attach the files from My Documents in one step.');
    await p.waitForTimeout(1500);
  }
  await T.say('A missing document can be uploaded here too. The Submit button works only when every required document is attached.');
  await T.click(p.locator('main button', { hasText: /^Submit application$/ }), 'When everything is attached, press Submit application.');
  await p.waitForTimeout(600);
  if ((await p.locator('.modal').count())) await T.click(p.locator('.modal').last().locator('button.button-primary').last(), 'Confirm. After this, the OAS reviews your application.');
  await p.waitForTimeout(1500);

  T.chapter('Follow your application');
  await T.click(nav('My Applications'), 'Open My Applications to follow every application.');
  await p.waitForTimeout(1500);
  await T.point(p.locator('main table tbody tr').first(), 'The status tells you where it is: Submitted, Under review, Needs action, Forwarded, Approved.');
  await T.say('If it says Needs action, open it: the OAS wrote what to fix. Replace the document and submit again.');
  await T.click(p.locator('.bell-button'), 'The bell shows every update from the OAS.');
  await p.waitForTimeout(1500);
  await p.keyboard.press('Escape'); await p.locator('.bell-button').click().catch(() => {}); await p.waitForTimeout(400);

  T.chapter('Profile, messages and help');
  await T.click(nav('My Profile'), 'In My Profile you can add your photo and your field and goals, so the AI finds programs for your field.');
  await p.waitForTimeout(1500);
  await T.click(nav('Contact OAS'), 'Questions? Send the OAS a message here. Their reply appears on this page.');
  await p.waitForTimeout(1200);
  await T.click(nav('Help'), 'Help explains every step in writing, and has this video.');
  await p.waitForTimeout(1200);

  T.chapter('Sign out');
  await T.click(p.locator('.sidebar button:has-text("Sign out")'), 'Always sign out when you finish, especially on a shared computer.');
  await p.waitForTimeout(1200);
  await T.card('<h1>You are ready!</h1><p>Find a scholarship · Prepare your documents · Apply · Follow your application</p><div class="gold">Questions? Use Contact OAS or visit the Office of Admission and Scholarship</div>', 4200);

  const video = p.video(); await ctx.close(); const path = await video.path();
  require('fs').writeFileSync(OUT + '/student-chapters.json', JSON.stringify({ video: path, chapters }, null, 1));
  console.log('student video', path, 'chapters', chapters.length);
  await b.close();
})().catch((e) => { console.log('STOPPED:', e.message.split('\n')[0]); process.exit(1); });
