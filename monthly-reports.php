<?php
/**
 * SADA One — Monthly Reports
 * Monthly work reports per client file are filled in on the panel.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (is_intern()) { header('Location: index.php'); exit; }

$clients = rows("SELECT c.id, c.name, c.manager_id, u.name manager_name FROM clients c LEFT JOIN users u ON u.id=c.manager_id WHERE c.status='aktif' ORDER BY c.name");
$currentPeriod = date('Y-m');
// Fill-status of the current period per client (for the tracking grid)
$periodStatus = [];
foreach (rows("SELECT client_id, status FROM monthly_reports WHERE period=?", [$currentPeriod]) as $ps) $periodStatus[$ps['client_id']] = $ps['status'];
$reports = rows("SELECT r.*, d.name client_name, y.name author_name FROM monthly_reports r JOIN clients d ON d.id=r.client_id JOIN users y ON y.id=r.author_id ORDER BY r.period DESC, d.name");

// Report to edit (if client file + period are selected)
$secClient = (int)($_GET['client'] ?? 0);
$secPeriod = preg_match('/^\d{4}-\d{2}$/', $_GET['period'] ?? '') ? $_GET['period'] : date('Y-m');
$is_active = $secClient ? row("SELECT * FROM monthly_reports WHERE client_id=? AND period=?", [$secClient, $secPeriod]) : null;

$periodName = function (string $d): string {
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    [$y, $a] = explode('-', $d);
    return $aylar[(int)$a] . ' ' . $y;
};

page_start('Aylık Raporlar', 'mreports');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Aylık Raporlar</div><div class="sayfa-alt">Müşteri dosyaları için dönem raporları — özet, yapılanlar, metrikler, gelecek plan</div></div>
</div>

<!-- This month at a glance: who has filled in, who has not -->
<div class="kart mb-3">
    <div class="satir-esnek arasi mb-2">
        <div class="kart-baslik" style="font-size:15px">Bu Ay (<?= e($currentPeriod) ?>) Doldurma Durumu</div>
        <span class="kucuk metin-muted"><?= count(array_filter($periodStatus, fn($s) => $s === 'tamamlandi')) ?>/<?= count($clients) ?> tamamlandı</span>
    </div>
    <div class="izgara" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px">
        <?php foreach ($clients as $cl):
            $st = $periodStatus[$cl['id']] ?? null;
            $rozet = $st === 'tamamlandi' ? '<span class="rozet r-tamamlandi">Tamamlandı</span>' : ($st === 'taslak' ? '<span class="rozet r-devam">Taslak</span>' : '<span class="rozet r-gecikti">Boş</span>'); ?>
        <a href="?client=<?= $cl['id'] ?>&period=<?= $currentPeriod ?>" class="satir-esnek arasi kucuk" style="padding:9px 12px;background:var(--surface-2);border-radius:10px;gap:8px">
            <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><b><?= e($cl['name']) ?></b><br><span class="metin-muted" style="font-size:11px"><?= $cl['manager_name'] ? e($cl['manager_name']) : 'sorumlu atanmadı' ?></span></span>
            <?= $rozet ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="izgara" style="grid-template-columns:340px 1fr;align-items:start">
    <div class="kart">
        <div class="kart-baslik mb-2">Rapor Seç / Başlat</div>
        <form method="get">
            <div class="form-grup"><label class="form-etiket">Dosya</label>
                <select name="client" class="secim" onchange="this.form.submit()">
                    <option value="">Seçin...</option>
                    <?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $secClient === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-grup"><label class="form-etiket">Dönem</label>
                <input type="month" name="period" class="girdi native-kal" value="<?= e($secPeriod) ?>" onchange="this.form.submit()"></div>
        </form>

        <div class="kart-baslik mb-2 mt-3" style="font-size:14px">Doldurulan Raporlar</div>
        <div class="dikey" style="gap:5px;max-height:420px;overflow-y:auto">
            <?php if (!$reports): ?><div class="metin-muted kucuk">Henüz rapor yok.</div><?php endif; ?>
            <?php foreach ($reports as $r): ?>
            <a href="?client=<?= $r['client_id'] ?>&period=<?= $r['period'] ?>" class="satir-esnek arasi kucuk" style="padding:9px 11px;background:var(--surface-2);border-radius:9px">
                <span><b><?= e($r['client_name']) ?></b> · <?= $periodName($r['period']) ?></span>
                <?= $r['status'] === 'tamamlandi' ? '<span class="rozet r-tamamlandi" style="padding:1px 8px">Tamam</span>' : '<span class="rozet r-bekliyor" style="padding:1px 8px">Taslak</span>' ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kart">
        <?php if (!$secClient): ?>
        <div class="bos-durum" style="padding:60px 20px">
            <div class="bos-ikon">📊</div>
            <div class="bos-baslik">Rapor seçin</div>
            <div class="bos-metin">Soldan dosya ve dönem seçerek yeni rapor başlatın ya da mevcut raporu açın.</div>
        </div>
        <?php else:
            $clientName = val("SELECT name FROM clients WHERE id=?", [$secClient]); ?>
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik"><?= e($clientName) ?> — <?= $periodName($secPeriod) ?> Raporu</div>
            <?php if ($is_active): ?><span class="kucuk metin-muted">Son güncelleme: <?= e($is_active['author_name'] ?? '') ?: '' ?> <?= format_date($is_active['updated'] ?? $is_active['created'], true) ?></span><?php endif; ?>
        </div>
        <?php
        // Automatic financial summary for the selected client + period (live, not stored)
        [$pYil, $pAy] = explode('-', $secPeriod);
        $pBas = "$secPeriod-01"; $pSon = date('Y-m-t', strtotime($pBas));
        $finBudget = (float)val("SELECT COALESCE(SUM(budget),0) FROM projects WHERE client_id=? AND status='aktif'", [$secClient]);
        $finExtra = (float)val("SELECT COALESCE(SUM(t.amount),0) FROM project_extra_requests t JOIN projects p ON p.id=t.project_id WHERE p.client_id=? AND t.status='onaylandi' AND t.created BETWEEN ? AND ?", [$secClient, "$pBas 00:00:00", "$pSon 23:59:59"]);
        $finIncome = (float)val("SELECT COALESCE(SUM(o.amount),0) FROM payments o JOIN projects p ON p.id=o.project_id WHERE p.client_id=? AND o.date BETWEEN ? AND ?", [$secClient, $pBas, $pSon]);
        $finShoot = (float)val("SELECT COALESCE(SUM(e.cost),0) FROM events e LEFT JOIN projects p ON p.id=e.project_id WHERE (e.client_id=? OR p.client_id=?) AND e.start BETWEEN ? AND ?", [$secClient, $secClient, "$pBas 00:00:00", "$pSon 23:59:59"]);
        ?>
        <div class="izgara mb-3" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="hucre-alt">Aktif Proje Bütçesi</div><div class="kalin"><?= number_format($finBudget, 0, ',', '.') ?> ₺</div></div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="hucre-alt">Bu Ay Onaylı Ek Talep</div><div class="kalin">+<?= number_format($finExtra, 0, ',', '.') ?> ₺</div></div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="hucre-alt">Bu Ay Tahsilat</div><div class="kalin" style="color:var(--basari)"><?= number_format($finIncome, 0, ',', '.') ?> ₺</div></div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="hucre-alt">Bu Ay Çekim Maliyeti</div><div class="kalin" style="color:var(--tehlike)"><?= number_format($finShoot, 0, ',', '.') ?> ₺</div></div>
        </div>
        <div class="form-ipucu mb-2">Finansal özet panel verilerinden otomatik hesaplanır; rapora elle geçirmenize gerek yok.</div>
        <div class="satir-esnek mb-2" style="gap:10px">
            <button type="button" class="btn btn-sm" id="aiDraftBtn" onclick="aiDraft(<?= $secClient ?>, '<?= e($secPeriod) ?>')">🪄 AI ile Taslak Doldur</button>
            <span class="kucuk metin-muted" id="aiDraftDurum"></span>
        </div>
        <form data-ajax="monthly_report_save" data-refresh="hayir" id="reportForm">
            <input type="hidden" name="client_id" value="<?= $secClient ?>">
            <input type="hidden" name="period" value="<?= e($secPeriod) ?>">
            <div class="form-grup"><label class="form-etiket">Genel Özet</label><textarea name="summary" class="metin-alani" rows="3" placeholder="Bu ay genel olarak..."><?= e($is_active['summary'] ?? '') ?></textarea></div>
            <div class="form-grup"><label class="form-etiket">Yapılan Çalışmalar</label><textarea name="work_done" class="metin-alani" rows="5" placeholder="- 12 içerik üretildi ve yayınlandı&#10;- 2 çekim gerçekleştirildi..."><?= e($is_active['work_done'] ?? '') ?></textarea></div>
            <div class="form-grup"><label class="form-etiket">Metrikler & Sonuçlar</label><textarea name="metrics" class="metin-alani" rows="4" placeholder="Erişim, etkileşim, takipçi değişimi, öne çıkan içerikler..."><?= e($is_active['metrics'] ?? '') ?></textarea></div>
            <div class="form-grup"><label class="form-etiket">Gelecek Ay Planı</label><textarea name="plan" class="metin-alani" rows="3" placeholder="Önümüzdeki dönem hedefleri..."><?= e($is_active['plan'] ?? '') ?></textarea></div>
            <div class="satir-esnek" style="gap:10px">
                <button type="submit" class="btn" onclick="this.form.querySelectorAll('input[name=status]').forEach(x => x.remove())">Taslak Kaydet</button>
                <button type="submit" class="btn btn-marka" onclick="this.form.querySelectorAll('input[name=status]').forEach(x => x.remove()); const i = document.createElement('input'); i.type = 'hidden'; i.name = 'status'; i.value = 'tamamlandi'; this.form.appendChild(i)">Tamamlandı Olarak Kaydet</button>
                <?php if ($is_active): ?>
                <button type="button" class="btn" onclick="reportMailAc(<?= $secClient ?>, '<?= e($secPeriod) ?>')">📧 Müşteri Maili</button>
                <?php if (!empty($is_active['sent_at'])): ?><span class="rozet r-tamamlandi kucuk" title="<?= e($is_active['sent_to'] ?? '') ?>">Gönderildi: <?= format_date($is_active['sent_at'], true) ?></span><?php endif; ?>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<script>
async function aiDraft(clientId, period) {
    const btn = document.getElementById('aiDraftBtn'), st = document.getElementById('aiDraftDurum');
    btn.disabled = true; st.textContent = 'Panel verileri derleniyor, taslak yazılıyor... (~20 sn)';
    const j = await api('ai_report_draft', { client_id: clientId, period });
    btn.disabled = false;
    if (!j.ok) { st.textContent = ''; toast(j.error || 'Taslak üretilemedi', 'hata'); return; }
    const f = document.getElementById('reportForm');
    for (const [alan, deger] of Object.entries(j.draft)) {
        const el = f.querySelector(`[name="${alan}"]`);
        if (el && deger) el.value = deger;
    }
    st.textContent = 'Taslak dolduruldu — kontrol edip kaydedin.';
    toast('AI taslağı hazır. Düzenleyip kaydetmeyi unutmayın.', 'basari');
}
</script>
<div class="modal-katman" id="modalReportMail">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik">📧 Müşteri Rapor Maili</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <div class="modal-govde">
        <div class="form-satir">
            <div class="form-grup"><label class="form-etiket">Alıcı</label><input class="girdi" id="rm_to" placeholder="musteri@firma.com"></div>
            <div class="form-grup"><label class="form-etiket">Gönderen</label><select class="secim native-kal" id="rm_from"></select></div>
        </div>
        <div class="form-grup"><label class="form-etiket">Konu</label><input class="girdi" id="rm_subject"></div>
        <details class="mb-2" id="rm_tasarim">
            <summary class="kucuk kalin" style="cursor:pointer;padding:6px 0">🎨 Tasarımı Düzenle — kapak görseli, favori içerik, istatistikler</summary>
            <div class="mt-2" style="padding:14px;background:var(--surface-2);border-radius:12px">
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Kapak Görseli <span class="metin-muted" style="font-weight:400" id="rm_hero_durum"></span></label>
                        <input type="file" class="girdi" id="rm_hero" accept="image/*">
                        <label class="kucuk satir-esnek mt-1" style="gap:6px"><input type="checkbox" id="rm_hero_kaldir"> Mevcut görseli kaldır</label></div>
                    <div class="form-grup"><label class="form-etiket">Favori Görseli <span class="metin-muted" style="font-weight:400" id="rm_fav_img_durum"></span></label>
                        <input type="file" class="girdi" id="rm_fav_img" accept="image/*">
                        <label class="kucuk satir-esnek mt-1" style="gap:6px"><input type="checkbox" id="rm_fav_img_kaldir"> Mevcut görseli kaldır</label></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Favori Başlığı</label><input class="girdi" id="rm_fav_title" placeholder="Bu Ayın Favorisi"></div>
                    <div class="form-grup"><label class="form-etiket">Öne Çıkan Sayı</label><input class="girdi" id="rm_fav_stat" placeholder="113B izlenme"></div>
                </div>
                <div class="form-grup"><label class="form-etiket">Favori Açıklaması</label><textarea class="metin-alani" id="rm_fav_text" rows="2" placeholder="Ürettiğimiz bu içerik markanızı çok daha ileriye taşıdı!"></textarea></div>
                <label class="form-etiket">İstatistik Kartları <span class="metin-muted" style="font-weight:400">(etiket · değer · değişim — boş bırakılan satır atlanır)</span></label>
                <div class="dikey" style="gap:6px" id="rm_stats">
                    <?php for ($si = 0; $si < 4; $si++): ?>
                    <div class="satir-esnek" style="gap:6px">
                        <input class="girdi rm-stat-tag" placeholder="<?= ['Erişilen Hesaplar','Görüntüleme','Takipçi Sayısı','Etkileşim'][$si] ?>" style="flex:2">
                        <input class="girdi rm-stat-deger" placeholder="<?= ['340,8K','1.3M','43,3K','86K'][$si] ?>" style="flex:1">
                        <input class="girdi rm-stat-degisim" placeholder="+%12" style="flex:1">
                    </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn btn-sm mt-2" onclick="reportMailTasarimKaydet()">Kaydet & Önizlemeyi Yenile</button>
            </div>
        </details>
        <div class="form-grup"><label class="form-etiket">Önizleme</label>
            <iframe id="rm_preview" style="width:100%;height:420px;border:1px solid var(--border);border-radius:12px;background:#eef1f6"></iframe>
        </div>
    </div>
    <div class="modal-alt">
        <span class="kucuk metin-muted" id="rm_sent_info" style="margin-right:auto"></span>
        <button type="button" class="btn btn-hayalet" data-modal-close>Vazgeç</button>
        <button type="button" class="btn btn-marka" id="rm_send" onclick="reportMailGonder()">Gönder</button>
    </div>
    </div>
</div>
<script>
let rmClient = 0, rmPeriod = '';
async function reportMailAc(clientId, period) {
    rmClient = clientId; rmPeriod = period;
    const j = await api('report_mail_preview', { client_id: clientId, period });
    if (!j.ok) return;
    document.getElementById('rm_to').value = j.to || '';
    document.getElementById('rm_subject').value = j.subject;
    document.getElementById('rm_from').innerHTML = j.senders.map(s => `<option value="${esc(s)}">${esc(s)}</option>`).join('');
    document.getElementById('rm_preview').srcdoc = j.html;
    document.getElementById('rm_sent_info').textContent = j.sent_at ? 'Daha önce gönderildi: ' + j.sent_at : '';
    // tasarım düzenleyicisini mevcut verilerle doldur
    const v = j.mail_data || {};
    document.getElementById('rm_hero_durum').textContent = v.hero ? '(yüklü ✓)' : '';
    document.getElementById('rm_fav_img_durum').textContent = (v.fav && v.fav.img) ? '(yüklü ✓)' : '';
    document.getElementById('rm_fav_title').value = (v.fav && v.fav.title) || '';
    document.getElementById('rm_fav_stat').value = (v.fav && v.fav.stat) || '';
    document.getElementById('rm_fav_text').value = (v.fav && v.fav.text) || '';
    const satirlar = document.querySelectorAll('#rm_stats .satir-esnek');
    satirlar.forEach((s, i) => {
        const st = (v.stats || [])[i] || {};
        s.querySelector('.rm-stat-tag').value = st.tag || '';
        s.querySelector('.rm-stat-deger').value = st.deger || '';
        s.querySelector('.rm-stat-degisim').value = st.degisim || '';
    });
    ['rm_hero', 'rm_fav_img'].forEach(id => document.getElementById(id).value = '');
    ['rm_hero_kaldir', 'rm_fav_img_kaldir'].forEach(id => document.getElementById(id).checked = false);
    modalOpen('modalReportMail');
}
async function reportMailTasarimKaydet() {
    const stats = [...document.querySelectorAll('#rm_stats .satir-esnek')].map(s => ({
        tag: s.querySelector('.rm-stat-tag').value.trim(),
        deger: s.querySelector('.rm-stat-deger').value.trim(),
        degisim: s.querySelector('.rm-stat-degisim').value.trim()
    })).filter(s => s.tag || s.deger);
    const data = {
        client_id: rmClient, period: rmPeriod,
        fav_title: document.getElementById('rm_fav_title').value,
        fav_stat: document.getElementById('rm_fav_stat').value,
        fav_text: document.getElementById('rm_fav_text').value,
        stats: stats,
        hero_kaldir: document.getElementById('rm_hero_kaldir').checked ? '1' : '0',
        fav_img_kaldir: document.getElementById('rm_fav_img_kaldir').checked ? '1' : '0'
    };
    const hero = document.getElementById('rm_hero').files[0];
    const favImg = document.getElementById('rm_fav_img').files[0];
    if (hero) data.hero_img = hero;
    if (favImg) data.fav_img = favImg;
    const j = await api('report_mail_data_save', data);
    if (!j.ok) return;
    toast('Tasarım kaydedildi', 'basari', 1600);
    // önizlemeyi tazele (alanları yeniden doldurur)
    reportMailAc(rmClient, rmPeriod);
    document.getElementById('rm_tasarim').open = true;
}
async function reportMailGonder() {
    const btn = document.getElementById('rm_send');
    if (!confirm('Rapor maili "' + document.getElementById('rm_to').value + '" adresine gönderilsin mi?')) return;
    btn.disabled = true; btn.textContent = 'Gönderiliyor...';
    const j = await api('report_mail_send', {
        client_id: rmClient, period: rmPeriod,
        to: document.getElementById('rm_to').value,
        from: document.getElementById('rm_from').value,
        subject: document.getElementById('rm_subject').value
    });
    btn.disabled = false; btn.textContent = 'Gönder';
    if (j.ok) { toast(j.message, 'basari'); modalClose(document.getElementById('modalReportMail')); setTimeout(() => location.reload(), 700); }
}
</script>
<?php page_end(); ?>
