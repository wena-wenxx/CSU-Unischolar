// Tutorial recorder helpers: an overlay with a gold arrow, a highlight ring, a fake cursor and a caption bar,
// drawn on top of the real website while Playwright records a video. The overlay never blocks clicks.
const OVERLAY = `
(() => {
  if (window.__tut) return;
  const css = \`
    #tut-root{position:fixed;inset:0;pointer-events:none;z-index:2147483646;font-family:Inter,Segoe UI,Arial,sans-serif}
    #tut-ring{position:fixed;border:4px solid #f2c230;border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,.18),0 0 18px 4px rgba(242,194,48,.85);
      transition:all .55s cubic-bezier(.4,0,.2,1);opacity:0}
    #tut-arrow{position:fixed;left:0;top:0;transform-origin:0 50%;transition:all .55s cubic-bezier(.4,0,.2,1);opacity:0;filter:drop-shadow(0 3px 4px rgba(0,0,0,.45))}
    #tut-cursor{position:fixed;width:22px;height:22px;margin:-4px 0 0 -4px;transition:left .55s cubic-bezier(.4,0,.2,1),top .55s cubic-bezier(.4,0,.2,1);opacity:0}
    #tut-cursor.click::after{content:"";position:absolute;left:-14px;top:-14px;width:40px;height:40px;border-radius:50%;border:3px solid #f2c230;animation:tutp .5s ease-out}
    @keyframes tutp{from{transform:scale(.3);opacity:1}to{transform:scale(1.4);opacity:0}}
    #tut-cap{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);max-width:min(980px,86vw);background:rgba(4,58,32,.96);color:#fff;
      border-left:6px solid #f2c230;border-radius:12px;padding:14px 22px;font-size:22px;line-height:1.35;box-shadow:0 8px 28px rgba(0,0,0,.35);opacity:0;transition:opacity .3s}
    #tut-cap small{display:block;color:#f2c230;font-size:14px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin-bottom:2px}
    #tut-card{position:fixed;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;
      background:linear-gradient(135deg,#043a20,#0b6b3a);color:#fff;opacity:0;transition:opacity .5s}
    #tut-card h1{font-family:Georgia,serif;font-size:54px;margin:0;color:#fff !important}
    #tut-card p{font-size:24px;margin:0;max-width:900px;color:#e9f3ec}
    #tut-card .gold{color:#f2c230;font-weight:700;letter-spacing:.08em;text-transform:uppercase;font-size:18px}
  \`;
  const s = document.createElement('style'); s.textContent = css; document.documentElement.appendChild(s);
  const root = document.createElement('div'); root.id = 'tut-root';
  root.innerHTML = '<div id="tut-ring"></div>'
    + '<svg id="tut-arrow" width="150" height="56" viewBox="0 0 150 56"><path d="M4 20 H104 V6 L146 28 L104 50 V36 H4 Z" fill="#f2c230" stroke="#043a20" stroke-width="4" stroke-linejoin="round"/></svg>'
    + '<svg id="tut-cursor" viewBox="0 0 24 24"><path d="M3 2 L3 20 L8 15 L11.5 22 L14.5 20.5 L11 13.8 L18 13.8 Z" fill="#fff" stroke="#111" stroke-width="1.6" stroke-linejoin="round"/></svg>'
    + '<div id="tut-cap"><small></small><span></span></div><div id="tut-card"></div>';
  const mount = () => { if (!document.body) return setTimeout(mount, 30); document.body.appendChild(root); };
  mount();
  const $ = (id) => document.getElementById(id);
  window.__tut = {
    target(box) {
      const ring = $('tut-ring'), arrow = $('tut-arrow'), cur = $('tut-cursor');
      if (!box) { ring.style.opacity = 0; arrow.style.opacity = 0; return; }
      const pad = 6;
      Object.assign(ring.style, { left: box.x - pad + 'px', top: box.y - pad + 'px', width: box.width + 2 * pad + 'px', height: box.height + 2 * pad + 'px', opacity: 1 });
      const cx = box.x + box.width / 2, cy = box.y + box.height / 2, W = innerWidth, H = innerHeight;
      let dx = cx - W / 2, dy = cy - H / 2; const len = Math.hypot(dx, dy) || 1;
      if (len < 80) { dx = 0; dy = -1; } else { dx /= len; dy /= len; }
      // tip: on the ring edge, on the side facing the middle of the screen
      const tx = Math.abs(dx) > 1e-6 ? (box.width / 2 + pad + 6) / Math.abs(dx) : Infinity;
      const ty = Math.abs(dy) > 1e-6 ? (box.height / 2 + pad + 6) / Math.abs(dy) : Infinity;
      const t = Math.min(tx, ty);
      const tipX = cx - dx * t, tipY = cy - dy * t, L = 150;
      const tailX = tipX - dx * L, tailY = tipY - dy * L, ang = Math.atan2(dy, dx) * 180 / Math.PI;
      Object.assign(arrow.style, { transform: 'translate(' + tailX + 'px,' + (tailY - 28) + 'px) rotate(' + ang + 'deg)', opacity: 1 });
      Object.assign(cur.style, { left: cx + 'px', top: cy + 'px', opacity: 1 });
    },
    caption(text, label) {
      const c = $('tut-cap'); if (!text) { c.style.opacity = 0; return; }
      c.querySelector('small').textContent = label || ''; c.querySelector('span').textContent = text; c.style.opacity = 1;
    },
    click() { const c = $('tut-cursor'); c.classList.remove('click'); void c.offsetWidth; c.classList.add('click'); },
    card(html) { const k = $('tut-card'); if (!html) { k.style.opacity = 0; return; } k.innerHTML = html; k.style.opacity = 1; },
  };
})();`;

