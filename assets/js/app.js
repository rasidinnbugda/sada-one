/* ============================================================
   SADA Dijital — Uygulama JavaScript
   ============================================================ */
(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';

    /* ---------- Yardımcılar ---------- */
    const $ = (s, k = document) => k.querySelector(s);
    const $$ = (s, k = document) => Array.from(k.querySelectorAll(s));

    window.api = async function (action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf', CSRF);
        for (const k in data) {
            if (data[k] instanceof File || data[k] instanceof Blob) fd.append(k, data[k]);
            else if (Array.isArray(data[k])) fd.append(k, JSON.stringify(data[k]));
            else fd.append(k, data[k] ?? '');
        }
        try {
            const r = await fetch('ajax.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.ok && j.error) toast(j.error, 'hata');
            return j;
        } catch (e) {
            toast('Bağlantı hatası. Tekrar deneyin.', 'hata');
            return { ok: false, error: 'network' };
        }
    };

    /* ---------- Toast ---------- */
    window.toast = function (message, type = 'info', sure = 3800) {
        const field = $('#toastField');
        if (!field) return;
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        const icons = {
            basari: '<path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            error: '<path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/>',
            info: '<path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
        };
        el.innerHTML = `<svg class="toast-ikon" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">${icons[type] || icons.info}</svg><span>${message}</span>`;
        field.appendChild(el);
        setTimeout(() => { el.classList.add('cikis'); setTimeout(() => el.remove(), 300); }, sure);
    };

    /* ---------- Modal ---------- */
    window.modalOpen = function (id) {
        const m = document.getElementById(id);
        if (m) { m.classList.add('acik'); document.body.style.overflow = 'hidden'; const first = m.querySelector('input,textarea,select'); if (first) setTimeout(() => first.focus(), 120); }
    };
    window.modalClose = function (el) {
        const m = el?.closest ? el.closest('.modal-katman') : document.getElementById(el);
        if (m) { m.classList.remove('acik'); document.body.style.overflow = ''; }
    };
    document.addEventListener('click', e => {
        if (e.target.classList?.contains('modal-katman')) modalClose(e.target);
        const acan = e.target.closest('[data-modal]');
        if (acan) { e.preventDefault(); modalOpen(acan.dataset.modal); }
        const kapatan = e.target.closest('[data-modal-close]');
        if (kapatan) { e.preventDefault(); modalClose(kapatan); }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { const a = $('.modal-katman.open'); if (a) modalClose(a); }
    });

    /* ---------- Açılır menü (dropdown) ---------- */
    document.addEventListener('click', e => {
        const btn = e.target.closest('[data-acilir-btn]');
        const openOlan = $$('.acilir.open');
        if (btn) {
            const grup = btn.closest('.acilir');
            const zatenOpen = grup.classList.contains('acik');
            openOlan.forEach(a => a.classList.remove('acik'));
            if (!zatenOpen) grup.classList.add('acik');
            e.stopPropagation();
        } else if (!e.target.closest('.acilir-panel')) {
            openOlan.forEach(a => a.classList.remove('acik'));
        }
    });

    /* ---------- Sekmeler ---------- */
    document.addEventListener('click', e => {
        const sekme = e.target.closest('[data-sekme]');
        if (!sekme) return;
        const kap = sekme.closest('.sekme-kap') || document;
        $$('[data-sekme]', kap).forEach(s => s.classList.remove('aktif'));
        $$('.sekme-content', kap).forEach(s => s.classList.remove('aktif'));
        sekme.classList.add('aktif');
        const target = $('#sekme-' + sekme.dataset.sekme, kap);
        if (target) target.classList.add('aktif');
        if (history.replaceState) history.replaceState(null, '', '#' + sekme.dataset.sekme);
    });
    // URL hash with sekme aç
    if (location.hash) {
        const s = $(`[data-sekme="${location.hash.slice(1)}"]`);
        if (s) s.click();
    }

    /* ---------- Nav grupları: aç/kapa + hatırla ---------- */
    $$('.nav-grup').forEach(grup => {
        const setting_key = 'navGrup_' + grup.dataset.navGrup;
        // Kayıtlı status (is_active page içeren grup her time açık)
        if (!grup.classList.contains('aktif-grup')) {
            const entry = localStorage.getItem(setting_key);
            if (entry === 'acik') grup.classList.add('acik');
        }
        grup.querySelector('[data-grup-btn]').addEventListener('click', () => {
            grup.classList.toggle('acik');
            localStorage.setItem(setting_key, grup.classList.contains('acik') ? 'acik' : 'kapali');
        });
    });

    /* ---------- Kenar çubuğu (mobil) ---------- */
    const sidebar = $('#sidebar'), karartma = $('[data-karartma]');
    $('[data-sidebar-open]')?.addEventListener('click', () => { sidebar.classList.add('acik'); karartma.classList.add('acik'); });
    const sidebarClose = () => { sidebar?.classList.remove('acik'); karartma?.classList.remove('acik'); };
    $('[data-sidebar-close]')?.addEventListener('click', sidebarClose);
    karartma?.addEventListener('click', sidebarClose);

    /* ---------- Tema değiştirme ---------- */
    $$('.theme-nokta').forEach(nokta => {
        nokta.addEventListener('click', async () => {
            const theme = nokta.dataset.theme;
            document.documentElement.setAttribute('data-theme', theme);
            $$('.theme-nokta').forEach(n => n.classList.toggle('secili', n === nokta));
            await api('theme_change', { theme });
        });
    });

    /* ---------- Bildirimler ---------- */
    document.addEventListener('click', async e => {
        const read = e.target.closest('[data-all-read]');
        if (read) {
            e.preventDefault(); e.stopPropagation();
            await api('notification_all_read');
            $$('.notification-oge.new').forEach(b => b.classList.remove('yeni'));
            $('#notificationCounter')?.remove();
            read.remove();
        }
        const notificationDelete = e.target.closest('[data-notification-delete]');
        if (notificationDelete) {
            e.preventDefault(); e.stopPropagation();
            const oge = notificationDelete.closest('[data-notification]');
            await api('notification_delete', { id: oge.dataset.notification });
            oge.remove();
            return;
        }
        const bldrm = e.target.closest('[data-notification]');
        if (bldrm && bldrm.classList.contains('yeni')) {
            api('notification_read', { id: bldrm.dataset.notification });
        }
    });

    /* ---------- HTML escape helper for template literals (XSS guard) ---------- */
    window.esc = v => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    /* ---------- Gezinme ilerleme çubuğu: tık anında görünür, sayfa değişince biter ---------- */
    document.addEventListener('click', e => {
        const a = e.target.closest('a[href]');
        if (!a || a.target === '_blank' || a.hasAttribute('download') || e.metaKey || e.ctrlKey) return;
        const href = a.getAttribute('href') || '';
        if (href.startsWith('#') || href.startsWith('javascript') || href.startsWith('http') && !href.includes(location.host)) return;
        const cubuk = document.getElementById('sayfaCubugu');
        if (!cubuk) return;
        cubuk.classList.add('aktif');
        cubuk.style.width = '30%';
        setTimeout(() => cubuk.style.width = '75%', 180);
        setTimeout(() => cubuk.style.width = '92%', 700);
    });
    window.addEventListener('pageshow', () => {
        const cubuk = document.getElementById('sayfaCubugu');
        if (cubuk) { cubuk.style.width = '0'; cubuk.classList.remove('aktif'); }
    });

    /* ---------- Canlı bildirim sayacı: 45 sn'de bir tazele ---------- */
    setInterval(async () => {
        if (document.hidden) return;
        try {
            const j = await api('bildirim_sayi', {});
            if (!j.ok) return;
            const rozet = document.querySelector('[data-bildirim-rozet]');
            if (!rozet) return;
            rozet.textContent = j.sayi > 99 ? '99+' : j.sayi;
            rozet.style.display = j.sayi > 0 ? '' : 'none';
        } catch (err) { /* sessiz geç */ }
    }, 45000);

    /* ---------- Katlanabilir bölümler (Adımlarım vb.) ---------- */
    $$('[data-katla]').forEach(kutu => {
        const anahtar = 'katla_' + kutu.dataset.katla;
        const kayit = localStorage.getItem(anahtar);
        // varsayılan kapalı; kayıtlı tercih varsa uygula
        if (kayit === 'acik') kutu.classList.remove('kapali');
        kutu.querySelector('[data-katla-btn]').addEventListener('click', () => {
            kutu.classList.toggle('kapali');
            localStorage.setItem(anahtar, kutu.classList.contains('kapali') ? 'kapali' : 'acik');
        });
    });

    /* ---------- AJAX form gönderimi ---------- */
    document.addEventListener('submit', async e => {
        const form = e.target;
        if (!form.matches('[data-ajax]')) return;
        e.preventDefault();
        // Üye seçici kutularını JSON'a serileştir
        const uyeJson = form.querySelector('.uye-json');
        if (uyeJson) uyeJson.value = JSON.stringify($$('.uye-kutu:checked', form).map(c => c.value));
        // Görev atananlarını serileştir
        const atananJson = form.querySelector('.atananlar-json');
        if (atananJson) atananJson.value = JSON.stringify($$('.atanan-kutu:checked', form).map(c => c.value));
        const btn = form.querySelector('[type="submit"]');
        const eskiMetin = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = 'İşleniyor...'; }

        const veri = {};
        new FormData(form).forEach((v, k) => { veri[k] = v; });
        // Formdaki TÜM dosya girdilerini ekle (örn. logo + favicon aynı formda)
        $$('input[type="file"]', form).forEach(alan => {
            if (alan.files.length && alan.name) veri[alan.name] = alan.files[0];
        });

        const j = await api(form.dataset.ajax, veri);
        if (btn) { btn.disabled = false; btn.innerHTML = eskiMetin; }

        if (j.ok) {
            if (j.mesaj) toast(j.mesaj, 'basari');
            if (j.yonlendir) { setTimeout(() => location.href = j.yonlendir, 500); }
            else if (form.dataset.yenile !== 'hayir') { setTimeout(() => location.reload(), 550); }
            const m = form.closest('.modal-katman'); if (m) modalKapat(m);
        }
    });

    /* ---------- Genel data-eylem tetikleyicileri (onay diyaloğu) ---------- */
    document.addEventListener('click', async e => {
        const el = e.target.closest('[data-eylem]');
        if (!el) return;
        e.preventDefault();
        const onay = el.dataset.onay;
        if (onay && !confirm(onay)) return;
        const veri = {};
        for (const k in el.dataset) {
            if (!['eylem', 'onay', 'yenile', 'yonlendir'].includes(k)) veri[k] = el.dataset[k];
        }
        const j = await api(el.dataset.eylem, veri);
        if (j.ok) {
            if (j.mesaj) toast(j.mesaj, 'basari');
            if (el.dataset.yonlendir) location.href = el.dataset.yonlendir;
            else if (j.yonlendir) location.href = j.yonlendir;
            else if (el.dataset.yenile !== 'hayir') setTimeout(() => location.reload(), 450);
        }
    });

    /* ---------- Kanban sürükle-bırak (sütun içi sıralama + kalıcılık) ---------- */
    let suruklenen = null;
    $$('.kanban-kart[draggable]').forEach(bagla_kart);
    function bagla_kart(kart) {
        kart.addEventListener('dragstart', e => {
            suruklenen = kart;
            // Fare altında süzülen özel klon: hafif eğik + derin gölge
            if (e.dataTransfer && e.dataTransfer.setDragImage) {
                const r = kart.getBoundingClientRect();
                const klon = kart.cloneNode(true);
                klon.classList.add('kanban-ghost');
                klon.style.width = r.width + 'px';
                const sar = document.createElement('div');
                sar.style.cssText = 'position:fixed;top:-600px;left:-600px;padding:34px;pointer-events:none;background:transparent';
                sar.appendChild(klon);
                document.body.appendChild(sar);
                e.dataTransfer.setDragImage(sar, (e.clientX - r.left) + 34, (e.clientY - r.top) + 34);
                setTimeout(() => sar.remove(), 0);
            }
            setTimeout(() => kart.classList.add('suruklenuyor'), 0);
        });
        kart.addEventListener('dragend', () => {
            kart.classList.remove('suruklenuyor');
            kart.classList.add('birakildi');
            setTimeout(() => kart.classList.remove('birakildi'), 360);
            suruklenen = null;
        });
    }
    // Fare konumuna göre ekleme noktası: hangi kartın üstüne bırakılacak?
    function eklemeNoktasi(liste, y) {
        const kartlar = Array.from(liste.querySelectorAll('.kanban-kart:not(.suruklenuyor)'));
        let enYakin = { mesafe: Number.NEGATIVE_INFINITY, el: null };
        for (const k of kartlar) {
            const kutu = k.getBoundingClientRect();
            const fark = y - kutu.top - kutu.height / 2;
            if (fark < 0 && fark > enYakin.mesafe) enYakin = { mesafe: fark, el: k };
        }
        return enYakin.el;
    }
    $$('.kanban-liste').forEach(liste => {
        liste.addEventListener('dragover', e => {
            e.preventDefault();
            liste.closest('.kanban-sutun').classList.add('surukleme-ustunde');
            if (!suruklenen) return;
            const sonraki = eklemeNoktasi(liste, e.clientY);
            if (sonraki) liste.insertBefore(suruklenen, sonraki);
            else liste.appendChild(suruklenen);
        });
        liste.addEventListener('dragleave', () => liste.closest('.kanban-sutun').classList.remove('surukleme-ustunde'));
        liste.addEventListener('drop', async e => {
            e.preventDefault();
            const sutun = liste.closest('.kanban-sutun');
            sutun.classList.remove('surukleme-ustunde');
            if (!suruklenen) return;
            const yeniDurum = sutun.dataset.durum;
            const gorevId = suruklenen.dataset.gorev;
            const eskiDurum = suruklenen.dataset.durum;
            const kart = suruklenen;
            kart.dataset.durum = yeniDurum;
            guncelleKanbanSayilar();
            // Sütundaki güncel sırayı topla ve tek istekle kaydet
            const idler = Array.from(liste.querySelectorAll('.kanban-kart')).map(k => k.dataset.gorev);
            const j = await api('gorev_sirala', { id: gorevId, durum: yeniDurum, idler });
            if (j.ok) {
                if (yeniDurum !== eskiDurum) toast('Görev "' + sutun.querySelector('.kanban-title').textContent + '" durumuna taşındı', 'basari', 2200);
            } else {
                // Kilit vb. reddedildi: kartı eski sütununa geri koy
                kart.dataset.durum = eskiDurum;
                const eskiListe = $(`.kanban-sutun[data-durum="${oldStatus}"] .kanban-liste`);
                if (eskiListe) eskiListe.appendChild(kart);
                guncelleKanbanSayilar();
            }
        });
    });
    function guncelleKanbanSayilar() {
        $$('.kanban-sutun').forEach(s => {
            const say = s.querySelectorAll('.kanban-kart').length;
            const el = s.querySelector('.kanban-sayi'); if (el) el.textContent = say;
        });
    }

    /* ---------- Global arama ---------- */
    const aramaGirdi = $('#globalArama');
    if (aramaGirdi) {
        const panel = $('#aramaSonuc');
        let zamanlayici = null;
        aramaGirdi.addEventListener('input', () => {
            clearTimeout(zamanlayici);
            const q = aramaGirdi.value.trim();
            if (q.length < 2) { panel.classList.remove('acik'); return; }
            zamanlayici = setTimeout(async () => {
                const j = await api('arama', { q });
                if (!j.ok) return;
                let h = '';
                const ikonlar = { 'Dosyalar': '📁', 'Projeler': '📋', 'Görevler': '✅', 'İçerikler': '📅', 'Talepler': '💬' };
                for (const grup in j.sonuclar) {
                    if (!j.sonuclar[grup].length) continue;
                    h += `<div class="search-grup">${ikonlar[grup] || ''} ${grup}</div>`;
                    j.sonuclar[grup].forEach(s => {
                        h += `<a href="${s.link}" class="search-oge"><span>${s.ad.replace(/</g, '&lt;')}</span><span class="hucre-bottom">${s.alt || ''}</span></a>`;
                    });
                }
                panel.innerHTML = h || '<div class="bos-mini">Sonuç bulunamadı</div>';
                panel.classList.add('open');
            }, 280);
        });
        document.addEventListener('click', e => {
            if (!e.target.closest('.search-global')) panel.classList.remove('open');
        });
        aramaGirdi.addEventListener('keydown', e => { if (e.key === 'Escape') panel.classList.remove('open'); });
    }

    /* ---------- @Mention autocomplete ---------- */
    function trLower(s) { return s.replace(/İ/g, 'i').replace(/I/g, 'ı').toLowerCase(); }
    function mentionKur(ta) {
        const kap = ta.closest('.mention-kap') || ta.parentElement;
        let acilir = null, aktifIndex = 0, eslesen = [];
        function kapat() { if (acilir) { acilir.remove(); acilir = null; } }
        function sorguBul() {
            const kadar = ta.value.slice(0, ta.selectionStart);
            const m = kadar.match(/@([^\s@]{0,25})$/);
            return m ? m[1] : null;
        }
        function goster(sorgu) {
            const kisiler = window.sadaKisiler || [];
            eslesen = kisiler.filter(k => trLower(k.ad).includes(trLower(sorgu))).slice(0, 6);
            if (!eslesen.length) { kapat(); return; }
            kapat();
            aktifIndex = 0;
            acilir = document.createElement('div');
            acilir.className = 'mention-acilir';
            eslesen.forEach((k, i) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'mention-oge' + (i === 0 ? ' is_active' : '');
                b.textContent = '@ ' + k.ad;
                b.addEventListener('mousedown', e => { e.preventDefault(); sec(k); });
                acilir.appendChild(b);
            });
            kap.appendChild(acilir);
        }
        function sec(kisi) {
            const kadar = ta.value.slice(0, ta.selectionStart);
            const sonrasi = ta.value.slice(ta.selectionStart);
            const yeniKadar = kadar.replace(/@[^\s@]{0,25}$/, '@' + kisi.ad + ' ');
            ta.value = yeniKadar + sonrasi;
            ta.selectionStart = ta.selectionEnd = yeniKadar.length;
            // id'yi gizli alana add
            const gizli = (ta.closest('form') || kap).querySelector('.mention-idler');
            if (gizli) {
                const mevcut = gizli.value ? JSON.parse(gizli.value) : [];
                if (!mevcut.includes(person.id)) mevcut.push(person.id);
                gizli.value = JSON.stringify(mevcut);
            }
            close();
            ta.focus();
        }
        ta.addEventListener('input', () => {
            const query = queryFind();
            if (query === null) { close(); return; }
            show(query);
        });
        ta.addEventListener('keydown', e => {
            if (!acilir) return;
            const ogeler = acilir.querySelectorAll('.mention-oge');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = (activeIndex + (e.key === 'ArrowDown' ? 1 : -1) + ogeler.length) % ogeler.length;
                ogeler.forEach((o, i) => o.classList.toggle('aktif', i === activeIndex));
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                sec(eslesen[activeIndex]);
            } else if (e.key === 'Escape') close();
        });
        ta.addEventListener('blur', () => setTimeout(close, 180));
    }
    $$('textarea[data-mention]').forEach(mentionSetup);
    window.sadaMentionSetup = mentionSetup;

    /* ---------- Görev table: sütun sıralama ---------- */
    document.addEventListener('click', e => {
        const th = e.target.closest('th.siralanir');
        if (!th) return;
        const table = th.closest('table');
        const tbody = table.querySelector('tbody');
        const index = Array.from(th.parentElement.children).indexOf(th);
        const yon = th.dataset.yon === 'asc' ? 'desc' : 'asc';
        table.querySelectorAll('th.siralanir').forEach(t => { delete t.dataset.yon; const i = t.querySelector('.sira-isaret'); if (i) i.textContent = '↕'; });
        th.dataset.yon = yon;
        const isaret = th.querySelector('.sira-isaret'); if (isaret) isaret.textContent = yon === 'asc' ? '↑' : '↓';
        const satirlar = Array.from(tbody.querySelectorAll('tr'));
        satirlar.sort((a, b) => {
            const av = (a.children[index]?.dataset.sort ?? a.children[index]?.textContent ?? '').trim();
            const bv = (b.children[index]?.dataset.sort ?? b.children[index]?.textContent ?? '').trim();
            const an = parseFloat(av), bn = parseFloat(bv);
            const result = (!isNaN(an) && !isNaN(bn)) ? an - bn : av.localeCompare(bv, 'tr');
            return yon === 'asc' ? result : -result;
        });
        satirlar.forEach(s => tbody.appendChild(s));
    });

    /* ---------- Görev table: hücre içi düzenleme ---------- */
    window.hucreKaydet = async function (el, id, field) {
        const j = await api('gorev_alan', { id, field, setting_value: el.value });
        if (j.ok) {
            const hucre = el.closest('td');
            hucre.classList.remove('hucre-kaydedildi');
            void hucre.offsetWidth; // animasyonu tetikle
            hucre.classList.add('hucre-kaydedildi');
        } else if (el.dataset.old !== undefined) {
            el.value = el.dataset.old; // lock reddettiyse geri take
        }
    };

    /* ---------- Satır sıralama okları (akış/form editörleri) ---------- */
    document.addEventListener('click', e => {
        const ok = e.target.closest('[data-sira-yon]');
        if (!ok) return;
        e.preventDefault();
        const row_item = ok.closest('[data-siralanabilir]');
        if (!row_item) return;
        if (ok.dataset.positionYon === 'yukari' && row_item.previousElementSibling) {
            row_item.parentElement.insertBefore(row_item, row_item.previousElementSibling);
        } else if (ok.dataset.positionYon === 'asagi' && row_item.nextElementSibling) {
            row_item.parentElement.insertBefore(row_item.nextElementSibling, row_item);
        }
    });

    /* ---------- Arama filtreleme (istemci tarafı) ---------- */
    $$('[data-arama]').forEach(input => {
        input.addEventListener('input', () => {
            const q = input.value.toLowerCase().trim();
            $$(input.dataset.search).forEach(oge => {
                const text = oge.dataset.search || oge.textContent;
                oge.style.display = text.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    });

    /* ---------- Pill filtreler ---------- */
    $$('[data-pill-grup]').forEach(grup => {
        grup.addEventListener('click', e => {
            const pill = e.target.closest('.pill');
            if (!pill) return;
            $$('.pill', grup).forEach(p => p.classList.remove('aktif'));
            pill.classList.add('aktif');
            const setting_value = pill.dataset.setting_value;
            const target = grup.dataset.pillGrup;
            $$(target).forEach(oge => {
                oge.style.display = (setting_value === '' || oge.dataset.filtre === setting_value) ? '' : 'none';
            });
        });
    });

    /* ---------- İlerleme çubuğu animasyonu ---------- */
    setTimeout(() => {
        $$('.ilerleme-dolu[data-oran]').forEach(el => { el.style.width = el.dataset.rate + '%'; });
    }, 200);

    /* ---------- Sayaç animasyonu ---------- */
    $$('[data-sayac]').forEach(el => {
        const target = parseFloat(el.dataset.counter);
        if (isNaN(target)) return;
        let mevcut = 0;
        const step = target / 32;
        const timer = setInterval(() => {
            mevcut += step;
            if (mevcut >= target) { mevcut = target; clearInterval(timer); }
            el.textContent = Number.isInteger(target) ? Math.round(mevcut) : mevcut.toFixed(1);
        }, 22);
    });

    /* ---------- Mesajlaşmada en alta kaydır ---------- */
    const sohbetBody = $('.sohbet-govde');
    if (sohbetBody) sohbetBody.scrollTop = sohbetBody.scrollHeight;

    /* ---------- Canlı sync: 10 sn'de bir değişiklik kontrolü ----------
       Sayfa window.sadaCanli = {baglam, id, hash} tanımlarsa etkinleşir.
       Kullanıcı yazarken veya modal açıkken tazeleme ertelenir. */
    window.canliYenile = async function () {
        if (!window.sadaCanli) return;
        const j = await api('live_status', { baglam: sadaCanli.baglam, id: sadaCanli.id || 0 });
        if (j.ok) sadaCanli.hash = j.hash;
    };
    function mesgulMu() {
        const a = document.activeElement;
        if (a && (a.tagName === 'TEXTAREA' || a.tagName === 'INPUT' || a.tagName === 'SELECT')) return true;
        if ($('.modal-katman.open') || $('.mention-acilir') || $('.kanban-card.suruklenuyor')) return true;
        return false;
    }
    setInterval(async () => {
        if (!window.sadaCanli || document.hidden || mesgulMu()) return;
        try {
            const j = await api('live_status', { baglam: sadaCanli.baglam, id: sadaCanli.id || 0 });
            if (j.ok && j.hash !== sadaCanli.hash) {
                sadaCanli.hash = j.hash;
                location.reload();
            }
        } catch (e) { /* sessiz */ }
    }, 10000);

    window.sadaBaglaKart = bagla_kart; // dinamik kartlar için

    /* ============================================================
       ÖZEL SEÇİCİLER — tarayıcı varsayılanları yerine temalı bileşenler
       ============================================================ */
    const AYLAR_TR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    const GUN_KISA = ['Pt', 'Sa', 'Ça', 'Pe', 'Cu', 'Ct', 'Pz'];
    let acikPanel = null;
    function panelKapat() { if (acikPanel) { acikPanel.remove(); acikPanel = null; } }
    document.addEventListener('mousedown', e => {
        if (acikPanel && !acikPanel.contains(e.target) && !e.target.closest('.osec-tetik')) panelKapat();
    });
    function panelAc(tetik, panel) {
        panelKapat();
        panel.className = 'osec-panel ' + (panel.className || '');
        document.body.appendChild(panel);
        const k = tetik.getBoundingClientRect();
        const altBosluk = window.innerHeight - k.bottom;
        panel.style.left = Math.min(k.left, window.innerWidth - panel.offsetWidth - 10) + 'px';
        panel.style.top = (altBosluk > panel.offsetHeight + 12 ? k.bottom + 6 : k.top - panel.offsetHeight - 6) + window.scrollY + 'px';
        acikPanel = panel;
    }

    /* ---------- Özel SELECT ---------- */
    window.ozelSelectKur = function (kapsam) {
        (kapsam || document).querySelectorAll('select.select:not([data-osec]):not(.native-kal)').forEach(sel => {
            sel.dataset.osec = '1';
            const ozgunStil = sel.getAttribute('style') || '';
            sel.style.display = 'none';
            const tetik = document.createElement('button');
            tetik.type = 'button';
            tetik.className = 'select osec-tetik';
            if (ozgunStil) tetik.style.cssText += ozgunStil;
            const yaz = () => { tetik.textContent = sel.selectedOptions[0]?.textContent.trim() || 'Seçin...'; };
            yaz();
            sel.insertAdjacentElement('afterend', tetik);
            sel.addEventListener('change', yaz);
            tetik.addEventListener('click', () => {
                const panel = document.createElement('div');
                const ops = Array.from(sel.options);
                if (ops.length > 8) {
                    const ara = document.createElement('input');
                    ara.className = 'girdi'; ara.placeholder = 'Ara...';
                    ara.style.margin = '4px'; ara.style.width = 'calc(100% - 8px)';
                    ara.addEventListener('input', () => {
                        const q = ara.value.toLocaleLowerCase('tr');
                        panel.querySelectorAll('.osec-oge').forEach(o => o.style.display = o.textContent.toLocaleLowerCase('tr').includes(q) ? '' : 'none');
                    });
                    panel.appendChild(ara);
                }
                const liste = document.createElement('div');
                liste.className = 'osec-list';
                ops.forEach(op => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'osec-oge' + (op.selected ? ' selected' : '');
                    b.textContent = op.textContent.trim() || '—';
                    b.addEventListener('click', () => {
                        sel.value = op.value;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                        yaz(); panelKapat();
                    });
                    liste.appendChild(b);
                });
                panel.appendChild(liste);
                panelAc(tetik, panel);
                if (ops.length > 8) panel.querySelector('input')?.focus();
            });
        });
    };

    /* ---------- Özel TARİH / TARİH-SAAT / SAAT ---------- */
    function dateWrite(v) { // "YYYY-MM-DD" → "8 Temmuz 2026"
        if (!v) return '';
        const [y, m, g] = v.split('-').map(Number);
        return g + ' ' + AYLAR_TR[m - 1] + ' ' + y;
    }
    function takvimPanel(secili, minStr, onSec) {
        const bugun = new Date();
        let gy = secili ? +secili.slice(0, 4) : bugun.getFullYear();
        let ga = secili ? +secili.slice(5, 7) - 1 : bugun.getMonth();
        const panel = document.createElement('div');
        function ciz() {
            panel.innerHTML = '';
            const ust = document.createElement('div');
            ust.className = 'otarih-ust';
            ust.innerHTML = `<button type="button" class="sort_order-ok" data-y="-1">‹</button><b>${AYLAR_TR[ga]} ${gy}</b><button type="button" class="sort_order-ok" data-y="1">›</button>`;
            ust.querySelectorAll('[data-y]').forEach(b => b.addEventListener('click', () => {
                ga += +b.dataset.y; if (ga < 0) { ga = 11; gy--; } if (ga > 11) { ga = 0; gy++; }
                ciz();
            }));
            panel.appendChild(ust);
            const izgara = document.createElement('div');
            izgara.className = 'otarih-izgara';
            GUN_KISA.forEach(g => { const s = document.createElement('span'); s.className = 'otarih-gunad'; s.textContent = g; izgara.appendChild(s); });
            const ilkGun = (new Date(gy, ga, 1).getDay() + 6) % 7; // Pzt=0
            const gunSayi = new Date(gy, ga + 1, 0).getDate();
            for (let i = 0; i < ilkGun; i++) izgara.appendChild(document.createElement('span'));
            const bugunStr = bugun.toISOString().slice(0, 10);
            for (let g = 1; g <= gunSayi; g++) {
                const v = `${gy}-${String(ga + 1).padStart(2, '0')}-${String(g).padStart(2, '0')}`;
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'otarih-gun' + (v === selected ? ' secili' : '') + (v === todayStr ? ' bugun' : '');
                if (minStr && v < minStr.slice(0, 10)) b.disabled = true;
                b.textContent = g;
                b.addEventListener('click', () => onSec(v));
                izgara.appendChild(b);
            }
            panel.appendChild(izgara);
            const bottom = document.createElement('div');
            bottom.className = 'otarih-alt';
            const todayBtn = document.createElement('button');
            todayBtn.type = 'button'; todayBtn.className = 'mini-btn'; todayBtn.textContent = 'Bugün';
            todayBtn.addEventListener('click', () => onSec(todayStr));
            const clear = document.createElement('button');
            clear.type = 'button'; clear.className = 'mini-btn'; clear.style.color = 'var(--tehlike)'; clear.textContent = 'Temizle';
            clear.addEventListener('click', () => onSec(''));
            bottom.append(todayBtn, clear);
            panel.appendChild(bottom);
        }
        ciz();
        return panel;
    }
    function timeList(selectedTime, onSec) {
        const box = document.createElement('div');
        box.className = 'osaat-liste';
        // Serbest time girişi: istenen minutes writeılabilir
        const serbest = document.createElement('input');
        serbest.className = 'girdi osaat-serbest';
        serbest.placeholder = 'SS:DD yaz';
        serbest.value = selectedTime || '';
        serbest.maxLength = 5;
        const apply = () => {
            let v = serbest.value.trim().replace('.', ':').replace(',', ':');
            if (/^\d{1,2}:?\d{2}$/.test(v)) {
                if (!v.includes(':')) v = v.slice(0, -2) + ':' + v.slice(-2);
                const [s, d] = v.split(':').map(Number);
                if (s < 24 && d < 60) { onSec(String(s).padStart(2, '0') + ':' + String(d).padStart(2, '0')); return; }
            }
            serbest.style.borderColor = 'var(--tehlike)';
        };
        serbest.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
        serbest.addEventListener('input', () => serbest.style.borderColor = '');
        const applyBtn = document.createElement('button');
        applyBtn.type = 'button'; applyBtn.className = 'btn btn-sm btn-marka'; applyBtn.textContent = '✓';
        applyBtn.addEventListener('click', apply);
        const serbestSar = document.createElement('div');
        serbestSar.className = 'osaat-serbest-sar';
        serbestSar.append(serbest, applyBtn);
        box.appendChild(serbestSar);
        for (let s = 0; s < 24; s++) for (const min of [0, 30]) {
            const v = String(s).padStart(2, '0') + ':' + String(min).padStart(2, '0');
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'osec-oge' + (v === selectedTime ? ' secili' : '');
            b.textContent = v;
            b.addEventListener('click', () => onSec(v));
            box.appendChild(b);
        }
        return box;
    }
    window.ozelDateSetup = function (scope) {
        (scope || document).querySelectorAll('input[type=date]:not([data-osec]), input[type=datetime-local]:not([data-osec]), input[type=time]:not([data-osec])').forEach(inp => {
            if (inp.classList.contains('native-kal')) return;
            inp.dataset.osec = '1';
            const type = inp.type;
            inp.type = 'text';
            inp.readOnly = true;
            inp.classList.add('osec-tetik');
            inp.style.cursor = 'pointer';
            const gercek = document.createElement('input');
            gercek.type = 'hidden'; gercek.name = inp.name; inp.name = '';
            gercek.value = inp.value;
            if (inp.required) { inp.dataset.is_required = '1'; }
            inp.insertAdjacentElement('afterend', gercek);
            const show = () => {
                const v = gercek.value;
                inp.dataset.setting_value = v;
                if (!v) { inp.value = ''; return; }
                if (type === 'time') inp.value = v.slice(0, 5);
                else if (type === 'date') inp.value = dateWrite(v);
                else inp.value = dateWrite(v.slice(0, 10)) + ', ' + v.slice(11, 16);
            };
            show();
            inp.addEventListener('click', () => {
                const min = inp.getAttribute('min') || '';
                if (type === 'time') {
                    const panel = document.createElement('div');
                    panel.appendChild(timeList(gercek.value.slice(0, 5), v => { gercek.value = v; show(); gercek.dispatchEvent(new Event('change', { bubbles: true })); panelClose(); }));
                    panelOpen(inp, panel);
                    panel.querySelector('.selected')?.scrollIntoView({ block: 'center' });
                    return;
                }
                if (type === 'date') {
                    panelOpen(inp, calendarPanel(gercek.value, min, v => { gercek.value = v; show(); gercek.dispatchEvent(new Event('change', { bubbles: true })); panelClose(); }));
                    return;
                }
                // datetime-local: calendar + time yan yana
                const panel = document.createElement('div');
                panel.className = 'otarih-cift';
                let tSecim = gercek.value ? gercek.value.slice(0, 10) : '';
                let sSecim = gercek.value ? gercek.value.slice(11, 16) : '10:00';
                const bitir = () => {
                    if (!tSecim) { gercek.value = ''; }
                    else gercek.value = tSecim + 'T' + (sSecim || '10:00');
                    show(); gercek.dispatchEvent(new Event('change', { bubbles: true })); panelClose();
                };
                const tak = calendarPanel(tSecim, min, v => { if (!v) { tSecim = ''; bitir(); return; } tSecim = v; tak.querySelectorAll('.otarih-day').forEach(g => g.classList.remove('secili')); bitir(); });
                const time = timeList(sSecim, v => { sSecim = v; if (tSecim) bitir(); else { time.querySelectorAll('.selected').forEach(x => x.classList.remove('secili')); } });
                panel.append(tak, time);
                panelOpen(inp, panel);
                time.querySelector('.selected')?.scrollIntoView({ block: 'center' });
            });
        });
    };
    try { ozelSelectSetup(); ozelDateSetup(); } catch (e) { console.error('Seçici hatası:', e); }
    window.ozelPickerRefresh = () => { ozelSelectSetup(); ozelDateSetup(); };

})();
