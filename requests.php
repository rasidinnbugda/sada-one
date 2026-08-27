<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$forms = rows("SELECT * FROM form_templates WHERE is_active=1 ORDER BY name");

if (is_staff()) {
    $requests = rows("SELECT t.*, f.name form_name, ug.name sender_name, d.name client_name, p.name project_name FROM requests t JOIN form_templates f ON f.id=t.template_id LEFT JOIN users ug ON ug.id=t.sender_id LEFT JOIN clients d ON d.id=t.client_id LEFT JOIN projects p ON p.id=t.project_id ORDER BY FIELD(t.status,'yeni','inceleniyor','gorev_olusturuldu','tamamlandi','reddedildi'), t.id DESC");
} else {
    $requests = rows("SELECT t.*, f.name form_name, ug.name sender_name, p.name project_name FROM requests t JOIN form_templates f ON f.id=t.template_id LEFT JOIN users ug ON ug.id=t.sender_id LEFT JOIN projects p ON p.id=t.project_id WHERE t.sender_id=? ORDER BY t.id DESC", [$u['id']]);
}

// Project list for the customer (to pick in the request form)
$customerProjects = [];
if (is_customer()) {
    [$mdIn, $mdP] = in_clause(customer_client_ids());
    $customerProjects = rows("SELECT id, name FROM projects WHERE client_id IN $mdIn AND status='aktif' ORDER BY name", $mdP);
}
$tumClients = is_staff() ? rows("SELECT id, name FROM clients WHERE status='aktif' ORDER BY name") : [];

page_start('Talepler', 'requests');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik"><?= is_staff() ? 'Gelen Talepler' : 'Taleplerim' ?></div><div class="sayfa-alt"><?= is_staff() ? 'Müşteri ve ekip talepleri' : 'Oluşturduğunuz talepler ve durumları' ?></div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalNewRequest"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Talep</button></div>
</div>

<?php if (is_staff()): ?>
<div class="filtre-bar">
    <div class="pill-filtre" data-pill-grup="#requestList .talep-sat">
        <button class="pill aktif" data-setting_value="">Tümü</button>
        <?php foreach (REQUEST_STATUSES as $k => $v): ?><button class="pill" data-setting_value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$requests): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 10h8m-8 4h4m9-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="bos-baslik">Talep yok</div><div class="bos-metin"><?= is_staff() ? 'Henüz gelen bir talep bulunmuyor.' : 'Bir iş, revizyon veya çekim talebi oluşturmak için başlayın.' ?></div><button class="btn btn-marka" data-modal="modalNewRequest">Yeni Talep Oluştur</button></div>
<?php else: ?>
<div class="tablo-sar"><table class="tablo" id="requestList"><thead><tr><th>Talep</th><?php if (is_staff()): ?><th>Gönderen</th><th>Dosya/Proje</th><?php endif; ?><th>Tarih</th><th>Durum</th><th></th></tr></thead><tbody>
    <?php foreach ($requests as $t): ?>
    <tr class="tik talep-sat" data-filter="<?= $t['status'] ?>" onclick="location.href='request.php?id=<?= $t['id'] ?>'">
        <td><div class="hucre-ana"><?= e($t['title']) ?></div><div class="hucre-alt"><?= e($t['form_name']) ?></div></td>
        <?php if (is_staff()): ?>
        <td><?= e($t['sender_name']) ?></td>
        <td class="kucuk"><?= e($t['client_name'] ?? '—') ?><?= $t['project_name'] ? ' / ' . e($t['project_name']) : '' ?></td>
        <?php endif; ?>
        <td class="kucuk"><?= format_date($t['created']) ?></td>
        <td><?= badge($t['status'], REQUEST_STATUSES) ?></td>
        <td><svg width="16" fill="none" stroke="var(--muted)" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></td>
    </tr>
    <?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<!-- New request modal: pick form type → fill in the fields -->
