/* SADA One — Mikasa: the team's assistant in the corner.
 * Speaks at most six lines a day (the first on the first page of the day, then now and then while the page is in
 * view), sleeps at night, can be hushed for the day or switched off. Motion uses the Web Animations API and stays
 * still for people who prefer reduced motion. The day's count lives in this browser only. */
(() => {
    const root = document.getElementById('mikasa');
    if (!root) return;
    const MAX = 6;
    const face = root.querySelector('.mk-face');
    const bubble = root.querySelector('.mk-bubble');
    const textEl = root.querySelector('.mk-text');
    const linkEl = root.querySelector('.mk-link');
    const menu = root.querySelector('.mk-menu');
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const now = new Date();
    const today = `${now.getFullYear()}-${now.getMonth() + 1}-${now.getDate()}`;
    const hour = now.getHours();
    const sleeping = hour >= 23 || hour < 7;

    const read = (fallback) => { try { return JSON.parse(localStorage.getItem('mikasa:day')) || fallback; } catch { return fallback; } };
    const save = () => { try { localStorage.setItem('mikasa:day', JSON.stringify(day)); } catch { /* private window: forget quietly */ } };
    let day = read({});
    if (day.date !== today) day = { date: today, shown: 0, index: 0, muted: false };

    // Arrival
    root.hidden = false;
    if (!reduce) root.animate([{ transform: 'translateY(26px) scale(.85)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: 520, easing: 'cubic-bezier(.22,1,.36,1)' });

    // Idle life: a slow float and a blink every few seconds; asleep, a drifting "z"
    if (sleeping) {
        root.classList.add('is-sleeping');
        const z = root.querySelector('.mk-zzz');
        if (!reduce && z) z.animate([{ transform: 'translate(0,0) scale(.7)', opacity: 0 }, { opacity: 1, offset: .3 }, { transform: 'translate(10px,-22px) scale(1.1)', opacity: 0 }], { duration: 2600, iterations: Infinity, easing: 'ease-out' });
    } else if (!reduce) {
        face.animate([{ transform: 'translateY(0)' }, { transform: 'translateY(-3px)' }, { transform: 'translateY(0)' }], { duration: 3400, iterations: Infinity, easing: 'ease-in-out' });
        const eyes = root.querySelector('.mk-eyes');
        const blink = () => {
            eyes?.animate([{ transform: 'scaleY(1)' }, { transform: 'scaleY(.12)' }, { transform: 'scaleY(1)' }], { duration: 170, easing: 'ease-in-out' });
            setTimeout(blink, 2800 + Math.random() * 3600);
        };
        setTimeout(blink, 1800);
    }

    let hideTimer = null;
    const show = (text, link) => {
        textEl.textContent = text;
        linkEl.hidden = !link;
        if (link) linkEl.href = link;
        bubble.hidden = false;
        if (!reduce) bubble.animate([{ transform: 'translateY(6px) scale(.96)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: 260, easing: 'cubic-bezier(.22,1,.36,1)' });
        if (!reduce && !sleeping) face.animate([{ transform: 'rotate(0)' }, { transform: 'rotate(-8deg)' }, { transform: 'rotate(6deg)' }, { transform: 'rotate(0)' }], { duration: 480, easing: 'ease-in-out' });
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hide, Math.max(6000, text.length * 90));
    };
    const hide = () => {
        if (bubble.hidden) return;
        if (reduce) { bubble.hidden = true; return; }
        bubble.animate([{ opacity: 1 }, { opacity: 0, transform: 'translateY(4px)' }], { duration: 200 }).onfinish = () => { bubble.hidden = true; };
    };

    let lines = null;
    const load = async () => {
        if (lines) return lines;
        const j = await api('mikasa_lines');
        lines = j.ok ? j.lines : [];
        return lines;
    };
    // Speak the next line of the day; `asked` = the person clicked (a click may cycle, but the day's total still holds)
    const speak = async (asked) => {
        if (sleeping) { if (asked) show('Zzz… Sabah konuşuruz. Acil bir şey varsa Bugün sayfası açık.', 'today.php'); return; }
        if (day.shown >= MAX) { if (asked) show('Bugünlük bu kadar. Yarın yine buradayım.'); return; }
        if (day.muted && !asked) return;
        const all = await load();
        if (!all.length) return;
        if (day.index >= all.length) { if (!asked) return; day.index = 0; }
        const line = all[day.index];
        day.index++; day.shown++; save();
        show(line.text, line.link);
    };

    // The first line of the day, then one more every 25 minutes of visible time
    if (!sleeping && !day.muted && day.shown === 0) setTimeout(() => speak(false), 1400);
    setInterval(() => { if (document.visibilityState === 'visible') speak(false); }, 25 * 60 * 1000);

    face.addEventListener('click', () => {
        const open = menu.hidden;
        menu.hidden = !open;
        menu.querySelector('[data-mk=mute]').textContent = day.muted ? 'Konuşabilirsin' : 'Bugün sus';
        if (open && !reduce) menu.animate([{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 180 });
    });
    menu.addEventListener('click', async (e) => {
        const act = e.target.closest('[data-mk]')?.dataset.mk;
        if (!act) return;
        menu.hidden = true;
        if (act === 'next') speak(true);
        if (act === 'mute') { day.muted = !day.muted; save(); show(day.muted ? 'Tamam, bugün sessizim.' : 'Buradayım.'); }
        if (act === 'off') {
            const j = await api('mikasa_off');
            if (!j.ok) return;
            if (reduce) { root.remove(); return; }
            root.animate([{ opacity: 1 }, { opacity: 0, transform: 'translateY(20px) scale(.8)' }], { duration: 320 }).onfinish = () => root.remove();
            toast(`${root.dataset.name} kapatıldı. Profil → Bildirim Tercihleri'nden geri açabilirsiniz.`, 'info', 5000);
        }
    });
    bubble.addEventListener('click', (e) => { if (!e.target.closest('.mk-link')) hide(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { hide(); menu.hidden = true; } });
})();
