/* ============================================================
   SADA Dijital — Uygulama JavaScript
   ============================================================ */
(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';

    /* ---------- Yardımcılar ---------- */
    const $ = (s, k = document) => k.querySelector(s);
    const $$ = (s, k = document) => Array.from(k.querySelectorAll(s));

    window.api = async function (eylem, veri = {}) {
        const fd = new FormData();
        fd.append('eylem', eylem);
        fd.append('csrf', CSRF);
        for (const k in veri) {
            if (veri[k] instanceof File || veri[k] instanceof Blob) fd.append(k, veri[k]);
            else if (Array.isArray(veri[k])) fd.append(k, JSON.stringify(veri[k]));
            else fd.append(k, veri[k] ?? '');
        }
        try {
            const r = await fetch('ajax.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.ok && j.hata) toast(j.hata, 'hata');
            return j;
        } catch (e) {
            toast('Bağlantı hatası. Tekrar deneyin.', 'hata');
            return { ok: false, hata: 'network' };
        }
    };

    /* ---------- Toast ---------- */
    window.toast = function (mesaj, tur = 'bilgi', sure = 3800) {
        const alan = $('#toastAlan');
        if (!alan) return;
        const el = document.createElement('div');
        el.className = 'toast ' + tur;
        const ikonlar = {
            basari: '<path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            hata: '<path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/>',
            bilgi: '<path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
        };
        el.innerHTML = `<svg class="toast-ikon" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">${ikonlar[tur] || ikonlar.bilgi}</svg><span>${mesaj}</span>`;
        alan.appendChild(el);
        setTimeout(() => { el.classList.add('cikis'); setTimeout(() => el.remove(), 300); }, sure);
    };

    /* ---------- Modal ---------- */
    window.modalAc = function (id) {
        const m = document.getElementById(id);
        if (m) { m.classList.add('acik'); document.body.style.overflow = 'hidden'; const ilk = m.querySelector('input,textarea,select'); if (ilk) setTimeout(() => ilk.focus(), 120); }
    };
    window.modalKapat = function (el) {
        const m = el?.closest ? el.closest('.modal-katman') : document.getElementById(el);
        if (m) { m.classList.remove('acik'); document.body.style.overflow = ''; }
    };
    document.addEventListener('click', e => {
        if (e.target.classList?.contains('modal-katman')) modalKapat(e.target);
        const acan = e.target.closest('[data-modal]');
        if (acan) { e.preventDefault(); modalAc(acan.dataset.modal); }
        const kapatan = e.target.closest('[data-modal-kapat]');
        if (kapatan) { e.preventDefault(); modalKapat(kapatan); }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { const a = $('.modal-katman.acik'); if (a) modalKapat(a); }
    });

    /* ---------- Açılır menü (dropdown) ---------- */
    document.addEventListener('click', e => {
        const btn = e.target.closest('[data-acilir-btn]');
        const acikOlan = $$('.acilir.acik');
        if (btn) {
            const grup = btn.closest('.acilir');
            const zatenAcik = grup.classList.contains('acik');
            acikOlan.forEach(a => a.classList.remove('acik'));
            if (!zatenAcik) grup.classList.add('acik');
            e.stopPropagation();
        } else if (!e.target.closest('.acilir-panel')) {
            acikOlan.forEach(a => a.classList.remove('acik'));
        }
    });

    /* ---------- Sekmeler ---------- */
    document.addEventListener('click', e => {
        const sekme = e.target.closest('[data-sekme]');
        if (!sekme) return;
        const kap = sekme.closest('.sekme-kap') || document;
        $$('[data-sekme]', kap).forEach(s => s.classList.remove('aktif'));
        $$('.sekme-icerik', kap).forEach(s => s.classList.remove('aktif'));
        sekme.classList.add('aktif');
        const hedef = $('#sekme-' + sekme.dataset.sekme, kap);
        if (hedef) hedef.classList.add('aktif');
        if (history.replaceState) history.replaceState(null, '', '#' + sekme.dataset.sekme);
    });
    // URL hash ile sekme aç
    if (location.hash) {
        const s = $(`[data-sekme="${location.hash.slice(1)}"]`);
        if (s) s.click();
    }

    /* ---------- Nav grupları: aç/kapa + hatırla ---------- */
    $$('.nav-grup').forEach(grup => {
        const anahtar = 'navGrup_' + grup.dataset.navGrup;
        // Kayıtlı durum (aktif sayfa içeren grup her zaman açık)
        if (!grup.classList.contains('aktif-grup')) {
            const kayit = localStorage.getItem(anahtar);
            if (kayit === 'acik') grup.classList.add('acik');
        }
        grup.querySelector('[data-grup-btn]').addEventListener('click', () => {
            grup.classList.toggle('acik');
            localStorage.setItem(anahtar, grup.classList.contains('acik') ? 'acik' : 'kapali');
        });
    });

    /* ---------- Kenar çubuğu (mobil) ---------- */
    const kenar = $('#kenar'), karartma = $('[data-karartma]');
    $('[data-kenar-ac]')?.addEventListener('click', () => { kenar.classList.add('acik'); karartma.classList.add('acik'); });
    const kenarKapat = () => { kenar?.classList.remove('acik'); karartma?.classList.remove('acik'); };
    $('[data-kenar-kapat]')?.addEventListener('click', kenarKapat);
    karartma?.addEventListener('click', kenarKapat);

    /* ---------- Tema değiştirme ---------- */
    $$('.tema-nokta').forEach(nokta => {
        nokta.addEventListener('click', async () => {
            const tema = nokta.dataset.tema;
            document.documentElement.setAttribute('data-theme', tema);
            $$('.tema-nokta').forEach(n => n.classList.toggle('secili', n === nokta));
            await api('tema_degistir', { tema });
        });
    });

    /* ---------- Bildirimler ---------- */
    document.addEventListener('click', async e => {
        const oku = e.target.closest('[data-tumunu-oku]');
        if (oku) {
            e.preventDefault(); e.stopPropagation();
            await api('bildirim_tumunu_oku');
            $$('.bildirim-oge.yeni').forEach(b => b.classList.remove('yeni'));
            $('#bildirimSayac')?.remove();
            oku.remove();
        }
        const bildirimSil = e.target.closest('[data-bildirim-sil]');
        if (bildirimSil) {
            e.preventDefault(); e.stopPropagation();
            const oge = bildirimSil.closest('[data-bildirim]');
            await api('bildirim_sil', { id: oge.dataset.bildirim });
            oge.remove();
            return;
        }
        const bldrm = e.target.closest('[data-bildirim]');
        if (bldrm && bldrm.classList.contains('yeni')) {
            api('bildirim_oku', { id: bldrm.dataset.bildirim });
        }
    });

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
                if (yeniDurum !== eskiDurum) toast('Görev "' + sutun.querySelector('.kanban-baslik').textContent + '" durumuna taşındı', 'basari', 2200);
            } else {
                // Kilit vb. reddedildi: kartı eski sütununa geri koy
                kart.dataset.durum = eskiDurum;
                const eskiListe = $(`.kanban-sutun[data-durum="${eskiDurum}"] .kanban-liste`);
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
                    h += `<div class="arama-grup">${ikonlar[grup] || ''} ${grup}</div>`;
                    j.sonuclar[grup].forEach(s => {
                        h += `<a href="${s.link}" class="arama-oge"><span>${s.ad.replace(/</g, '&lt;')}</span><span class="hucre-alt">${s.alt || ''}</span></a>`;
                    });
                }
                panel.innerHTML = h || '<div class="bos-mini">Sonuç bulunamadı</div>';
                panel.classList.add('acik');
            }, 280);
        });
        document.addEventListener('click', e => {
            if (!e.target.closest('.arama-global')) panel.classList.remove('acik');
        });
        aramaGirdi.addEventListener('keydown', e => { if (e.key === 'Escape') panel.classList.remove('acik'); });
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
                b.className = 'mention-oge' + (i === 0 ? ' aktif' : '');
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
            // id'yi gizli alana ekle
            const gizli = (ta.closest('form') || kap).querySelector('.mention-idler');
            if (gizli) {
                const mevcut = gizli.value ? JSON.parse(gizli.value) : [];
                if (!mevcut.includes(kisi.id)) mevcut.push(kisi.id);
                gizli.value = JSON.stringify(mevcut);
            }
            kapat();
            ta.focus();
        }
        ta.addEventListener('input', () => {
            const sorgu = sorguBul();
            if (sorgu === null) { kapat(); return; }
            goster(sorgu);
        });
        ta.addEventListener('keydown', e => {
            if (!acilir) return;
            const ogeler = acilir.querySelectorAll('.mention-oge');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                aktifIndex = (aktifIndex + (e.key === 'ArrowDown' ? 1 : -1) + ogeler.length) % ogeler.length;
                ogeler.forEach((o, i) => o.classList.toggle('aktif', i === aktifIndex));
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                sec(eslesen[aktifIndex]);
            } else if (e.key === 'Escape') kapat();
        });
        ta.addEventListener('blur', () => setTimeout(kapat, 180));
    }
    $$('textarea[data-mention]').forEach(mentionKur);
    window.sadaMentionKur = mentionKur;

    /* ---------- Görev tablosu: sütun sıralama ---------- */
    document.addEventListener('click', e => {
        const th = e.target.closest('th.siralanir');
        if (!th) return;
        const tablo = th.closest('table');
        const tbody = tablo.querySelector('tbody');
        const index = Array.from(th.parentElement.children).indexOf(th);
        const yon = th.dataset.yon === 'asc' ? 'desc' : 'asc';
        tablo.querySelectorAll('th.siralanir').forEach(t => { delete t.dataset.yon; const i = t.querySelector('.sira-isaret'); if (i) i.textContent = '↕'; });
        th.dataset.yon = yon;
        const isaret = th.querySelector('.sira-isaret'); if (isaret) isaret.textContent = yon === 'asc' ? '↑' : '↓';
        const satirlar = Array.from(tbody.querySelectorAll('tr'));
        satirlar.sort((a, b) => {
            const av = (a.children[index]?.dataset.sirala ?? a.children[index]?.textContent ?? '').trim();
            const bv = (b.children[index]?.dataset.sirala ?? b.children[index]?.textContent ?? '').trim();
            const an = parseFloat(av), bn = parseFloat(bv);
            const sonuc = (!isNaN(an) && !isNaN(bn)) ? an - bn : av.localeCompare(bv, 'tr');
            return yon === 'asc' ? sonuc : -sonuc;
        });
        satirlar.forEach(s => tbody.appendChild(s));
    });

    /* ---------- Görev tablosu: hücre içi düzenleme ---------- */
    window.hucreKaydet = async function (el, id, alan) {
        const j = await api('gorev_alan', { id, alan, deger: el.value });
        if (j.ok) {
            const hucre = el.closest('td');
            hucre.classList.remove('hucre-kaydedildi');
            void hucre.offsetWidth; // animasyonu tetikle
            hucre.classList.add('hucre-kaydedildi');
        } else if (el.dataset.eski !== undefined) {
            el.value = el.dataset.eski; // kilit reddettiyse geri al
        }
    };

    /* ---------- Satır sıralama okları (akış/form editörleri) ---------- */
    document.addEventListener('click', e => {
        const ok = e.target.closest('[data-sira-yon]');
        if (!ok) return;
        e.preventDefault();
        const satir = ok.closest('[data-siralanabilir]');
        if (!satir) return;
        if (ok.dataset.siraYon === 'yukari' && satir.previousElementSibling) {
            satir.parentElement.insertBefore(satir, satir.previousElementSibling);
        } else if (ok.dataset.siraYon === 'asagi' && satir.nextElementSibling) {
            satir.parentElement.insertBefore(satir.nextElementSibling, satir);
        }
    });

    /* ---------- Arama filtreleme (istemci tarafı) ---------- */
    $$('[data-arama]').forEach(input => {
        input.addEventListener('input', () => {
            const q = input.value.toLowerCase().trim();
            $$(input.dataset.arama).forEach(oge => {
                const metin = oge.dataset.ara || oge.textContent;
                oge.style.display = metin.toLowerCase().includes(q) ? '' : 'none';
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
            const deger = pill.dataset.deger;
            const hedef = grup.dataset.pillGrup;
            $$(hedef).forEach(oge => {
                oge.style.display = (deger === '' || oge.dataset.filtre === deger) ? '' : 'none';
            });
        });
    });

    /* ---------- İlerleme çubuğu animasyonu ---------- */
    setTimeout(() => {
        $$('.ilerleme-dolu[data-oran]').forEach(el => { el.style.width = el.dataset.oran + '%'; });
    }, 200);

    /* ---------- Sayaç animasyonu ---------- */
    $$('[data-sayac]').forEach(el => {
        const hedef = parseFloat(el.dataset.sayac);
        if (isNaN(hedef)) return;
        let mevcut = 0;
        const adim = hedef / 32;
        const zamanlayici = setInterval(() => {
            mevcut += adim;
            if (mevcut >= hedef) { mevcut = hedef; clearInterval(zamanlayici); }
            el.textContent = Number.isInteger(hedef) ? Math.round(mevcut) : mevcut.toFixed(1);
        }, 22);
    });

    /* ---------- Mesajlaşmada en alta kaydır ---------- */
    const sohbetGovde = $('.sohbet-govde');
    if (sohbetGovde) sohbetGovde.scrollTop = sohbetGovde.scrollHeight;

    /* ---------- Canlı senkron: 10 sn'de bir değişiklik kontrolü ----------
       Sayfa window.sadaCanli = {baglam, id, hash} tanımlarsa etkinleşir.
       Kullanıcı yazarken veya modal açıkken tazeleme ertelenir. */
    window.canliYenile = async function () {
        if (!window.sadaCanli) return;
        const j = await api('canli_durum', { baglam: sadaCanli.baglam, id: sadaCanli.id || 0 });
        if (j.ok) sadaCanli.hash = j.hash;
    };
    function mesgulMu() {
        const a = document.activeElement;
        if (a && (a.tagName === 'TEXTAREA' || a.tagName === 'INPUT' || a.tagName === 'SELECT')) return true;
        if ($('.modal-katman.acik') || $('.mention-acilir') || $('.kanban-kart.suruklenuyor')) return true;
        return false;
    }
    setInterval(async () => {
        if (!window.sadaCanli || document.hidden || mesgulMu()) return;
        try {
            const j = await api('canli_durum', { baglam: sadaCanli.baglam, id: sadaCanli.id || 0 });
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
        (kapsam || document).querySelectorAll('select.secim:not([data-osec]):not(.native-kal)').forEach(sel => {
            sel.dataset.osec = '1';
            const ozgunStil = sel.getAttribute('style') || '';
            sel.style.display = 'none';
            const tetik = document.createElement('button');
            tetik.type = 'button';
            tetik.className = 'secim osec-tetik';
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
                liste.className = 'osec-liste';
                ops.forEach(op => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'osec-oge' + (op.selected ? ' secili' : '');
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
    function tarihYaz(v) { // "YYYY-MM-DD" → "8 Temmuz 2026"
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
            ust.innerHTML = `<button type="button" class="sira-ok" data-y="-1">‹</button><b>${AYLAR_TR[ga]} ${gy}</b><button type="button" class="sira-ok" data-y="1">›</button>`;
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
                b.className = 'otarih-gun' + (v === secili ? ' secili' : '') + (v === bugunStr ? ' bugun' : '');
                if (minStr && v < minStr.slice(0, 10)) b.disabled = true;
                b.textContent = g;
                b.addEventListener('click', () => onSec(v));
                izgara.appendChild(b);
            }
            panel.appendChild(izgara);
            const alt = document.createElement('div');
            alt.className = 'otarih-alt';
            const bugunBtn = document.createElement('button');
            bugunBtn.type = 'button'; bugunBtn.className = 'mini-btn'; bugunBtn.textContent = 'Bugün';
            bugunBtn.addEventListener('click', () => onSec(bugunStr));
            const temizle = document.createElement('button');
            temizle.type = 'button'; temizle.className = 'mini-btn'; temizle.style.color = 'var(--tehlike)'; temizle.textContent = 'Temizle';
            temizle.addEventListener('click', () => onSec(''));
            alt.append(bugunBtn, temizle);
            panel.appendChild(alt);
        }
        ciz();
        return panel;
    }
    function saatListesi(seciliSaat, onSec) {
        const kutu = document.createElement('div');
        kutu.className = 'osaat-liste';
        // Serbest saat girişi: istenen dakika yazılabilir
        const serbest = document.createElement('input');
        serbest.className = 'girdi osaat-serbest';
        serbest.placeholder = 'SS:DD yaz';
        serbest.value = seciliSaat || '';
        serbest.maxLength = 5;
        const uygula = () => {
            let v = serbest.value.trim().replace('.', ':').replace(',', ':');
            if (/^\d{1,2}:?\d{2}$/.test(v)) {
                if (!v.includes(':')) v = v.slice(0, -2) + ':' + v.slice(-2);
                const [s, d] = v.split(':').map(Number);
                if (s < 24 && d < 60) { onSec(String(s).padStart(2, '0') + ':' + String(d).padStart(2, '0')); return; }
            }
            serbest.style.borderColor = 'var(--tehlike)';
        };
        serbest.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); uygula(); } });
        serbest.addEventListener('input', () => serbest.style.borderColor = '');
        const uygulaBtn = document.createElement('button');
        uygulaBtn.type = 'button'; uygulaBtn.className = 'btn btn-sm btn-marka'; uygulaBtn.textContent = '✓';
        uygulaBtn.addEventListener('click', uygula);
        const serbestSar = document.createElement('div');
        serbestSar.className = 'osaat-serbest-sar';
        serbestSar.append(serbest, uygulaBtn);
        kutu.appendChild(serbestSar);
        for (let s = 0; s < 24; s++) for (const dk of [0, 30]) {
            const v = String(s).padStart(2, '0') + ':' + String(dk).padStart(2, '0');
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'osec-oge' + (v === seciliSaat ? ' secili' : '');
            b.textContent = v;
            b.addEventListener('click', () => onSec(v));
            kutu.appendChild(b);
        }
        return kutu;
    }
    window.ozelTarihKur = function (kapsam) {
        (kapsam || document).querySelectorAll('input[type=date]:not([data-osec]), input[type=datetime-local]:not([data-osec]), input[type=time]:not([data-osec])').forEach(inp => {
            if (inp.classList.contains('native-kal')) return;
            inp.dataset.osec = '1';
            const tur = inp.type;
            inp.type = 'text';
            inp.readOnly = true;
            inp.classList.add('osec-tetik');
            inp.style.cursor = 'pointer';
            const gercek = document.createElement('input');
            gercek.type = 'hidden'; gercek.name = inp.name; inp.name = '';
            gercek.value = inp.value;
            if (inp.required) { inp.dataset.zorunlu = '1'; }
            inp.insertAdjacentElement('afterend', gercek);
            const goster = () => {
                const v = gercek.value;
                inp.dataset.deger = v;
                if (!v) { inp.value = ''; return; }
                if (tur === 'time') inp.value = v.slice(0, 5);
                else if (tur === 'date') inp.value = tarihYaz(v);
                else inp.value = tarihYaz(v.slice(0, 10)) + ', ' + v.slice(11, 16);
            };
            goster();
            inp.addEventListener('click', () => {
                const min = inp.getAttribute('min') || '';
                if (tur === 'time') {
                    const panel = document.createElement('div');
                    panel.appendChild(saatListesi(gercek.value.slice(0, 5), v => { gercek.value = v; goster(); gercek.dispatchEvent(new Event('change', { bubbles: true })); panelKapat(); }));
                    panelAc(inp, panel);
                    panel.querySelector('.secili')?.scrollIntoView({ block: 'center' });
                    return;
                }
                if (tur === 'date') {
                    panelAc(inp, takvimPanel(gercek.value, min, v => { gercek.value = v; goster(); gercek.dispatchEvent(new Event('change', { bubbles: true })); panelKapat(); }));
                    return;
                }
                // datetime-local: takvim + saat yan yana
                const panel = document.createElement('div');
                panel.className = 'otarih-cift';
                let tSecim = gercek.value ? gercek.value.slice(0, 10) : '';
                let sSecim = gercek.value ? gercek.value.slice(11, 16) : '10:00';
                const bitir = () => {
                    if (!tSecim) { gercek.value = ''; }
                    else gercek.value = tSecim + 'T' + (sSecim || '10:00');
                    goster(); gercek.dispatchEvent(new Event('change', { bubbles: true })); panelKapat();
                };
                const tak = takvimPanel(tSecim, min, v => { if (!v) { tSecim = ''; bitir(); return; } tSecim = v; tak.querySelectorAll('.otarih-gun').forEach(g => g.classList.remove('secili')); bitir(); });
                const saat = saatListesi(sSecim, v => { sSecim = v; if (tSecim) bitir(); else { saat.querySelectorAll('.secili').forEach(x => x.classList.remove('secili')); } });
                panel.append(tak, saat);
                panelAc(inp, panel);
                saat.querySelector('.secili')?.scrollIntoView({ block: 'center' });
            });
        });
    };
    try { ozelSelectKur(); ozelTarihKur(); } catch (e) { console.error('Seçici hatası:', e); }
    window.ozelSeciciYenile = () => { ozelSelectKur(); ozelTarihKur(); };

})();
