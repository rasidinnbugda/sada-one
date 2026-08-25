<?php
/**
 * SADA One — Internal Team Development and Mentorship Tracking Program
 * Member · desired development area · assigned mentor · practice arena/project · output & evaluation
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$can_manage = is_admin() || $u['role'] === 'pm';
$kayitlar = rows("SELECT m.*, uu.name member_name, uu.color member_color, uu.avatar member_avatar, mm.name mentor_name, p.name project_name
    FROM mentorship m JOIN users uu ON uu.id=m.member_id LEFT JOIN users mm ON mm.id=m.mentor_id LEFT JOIN projects p ON p.id=m.project_id
    ORDER BY FIELD(m.status,'devam','planlandi','tamamlandi'), m.created DESC");
$team = rows("SELECT id, name FROM users WHERE role IN ('yonetici','pm','ekip','stajyer') AND is_active=1 ORDER BY name");
$projects = rows("SELECT id, name FROM projects WHERE status='aktif' ORDER BY name");

$MDURUM = ['planlandi' => 'Planlandı', 'devam' => 'Devam Ediyor', 'tamamlandi' => 'Tamamlandı'];
$mRozet = fn($d) => '<span class="rozet ' . ['planlandi' => 'r-bekliyor', 'devam' => 'r-devam', 'tamamlandi' => 'r-tamamlandi'][$d] . '">' . $MDURUM[$d] . '</span>';

page_start('Gelişim & Mentörlük', 'growth');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Gelişim & Mentörlük</div><div class="sayfa-alt">Ekip içi yetkinlik gelişimi ve mentörlük eşleşmeleri</div></div>
    <?php if ($can_manage): ?><div class="sayfa-ust-aksiyon"><button class="btn btn-marka" onclick="mYeni()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Eşleşme</button></div><?php endif; ?>
</div>

<div class="stat-izgara">
    <div class="stat-kart"><div class="stat-etiket">Aktif Gelişim Süreci</div><div class="stat-deger"><?= count(array_filter($kayitlar, fn($k) => $k['status'] === 'devam')) ?></div></div>
    <div class="stat-kart"><div class="stat-etiket">Planlanan</div><div class="stat-deger"><?= count(array_filter($kayitlar, fn($k) => $k['status'] === 'planlandi')) ?></div></div>
    <div class="stat-kart"><div class="stat-etiket">Tamamlanan</div><div class="stat-deger"><?= count(array_filter($kayitlar, fn($k) => $k['status'] === 'tamamlandi')) ?></div></div>
    <div class="stat-kart"><div class="stat-etiket">Gelişimdeki Kişi</div><div class="stat-deger"><?= count(array_unique(array_column($kayitlar, 'member_id'))) ?></div></div>
</div>

<?php if (!$kayitlar): ?>
<div class="bos-durum">
    <div class="bos-ikon"><?= icon('roket', 36) ?></div>
    <div class="bos-baslik">Henüz mentörlük kaydı yok</div>
    <div class="bos-metin">Örn: "İmran → video edit gelişimi, mentör Ömer, uygulama sahası: 1 Ağustos podcast çekimi"</div>
    <?php if ($can_manage): ?><button class="btn btn-marka" onclick="mYeni()">İlk Eşleşmeyi Oluştur</button><?php endif; ?>
</div>
<?php else: ?>
<div class="izgara izgara-2">
    <?php foreach ($kayitlar as $k): ?>
    <div class="kart">
        <div class="satir-esnek arasi mb-2">
            <div class="satir-esnek" style="gap:10px">
                <?= avatar(['name' => $k['member_name'], 'color' => $k['member_color'], 'avatar' => $k['member_avatar']], 38) ?>
                <div><div class="kalin"><?= e($k['member_name']) ?></div><div class="hucre-alt"><?= e($k['field']) ?></div></div>
            </div>
            <div class="satir-esnek" style="gap:6px">
                <?= $mRozet($k['status']) ?>
                <?php if ($can_manage): ?>
                <button class="ikon-eylem" onclick='mDuzenle(<?= json_encode($k, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 15) ?></button>
                <button class="ikon-eylem tehlike" data-action="mentorship_delete" data-id="<?= $k['id'] ?>" data-approval="Mentörlük kaydı silinsin mi?"><?= icon('cop', 15) ?></button>
                <?php endif; ?>
            </div>
        </div>
        <div class="dikey kucuk" style="gap:7px">
            <div class="satir-esnek arasi"><span class="metin-muted">Mentör</span><span class="kalin"><?= $k['mentor_name'] ? e($k['mentor_name']) : '— belirlenmedi' ?></span></div>
            <div class="satir-esnek arasi"><span class="metin-muted">Uygulama Sahası</span><span><?= $k['project_name'] ? e($k['project_name']) : e($k['practice_arena'] ?: '—') ?></span></div>
        </div>
        <div class="mt-2" style="padding:10px 12px;background:var(--surface-2);border-radius:10px">
            <div class="hucre-alt mb-1">Çıktı & Değerlendirme Notu <?php if ($k['member_id'] == $u['id'] || $can_manage): ?><button class="mini-btn" onclick="mCikti(<?= $k['id'] ?>, this)">Düzenle</button><?php endif; ?></div>
            <div class="kucuk metin-2 m-cikti" style="white-space:pre-wrap"><?= $k['output'] ? e($k['output']) : '<span class="metin-muted">Henüz not girilmedi.</span>' ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($can_manage): ?>
<div class="modal-katman" id="modalMentor">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="mBaslik">Yeni Mentörlük Eşleşmesi</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="mentorship_save">
        <input type="hidden" name="id" id="m_id">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Ekip Üyesi <span class="zorunlu">*</span></label>
                    <select name="member_id" id="m_member" class="secim" required><option value="">Seçin...</option><?php foreach ($team as $e2): ?><option value="<?= $e2['id'] ?>"><?= e($e2['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Atanan Mentör</label>
                    <select name="mentor_id" id="m_mentor" class="secim"><option value="">— Belirlenmedi</option><?php foreach ($team as $e2): ?><option value="<?= $e2['id'] ?>"><?= e($e2['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Gelişim İstenen Alan <span class="zorunlu">*</span></label><input name="field" id="m_field" class="girdi" required placeholder="Örn. video edit ve içerik üretimi, çekim"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Uygulama Projesi</label>
                    <select name="project_id" id="m_project" class="secim"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">veya Serbest Saha</label><input name="practice_arena" id="m_practice_arena" class="girdi" placeholder="Örn. 1 Ağustos podcast tek başına kurulum"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Durum</label>
                <select name="status" id="m_status" class="secim"><?php foreach ($MDURUM as $min => $dv): ?><option value="<?= $min ?>"><?= $dv ?></option><?php endforeach; ?></select></div>
            <div class="form-grup"><label class="form-etiket">Çıktı & Değerlendirme Notu</label><textarea name="output" id="m_output" class="metin-alani" placeholder="Süreç sonunda gözlemler, değerlendirme..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
function mYeni() {
    const f = document.querySelector('#modalMentor form'); if (!f) return;
    f.reset(); document.getElementById('m_id').value = '';
    document.getElementById('mBaslik').textContent = 'Yeni Mentörlük Eşleşmesi';
    if (window.ozelPickerRefresh) ozelPickerRefresh();
    modalOpen('modalMentor');
}
function mDuzenle(k) {
    document.getElementById('m_id').value = k.id;
    document.getElementById('m_member').value = k.member_id;
    document.getElementById('m_mentor').value = k.mentor_id || '';
    document.getElementById('m_field').value = k.field;
    document.getElementById('m_project').value = k.project_id || '';
    document.getElementById('m_practice_arena').value = k.practice_arena || '';
    document.getElementById('m_status').value = k.status;
    document.getElementById('m_output').value = k.output || '';
    document.getElementById('mBaslik').textContent = 'Eşleşmeyi Düzenle';
    ['m_member', 'm_mentor', 'm_project', 'm_status'].forEach(id => document.getElementById(id).dispatchEvent(new Event('change')));
    modalOpen('modalMentor');
}
async function mCikti(id, btn) {
    const box = btn.closest('div').nextElementSibling;
    const mevcut = box.querySelector('.text-muted') ? '' : box.textContent.trim();
    const newNote = prompt('Çıktı & değerlendirme notu:', mevcut);
    if (newNote === null) return;
    const j = await api('mentorship_output', { id, output: newNote });
    if (j.ok) { box.textContent = newNote || 'Henüz not girilmedi.'; toast(j.message, 'basari'); }
}
</script>
<?php page_end(); ?>