<div class="modal-katman" id="modalNewRequest">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik">Yeni Talep</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <div class="modal-govde">
        <div id="talepAdim1">
            <div class="hucre-alt mb-3">Ne tür bir talep oluşturmak istiyorsunuz?</div>
            <div class="izgara izgara-2">
                <?php foreach ($forms as $f): ?>
                <button class="kart kart-tik" style="text-align:left;padding:16px" onclick="requestFormOpen(<?= $f['id'] ?>)">
                    <div class="kalin"><?= e($f['name']) ?></div>
                    <?php if ($f['description']): ?><div class="hucre-alt mt-1"><?= e($f['description']) ?></div><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div id="talepAdim2" style="display:none">
            <button class="btn btn-sm btn-hayalet mb-3" onclick="requestGeri()">← Geri</button>
            <form data-ajax="request_send" id="requestForm">
                <input type="hidden" name="template_id" id="requestTemplateId">
                <?php if ($customerProjects): ?>
                <div class="form-grup"><label class="form-etiket">İlgili Proje</label><select name="project_id" class="secim"><option value="">— Genel</option><?php foreach ($customerProjects as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
                <?php elseif ($tumClients): ?>
                <div class="form-grup"><label class="form-etiket">Dosya</label><select name="client_id" class="secim"><option value="">—</option><?php foreach ($tumClients as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div id="requestFields"></div>
                <button type="submit" class="btn btn-marka btn-blok mt-2">Talebi Gönder</button>
            </form>
        </div>
    </div>
    </div>
</div>

<script>
const formFields = <?= json_encode(array_reduce($forms, function ($acc, $f) {
    $acc[$f['id']] = ['name' => $f['name'], 'fields' => rows("SELECT * FROM form_fields WHERE template_id=? ORDER BY sort_order", [$f['id']])];
    return $acc;
}, []), JSON_UNESCAPED_UNICODE) ?>;

function requestFormOpen(id) {
    const f = formFields[id]; if (!f) return;
    document.getElementById('requestTemplateId').value = id;
    // Bölümler sekmeye dönüşür: her bölüm bir sekme, öncesindeki alanlar "Genel"
    const bolumler = f.fields.filter(a => a.type === 'bolum');
    const sekmeli = bolumler.length > 0;
    let h = '';
    let panelNo = 0;
    if (sekmeli) {
        const adlar = f.fields[0].type === 'bolum' ? [] : ['Genel'];
        bolumler.forEach(b => adlar.push(b.tag));
        h += `<div class="rt-sekmeler">` + adlar.map((ad, i) =>
            `<button type="button" class="rt-sekme${i === 0 ? ' aktif' : ''}" onclick="rtSekmeSec(this, ${i})"><span class="rt-no">${i + 1}</span><span>${esc(ad)}</span></button>`).join('') + `</div>`;
        h += `<div class="rt-ilerleme"><div class="rt-ilerleme-dolu" style="width:0%"></div></div><div class="rt-ilerleme-metin hucre-alt mb-2"></div>`;
        h += `<div class="rt-panel${panelNo === 0 ? ' aktif' : ''}" data-rt="${panelNo}"><div class="talep-izgara">`;
    }
    // Kısa alanlar iki sütuna oturur; uzun/bölüm alanları tam genişlik kaplar
    const kisa = ['metin', 'tarih', 'sayi', 'secim'];
    f.fields.forEach((a, fi) => {
        const is_required = a.is_required == 1 ? ' required' : '';
        const star = a.is_required == 1 ? ' <span class="zorunlu">*</span>' : '';
        if (a.type === 'bolum') {
            if (sekmeli) {
                // önceki paneli kapat, bu bölümün panelini aç
                if (!(fi === 0)) h += `</div></div>`;
                else if (panelNo === 0 && fi === 0) h = h.replace(`<div class="rt-panel aktif" data-rt="0"><div class="talep-izgara">`, '');
                panelNo = (fi === 0) ? 0 : panelNo + 1;
                h += `<div class="rt-panel${(fi === 0 && panelNo === 0) ? ' aktif' : ''}" data-rt="${panelNo}">`;
                if ((a.options || '').trim()) h += `<div class="hucre-alt mb-2" style="white-space:pre-wrap">${esc(a.options.trim())}</div>`;
                h += `<div class="talep-izgara">`;
            } else {
                h += `<div class="talep-bolum"><div class="talep-bolum-baslik">${esc(a.tag)}</div>`;
                if ((a.options || '').trim()) h += `<div class="hucre-alt mt-1" style="white-space:pre-wrap">${esc(a.options.trim())}</div>`;
                h += `</div>`;
            }
            return;
        }
        h += `<div class="form-grup${kisa.includes(a.type) ? '' : ' talep-genis'}"><label class="form-etiket">${esc(a.tag)}${star}</label>`;
        if (a.type === 'uzun_metin') h += `<textarea name="alan_${a.id}" class="metin-alani"${is_required}></textarea>`;
        else if (a.type === 'secim') {
            const satirlar = (a.options || '').split('\n').map(s => s.trim()).filter(Boolean);
            const digerVar = satirlar.includes('__diger__');
            const opsiyonlar = satirlar.filter(s => s !== '__diger__');
            if (digerVar) {
                // "Diğer" seçilince serbest metin açılır; gerçek değer gizli alanda taşınır
                h += `<input type="hidden" name="alan_${a.id}" class="sd-deger">`;
                h += `<select class="secim sd-secim" onchange="secimDiger(this)"${is_required}><option value="">— Seçin</option>`;
                opsiyonlar.forEach(s => { h += `<option value="${esc(s)}">${esc(s)}</option>`; });
                h += `<option value="__diger__">Diğer...</option></select>`;
                h += `<input type="text" class="girdi mt-1 sd-metin" placeholder="Lütfen belirtin..." style="display:none" oninput="secimDiger(this.closest('.form-grup').querySelector('.sd-secim'))">`;
            } else {
                h += `<select name="alan_${a.id}" class="secim"${is_required}><option value="">— Seçin</option>`;
                opsiyonlar.forEach(s => { h += `<option value="${esc(s)}">${esc(s)}</option>`; });
                h += `</select>`;
            }
        }
        else if (a.type === 'coklu_secim') {
            const satirlar = (a.options || '').split('\n').map(s => s.trim()).filter(Boolean);
            const digerVar = satirlar.includes('__diger__');
            h += `<input type="hidden" name="alan_${a.id}" class="cs-deger">`;
            h += `<div class="izgara izgara-2" style="gap:6px">`;
            satirlar.filter(s => s !== '__diger__').forEach(s => {
                h += `<label class="satir-esnek kucuk" style="gap:8px;padding:8px 11px;background:var(--surface-2);border-radius:9px;cursor:pointer"><input type="checkbox" class="cs-kutu" value="${esc(s)}" onchange="csGuncelle(this)"> <span>${esc(s)}</span></label>`;
            });
            if (digerVar) {
                h += `<label class="satir-esnek kucuk" style="gap:8px;padding:8px 11px;background:var(--surface-2);border-radius:9px;cursor:pointer"><input type="checkbox" class="cs-kutu cs-diger" value="" onchange="csGuncelle(this)"> <span style="flex-shrink:0">Diğer:</span><input type="text" class="girdi cs-diger-metin" style="padding:4px 8px;font-size:12.5px" oninput="csGuncelle(this)"></label>`;
            }
            h += `</div>`;
        }
        else if (a.type === 'tarih') h += `<input type="date" name="alan_${a.id}" class="girdi"${is_required}>`;
        else if (a.type === 'sayi') h += `<input type="number" name="alan_${a.id}" class="girdi"${is_required}>`;
        else if (a.type === 'dosya') h += `<input type="file" name="alan_${a.id}" class="girdi"${is_required}>`;
        else if (a.type === 'coklu_dosya') h += `<input type="file" name="alan_${a.id}" class="girdi" multiple${is_required}><div class="form-ipucu">Birden fazla dosyayı Ctrl ile seçebilirsiniz.</div>`;
        else h += `<input type="text" name="alan_${a.id}" class="girdi"${is_required}>`;
        h += `</div>`;
    });
    if (sekmeli) h += `</div></div>`;
    document.getElementById('requestFields').innerHTML = h;
    document.getElementById('requestFields').className = sekmeli ? '' : 'talep-izgara';
    if (sekmeli) {
        const kap = document.getElementById('requestFields');
        kap.querySelector('.rt-panel')?.setAttribute('data-gezildi', '1');
        kap.addEventListener('input', rtIlerleme);
        kap.addEventListener('change', rtIlerleme);
        rtIlerleme();
    }
    if (window.ozelPickerRefresh) ozelPickerRefresh();
    document.getElementById('talepAdim1').style.display = 'none';
    document.getElementById('talepAdim2').style.display = 'block';
}
function rtSekmeSec(btn, no) {
    const kap = document.getElementById('requestFields');
    kap.querySelectorAll('.rt-sekme').forEach((s, i) => s.classList.toggle('aktif', i === no));
    kap.querySelectorAll('.rt-panel').forEach(p => {
        const aktif = +p.dataset.rt === no;
        p.classList.toggle('aktif', aktif);
        if (aktif) p.setAttribute('data-gezildi', '1');
    });
    rtIlerleme();
}
// Alan doluluk kontrolü: gizli değer taşıyıcıları (osec/diğer/çoklu seçim) dahil
function rtDoluMu(el) {
    if (el.type === 'file') return el.files.length > 0;
    return (el.value || '').trim() !== '';
}
function rtIlerleme() {
    const kap = document.getElementById('requestFields');
    if (!kap.querySelector('.rt-sekmeler')) return;
    let zorunluToplam = 0, zorunluDolu = 0;
    kap.querySelectorAll('.rt-panel').forEach(p => {
        // Değer taşıyıcı, form grubundaki alan_* adlı eleman: özel seçiciler (tarih)
        // gerçek inputu type=hidden yapıp zorunluluğu adsız tetiğe taşıdığı için
        // hem gizli taşıyıcıyı saymalı hem zorunluluğu yıldızdan okumalıyız
        const gruplar = [...p.querySelectorAll('.form-grup')];
        const alanlar = gruplar.map(g => g.querySelector('[name^="alan_"]')).filter(Boolean);
        const zorunlular = gruplar.filter(g => g.querySelector('.zorunlu')).map(g => g.querySelector('[name^="alan_"]')).filter(Boolean);
        zorunluToplam += zorunlular.length;
        const doluZorunlu = zorunlular.filter(rtDoluMu).length;
        zorunluDolu += doluZorunlu;
        const tamam = zorunlular.length
            ? doluZorunlu === zorunlular.length
            : (p.getAttribute('data-gezildi') === '1' || alanlar.some(rtDoluMu));
        const sekme = kap.querySelectorAll('.rt-sekme')[+p.dataset.rt];
        if (sekme) {
            sekme.classList.toggle('tamam', tamam);
            sekme.querySelector('.rt-no').textContent = tamam ? '✓' : (+p.dataset.rt + 1);
        }
    });
    const paneller = [...kap.querySelectorAll('.rt-panel')];
    const tamamPanel = kap.querySelectorAll('.rt-sekme.tamam').length;
    const yuzde = zorunluToplam ? Math.round(zorunluDolu / zorunluToplam * 100) : Math.round(tamamPanel / paneller.length * 100);
    kap.querySelector('.rt-ilerleme-dolu').style.width = yuzde + '%';
    kap.querySelector('.rt-ilerleme-metin').textContent = zorunluToplam
        ? `${zorunluDolu}/${zorunluToplam} zorunlu alan tamamlandı (%${yuzde})`
        : `${tamamPanel}/${paneller.length} bölüm tamamlandı`;
}
// Gizli sekmedeki zorunlu alan doldurulmadıysa tarayıcı sessizce engeller;
// invalid olayını yakalayıp o alanın sekmesine geçiyoruz ki kullanıcı görsün
document.addEventListener('invalid', e => {
    const panel = e.target.closest('.rt-panel');
    if (panel && !panel.classList.contains('aktif')) rtSekmeSec(null, +panel.dataset.rt);
}, true);
function csGuncelle(kutu) {
    const grup = kutu.closest('.form-grup');
    const degerler = [...grup.querySelectorAll('.cs-kutu:checked')].filter(k => !k.classList.contains('cs-diger')).map(k => k.value);
    const diger = grup.querySelector('.cs-diger');
    if (diger && diger.checked) {
        const metin = grup.querySelector('.cs-diger-metin').value.trim();
        degerler.push(metin ? 'Diğer: ' + metin : 'Diğer');
    }
    grup.querySelector('.cs-deger').value = degerler.join(', ');
}
function secimDiger(sel) {
    const grup = sel.closest('.form-grup');
    const metin = grup.querySelector('.sd-metin');
    const gizli = grup.querySelector('.sd-deger');
    if (sel.value === '__diger__') {
        metin.style.display = '';
        const v = metin.value.trim();
        gizli.value = v ? 'Diğer: ' + v : '';
    } else {
        metin.style.display = 'none';
        gizli.value = sel.value;
    }
}
function requestGeri() { document.getElementById('talepAdim2').style.display = 'none'; document.getElementById('talepAdim1').style.display = 'block'; }
</script>
<?php page_end(); ?>
