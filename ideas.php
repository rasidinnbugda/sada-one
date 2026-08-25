<?php
/**
 * SADA One — Idea Board
 * Board where team members suggest content ideas to each other: idea · adaptable organization · description
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$ideas = rows("SELECT f.*, uu.name proposer_name, uu.color proposer_color, uu.avatar proposer_avatar
    FROM ideas f JOIN users uu ON uu.id=f.proposer_id ORDER BY FIELD(f.status,'yeni','begenildi','uygulandi'), f.id DESC");
$can_manage = is_admin() || $u['role'] === 'pm';
$FDURUM = ['yeni' => ['Yeni', 'r-bekliyor'], 'begenildi' => ['Beğenildi', 'r-devam'], 'uygulandi' => ['Uygulandı', 'r-tamamlandi']];

page_start('Fikir Panosu', 'ideas');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Fikir Panosu</div><div class="sayfa-alt">İçerik fikirleri — hangi kuruma uyarlanabilir, nasıl uygulanır</div></div>
    <div class="sayfa-ust-aksiyon">
        <button class="btn" onclick="modalOpen('modalAiIdea')">🪄 AI ile Üret</button>
        <button class="btn btn-marka" data-modal="modalIdea"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Fikir Öner</button></div>
</div>

<?php if (!$ideas): ?>
<div class="bos-durum">
    <div class="bos-ikon">💡</div>
    <div class="bos-baslik">Pano boş</div>
    <div class="bos-metin">Aklınıza gelen içerik fikrini paylaşın — hangi kuruma uyarlanabileceğini de yazın, ekip değerlendirsin.</div>
    <button class="btn btn-marka" data-modal="modalIdea">İlk Fikri Öner</button>
</div>
<?php else: ?>
<div class="izgara izgara-3">
    <?php foreach ($ideas as $f): [$fEtiket, $fSinif] = $FDURUM[$f['status']]; ?>
    <div class="kart" style="display:flex;flex-direction:column">
        <div class="satir-esnek arasi mb-2">
            <span class="rozet <?= $fSinif ?>"><?= $fEtiket ?></span>
            <div class="satir-esnek" style="gap:4px">
                <?php if ($can_manage && $f['status'] !== 'uygulandi'): ?>
                <button class="mini-btn" title="Durum ilerlet" onclick="ideaIlerlet(<?= $f['id'] ?>, '<?= $f['status'] === 'yeni' ? 'begenildi' : 'uygulandi' ?>')"><?= $f['status'] === 'yeni' ? '👍 Beğen' : '✅ Uygulandı' ?></button>
                <?php endif; ?>
                <?php if (is_admin() || $f['proposer_id'] == $u['id']): ?>
                <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-action="idea_delete" data-id="<?= $f['id'] ?>" data-approval="Fikir silinsin mi?"><?= icon('cop', 13) ?></button>
                <?php endif; ?>
            </div>
        </div>
        <div class="kalin mb-1" style="font-size:15px;line-height:1.45"><?= e($f['idea']) ?></div>
        <?php if ($f['organization']): ?><div class="kucuk mb-1"><span class="metin-muted">Uyarlanabilecek kurum:</span> <b><?= e($f['organization']) ?></b></div><?php endif; ?>
        <?php if ($f['description']): ?><div class="kucuk metin-2 mb-2" style="white-space:pre-wrap"><?= e($f['description']) ?></div><?php endif; ?>
        <div class="satir-esnek mt-auto" style="gap:8px;padding-top:10px;border-top:1px solid var(--border)">
            <?= avatar(['name' => $f['proposer_name'], 'color' => $f['proposer_color'], 'avatar' => $f['proposer_avatar']], 24) ?>
            <span class="kucuk metin-muted"><?= e($f['proposer_name']) ?> · <?= time_ago($f['created']) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalIdea">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Fikir Öner</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="idea_save">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Fikir <span class="zorunlu">*</span></label><input name="idea" class="girdi" required placeholder="Örn. Kurum çalışanlarıyla 'bir günüm' reels serisi"></div>
            <div class="form-grup"><label class="form-etiket">Uyarlanabilecek Kurum</label><input name="organization" class="girdi" placeholder="Hangi müşteri/dosya için uygun olur?"></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="description" class="metin-alani" placeholder="Nasıl uygulanır, neden işe yarar..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Panoya Ekle</button></div>
    </form></div>
</div>

<script>
async function ideaIlerlet(id, status) {
    const j = await api('idea_status', { id, status });
    if (j.ok) location.reload();
}
</script>
<!-- AI idea generator -->
<div class="modal-katman" id="modalAiIdea">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">🪄 AI ile Fikir Üret</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <div class="modal-govde">
        <div class="form-grup"><label class="form-etiket">Kurum / Konu</label><input id="aiTopic" class="girdi" placeholder="Örn. yerel kahve zinciri, Ramazan kampanyası"></div>
        <button class="btn btn-marka" id="aiIdeaBtn" onclick="aiIdeas()">Üret</button>
        <div class="dikey mt-3" id="aiIdeaList" style="gap:8px"></div>
    </div></div>
</div>
<script>
async function aiIdeas() {
    const topic = document.getElementById('aiTopic').value.trim();
    if (!topic) { toast('Kurum/konu yazın', 'hata'); return; }
    const btn = document.getElementById('aiIdeaBtn'), list = document.getElementById('aiIdeaList');
    btn.disabled = true; btn.textContent = 'Üretiliyor... (~15 sn)';
    const j = await api('ai_idea_generate', { topic });
    btn.disabled = false; btn.textContent = 'Üret';
    if (!j.ok) { toast(j.error || 'Üretilemedi', 'hata'); return; }
    list.innerHTML = '';
    for (const f of j.ideas) {
        const div = document.createElement('div');
        div.style.cssText = 'padding:11px 13px;background:var(--surface-2);border-radius:11px';
        div.innerHTML = `<div class="kalin kucuk"></div><div class="kucuk metin-2 mt-1"></div><button class="mini-btn mt-1">+ Panoya ekle</button>`;
        div.children[0].textContent = f.fikir || '';
        div.children[1].textContent = f.aciklama || '';
        div.children[2].addEventListener('click', async () => {
            const r = await api('idea_save', { idea: f.fikir, organization: topic, description: f.aciklama || '' });
            if (r.ok) { div.children[2].textContent = '✓ Eklendi'; div.children[2].disabled = true; }
        });
        list.appendChild(div);
    }
}
</script>
<?php page_end(); ?>