function make(page, chapters, t0) {
  let n = 0;
  const wait = (ms) => page.waitForTimeout(ms);
  const readMs = (text) => Math.max(2400, 900 + text.length * 48);
  const ensure = async () => { await page.evaluate(OVERLAY); await page.waitForFunction(() => !!document.getElementById('tut-ring')); };
  const box = async (loc) => {
    await loc.first().scrollIntoViewIfNeeded().catch(() => {});
    // Windows slide in (about 0.2 s): measure only when the element has stopped moving.
    let prev = null;
    for (let i = 0; i < 12; i++) {
      await wait(150);
      const b = await loc.first().boundingBox();
      if (b && prev && Math.abs(b.x - prev.x) < 1 && Math.abs(b.y - prev.y) < 1 && Math.abs(b.width - prev.width) < 1) {
        // Hidden under the sticky top bar (66 px)? Scroll the page down a little and measure again.
        const fixed = await loc.first().evaluate((el) => !!el.closest('.modal, .sidebar, .topbar')).catch(() => true);
        if (b.y < 80 && !fixed) { await page.evaluate(() => window.scrollBy(0, -140)); await wait(250); return loc.first().boundingBox(); }
        return b;
      }
      prev = b;
    }
    return prev;
  };
  const api = {
    wait,
    // A new chapter in the list under the video (title = what the viewer can jump to).
    chapter(title) { chapters.push({ t: Math.max(0, (Date.now() - t0) / 1000 - 0.3), title }); },
    async card(html, ms = 3200) { await ensure(); await page.evaluate((h) => window.__tut.card(h), html); await wait(ms); await page.evaluate(() => window.__tut.card(null)); await wait(500); },
    async say(text, ms) { await ensure(); await page.evaluate(([t, l]) => { window.__tut.target(null); window.__tut.caption(t, l); }, [text, 'Good to know']); await wait(ms || readMs(text)); },
    async point(loc, text, ms) {
      await ensure(); n++;
      const b = await box(loc);
      await page.evaluate(([bx, t, l]) => { window.__tut.target(bx); window.__tut.caption(t, l); }, [b, text, `Step ${n}`]);
      await wait(ms || readMs(text));
      return b;
    },
    async click(loc, text, ms) {
      if (text) await api.point(loc, text, ms); else { await ensure(); const b = await box(loc); await page.evaluate((bx) => window.__tut.target(bx), b); await wait(700); }
      await page.evaluate(() => window.__tut.click()); await wait(350);
      await page.evaluate(() => { window.__tut.target(null); });
      await loc.first().click();
      await wait(900);
      // The caption described the click; remove it so it never sits over the next screen.
      await page.evaluate(() => window.__tut && window.__tut.caption(null)).catch(() => {});
    },
    async type(loc, value, text) {
      if (text) await api.point(loc, text, 1800);
      await loc.first().click(); await loc.first().pressSequentially(value, { delay: 55 }); await wait(400);
    },
    async clear() { await ensure(); await page.evaluate(() => { window.__tut.target(null); window.__tut.caption(null); }); },
  };
  return api;
}

module.exports = { make };
