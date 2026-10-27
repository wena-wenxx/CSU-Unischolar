// OAS staff video guide (fictional demo data). Record AFTER student.cjs: it processes Juan's new application.
// Usage: node staff.cjs <outDir>
const { chromium } = require('playwright');
const { make } = require('./tut-lib.cjs');
const OUT = process.argv[2] || 'out', BASE = process.env.BASE || 'http://localhost:5173';
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1366, height: 768 }, recordVideo: { dir: OUT + '/raw-staff', size: { width: 1366, height: 768 } } });
  const p = await ctx.newPage(); const t0 = Date.now(); const chapters = [];
  const T = make(p, chapters, t0);
  const nav = (label) => p.locator('aside nav a', { hasText: new RegExp(`^${label}$`) });
  const modal = () => p.locator('.modal').last();
  const confirmIfAsked = async (before) => { await p.waitForTimeout(500); if ((await p.locator('.modal').count()) > before) await T.click(modal().locator('button.button-primary, button.button-danger').last()); };
  const quiet = async () => { await p.waitForTimeout(1200); for (const x of await p.locator('.toast-close').all()) await x.click().catch(() => {}); };

  await p.goto(BASE + '/login'); await p.waitForTimeout(800);
  await T.card('<div class="gold">Caraga State University · Office of Admission and Scholarship</div><h1>ScholarGuide</h1><p>Video guide for OAS staff: from a student\'s application to the payroll, in the same order as the OAS process.</p>', 4200);

  T.chapter('Sign in and dashboard');
  await T.type(p.locator('#email'), 'oas.staff@carsu.edu.ph', 'Sign in with your OAS staff account.');
  await T.type(p.locator('#password'), 'Staff@12345');
  await T.click(p.locator('form button[type=submit]'));
  await p.waitForSelector('aside nav'); await p.waitForTimeout(1200);
  if (await p.locator('.modal').count()) await T.click(p.locator('.modal button.close-button'), 'A short welcome guide appears the first time. Close it when you are ready.');
  await T.point(p.locator('.attention-grid, .stats-grid').first(), 'The dashboard shows what needs your attention. Click any number to open that list.');
  await T.point(p.locator('.sidebar .nav-heading').first(), 'The menu follows the OAS process: 1 Programs, 2 Applications, 3 Grantees, then Office records.');

  T.chapter('1 · Programs and their rules');
  await T.click(nav('Scholarships'), 'Scholarships: the programs students can see, their deadlines, documents and rules.');
  await p.waitForTimeout(1500);
  await T.click(p.locator('main button', { hasText: 'Manage' }).first(), 'Press Manage to edit a program.');
  await p.waitForTimeout(900);
  const rules = modal().locator('summary', { hasText: 'Eligibility rules' });
  if (await rules.count()) {
    await rules.first().evaluate((el) => { el.parentElement.open = true; });
    await p.waitForTimeout(500);
    await T.point(modal().locator('.rules-details').first(), 'Eligibility rules (GWA, income, year level…) are used by AI Recommendations. Type them from the official memo.');
    await T.point(modal().locator('button', { hasText: 'Read the program memo with AI' }), 'Or let the AI read the memo: it suggests the rules with the sentence it found them in. You check them and press Save.');
  }
  await T.click(modal().locator('button.close-button'));

  T.chapter('AI Recommendations');
  await T.click(nav('AI Recommendations'), 'AI Recommendations lists students the rules say may qualify, even if they have not applied.');
  await p.waitForTimeout(1800);
  await T.point(p.locator('.score-groups'), 'The score is a requirements match, not a chance of approval. Use these groups to find students.');
  await T.click(p.locator('.score-groups button', { hasText: 'Needs follow-up' }), '"Needs follow-up" lists the lowest scores first: usually missing or unchecked documents.');
  await p.waitForTimeout(1800);
  await T.click(p.locator('button', { hasText: 'Why?' }).first(), 'Press Why? to see every requirement, the student\'s data, and the points it earned.');
  await p.waitForTimeout(1200);
  await T.point(modal().locator('.check-table'), 'Every check is shown. Nothing changes until you invite the student, add them, or dismiss the suggestion.');
  await T.click(modal().locator('button.close-button'));

  T.chapter('2 · Review an application');
  await T.click(nav('Applications'), 'Applications: every application, grouped by student. The newest activity is on top.');
  await p.waitForTimeout(1500);
  await T.type(p.locator('#application-search'), 'Navarro', 'Search by name or Student ID.');
  await p.waitForTimeout(1200);
  const row = p.locator('main tbody tr', { hasText: 'TDP-SUC' }).first();
  await T.click(row.locator('button', { hasText: 'Review' }), 'Press Review to open the application Juan just submitted.');
  await p.waitForTimeout(1800);
  const run = modal().locator('button', { hasText: /^Run AI check$/ });
  if (await run.count()) {
    await T.click(run.first(), 'Press Run AI check: the AI reads the document and checks the name, Student ID and document type.');
    await quiet();
    let n = 0; while ((await run.count()) && n < 10) { await run.first().click(); await quiet(); n++; }
    await T.say('A flag is only a warning: open the file and decide. The AI cannot prove a document is genuine.');
  }
  await T.click(modal().locator('button', { hasText: 'Complete: forward to agency' }), 'When the documents are complete, press "Complete: forward to agency".');
  await confirmIfAsked(1); await quiet();

  T.chapter('Agency decision and approved lists');
  await T.point(modal().locator('button', { hasText: 'Approved by agency' }), 'The agency decides. Record its decision here for one student…');
  await T.click(modal().locator('button', { hasText: 'Approved by agency' }));
  await confirmIfAsked(1); await quiet();
  await T.click(modal().locator('button.close-button').first());
  await T.click(nav('Forwarded to Agency'), 'Forwarded to Agency: the list you send to CHED or the provider.');
  await p.waitForTimeout(1500);
  await T.point(p.locator('.export-menu summary'), 'Export it as Excel, PDF or CSV for the agency.');
  await T.click(nav('Approved Lists'), '…or, when the agency sends back its approved list, upload it in Approved Lists.');
  await p.waitForTimeout(1500);
  await T.point(p.locator('#agency-file'), 'Excel, Word, PDF, CSV or a photo. The system matches the Student IDs and shows every row before anything changes.');

  T.chapter('3 · Enrollment');
  await T.click(nav('Enrollment'), 'Before tagging a grantee, check that the student is enrolled this semester.');
  await p.waitForTimeout(1500);
  await T.point(p.locator('button', { hasText: 'Verify All Enrollments' }), 'Upload the Registrar\'s list, then Verify All: the system suggests matches and you press Record.');
  await T.click(nav('Applications'));
  await p.waitForTimeout(1200);
  await p.locator('#application-search').fill('Navarro'); await p.waitForTimeout(1000);
  await T.click(p.locator('main tbody tr', { hasText: 'TDP-SUC' }).first().locator('button', { hasText: 'Verify enrollment' }), 'For one student, press Verify enrollment…');
  await p.waitForTimeout(800);
  await T.click(modal().locator('button.button-primary'), '…and record that the student is currently enrolled.');
  await quiet();

  T.chapter('Tag the grantee');
  await T.click(nav('Scholar Records'), 'Scholar Records: approved and enrolled students are tagged as grantees here.');
  await p.waitForTimeout(1500);
  const tag = p.locator('.list-item, tr', { hasText: 'Navarro' }).filter({ has: p.locator('button', { hasText: 'Tag as Grantee' }) }).first();
  await T.click(tag.locator('button', { hasText: 'Tag as Grantee' }), 'Press Tag as Grantee. One student can hold only one active scholarship.');
  await p.waitForTimeout(800);
  await T.click(modal().locator('button.button-primary'));
  await quiet();

  T.chapter('Payroll and Form 6-A');
  await T.click(nav('Payroll'), 'Payroll: prepare the stipend list. No money is moved by the system.');
  await p.waitForTimeout(1500);
  const opt = (await p.locator('#prep-program option').allInnerTexts()).find((o) => /TDP - State/.test(o));
  if (opt) await p.selectOption('#prep-program', { label: opt });
  await T.click(p.locator('button', { hasText: /^Preview/ }).first(), 'Choose the program and period, then press Preview: only active, enrolled grantees are included.');
  await p.waitForTimeout(1500);
  await T.click(p.locator('button', { hasText: /^Confirm/ }).first(), 'Confirm to add them as Draft. Pressing it again never adds anyone twice.');
  await confirmIfAsked(0); await quiet();
  await T.point(p.locator('button', { hasText: 'General Payroll (Form 6-A)' }), 'Then mark entries Ready and Processed, and print General Form 6-A as PDF or Word for one program and period.');

  T.chapter('Office records and sign out');
  await T.click(nav('External Grantees'), 'External Grantees: scholars of LGUs and other providers, so nobody holds two scholarships.');
  await p.waitForTimeout(1200);
  await T.click(nav('Reports'), 'Reports: applications, scholars, payroll and the AI log, ready to export.');
  await p.waitForTimeout(1500);
  await T.click(p.locator('.sidebar button:has-text("Sign out")'), 'Sign out when you finish.');
  await p.waitForTimeout(1200);
  await T.card('<h1>That is the OAS process</h1><p>Programs → Applications → Agency decision → Enrollment → Grantees → Payroll</p><div class="gold">The AI suggests and checks. OAS staff decide. The agency approves.</div>', 4500);

  const video = p.video(); await ctx.close(); const path = await video.path();
  require('fs').writeFileSync(OUT + '/staff-chapters.json', JSON.stringify({ video: path, chapters }, null, 1));
  console.log('staff video', path, 'chapters', chapters.length);
  await b.close();
})().catch((e) => { console.log('STOPPED:', e.message.split('\n')[0]); process.exit(1); });
