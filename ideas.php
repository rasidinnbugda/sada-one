<?php
/**
 * SADA One — Idea Board
 * Board where team members suggest content ideas to each other: idea · adaptable organization · description
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$ideas = rows("SELECT f.*, uu.name proposer_name, uu.color proposer_color, uu.avatar proposer_avatar
    FROM ideas f JOIN users uu ON uu.id=f.proposer_id ORDER BY FIELD(f.status,'new','liked','implemented'), f.id DESC");
$can_manage = is_admin() || $u['role'] === 'pm';
$ideaClasses = ['new' => 'r-pending', 'liked' => 'r-in_progress', 'implemented' => 'r-completed'];

page_start('Fikir Panosu', 'ideas');
?>
<div class="page-top">
    <div><div class="page-title">Fikir Panosu</div><div class="page-bottom">İçerik fikirleri — hangi kuruma uyarlanabilir, nasıl uygulanır</div></div>
    <div class="page-top-action">
        <button class="btn" onclick="modalOpen('modalAiIdea')">🪄 AI ile Üret</button>
        <button class="btn btn-brand" data-modal="modalIdea"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Fikir Öner</button></div>
</div>

<?php if (!$ideas): ?>
<div class="empty-state">
    <div class="empty-icon">💡</div>
    <div class="empty-title">Pano boş</div>
    <div class="empty-text">Aklınıza gelen içerik fikrini paylaşın — hangi kuruma uyarlanabileceğini de yazın, ekip değerlendirsin.</div>
    <button class="btn btn-brand" data-modal="modalIdea">İlk Fikri Öner</button>
</div>
<?php else: ?>
<div class="grid grid-3">
    <?php foreach ($ideas as $f): $fLabel = IDEA_STATUSES[$f['status']]; $fClass = $ideaClasses[$f['status']]; ?>
    <div class="card" style="display:flex;flex-direction:column">
        <div class="row-flex between mb-2">
            <span class="badge <?= $fClass ?>"><?= $fLabel ?></span>
            <div class="row-flex" style="gap:4px">
                <?php if ($can_manage && $f['status'] !== 'implemented'): ?>
                <button class="mini-btn" title="Durum ilerlet" onclick="ideaAdvance(<?= $f['id'] ?>, '<?= $f['status'] === 'new' ? 'liked' : 'implemented' ?>')"><?= $f['status'] === 'new' ? '👍 Beğen' : '✅ Uygulandı' ?></button>
                <?php endif; ?>
                <?php if (is_admin() || $f['proposer_id'] == $u['id']): ?>
                <button class="icon-action danger" style="width:26px;height:26px" data-action="idea_delete" data-id="<?= $f['id'] ?>" data-confirm="Fikir silinsin mi?"><?= icon('cop', 13) ?></button>
                <?php endif; ?>
            </div>
        </div>
        <div class="bold mb-1" style="font-size:15px;line-height:1.45"><?= e($f['idea']) ?></div>
        <?php if ($f['organization']): ?><div class="small mb-1"><span class="text-muted">Uyarlanabilecek kurum:</span> <b><?= e($f['organization']) ?></b></div><?php endif; ?>
        <?php if ($f['description']): ?><div class="small text-2 mb-2" style="white-space:pre-wrap"><?= e($f['description']) ?></div><?php endif; ?>
        <div class="row-flex mt-auto" style="gap:8px;padding-top:10px;border-top:1px solid var(--border)">
            <?= avatar(['name' => $f['proposer_name'], 'color' => $f['proposer_color'], 'avatar' => $f['proposer_avatar']], 24) ?>
            <span class="small text-muted"><?= e($f['proposer_name']) ?> · <?= time_ago($f['created']) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-overlay" id="modalIdea">
    <div class="modal"><div class="modal-top"><div class="modal-title">Fikir Öner</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="idea_save">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Fikir <span class="required">*</span></label><input name="idea" class="input" required placeholder="Örn. Kurum çalışanlarıyla 'bir günüm' reels serisi"></div>
            <div class="form-group"><label class="form-label">Uyarlanabilecek Kurum</label><input name="organization" class="input" placeholder="Hangi müşteri/dosya için uygun olur?"></div>
            <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" class="text-area" placeholder="Nasıl uygulanır, neden işe yarar..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Panoya Ekle</button></div>
    </form></div>
</div>

<script>
async function ideaAdvance(id, status) {
    const j = await api('idea_status', { id, status });
    if (j.ok) location.reload();
}
</script>
<!-- AI idea generator -->
<div class="modal-overlay" id="modalAiIdea">
    <div class="modal"><div class="modal-top"><div class="modal-title">🪄 AI ile Fikir Üret</div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body">
        <div class="form-group"><label class="form-label">Kurum / Konu</label><input id="aiTopic" class="input" placeholder="Örn. yerel kahve zinciri, Ramazan kampanyası"></div>
        <button class="btn btn-brand" id="aiIdeaBtn" onclick="aiIdeas()">Üret</button>
        <div class="vertical mt-3" id="aiIdeaList" style="gap:8px"></div>
    </div></div>
</div>
<script>
async function aiIdeas() {
    const topic = document.getElementById('aiTopic').value.trim();
    if (!topic) { toast('Kurum/konu yazın', 'error'); return; }
    const btn = document.getElementById('aiIdeaBtn'), list = document.getElementById('aiIdeaList');
    btn.disabled = true; btn.textContent = 'Üretiliyor... (~15 sn)';
    const j = await api('ai_idea_generate', { topic });
    btn.disabled = false; btn.textContent = 'Üret';
    if (!j.ok) { toast(j.error || 'Üretilemedi', 'error'); return; }
    list.innerHTML = '';
    for (const f of j.ideas) {
        const div = document.createElement('div');
        div.style.cssText = 'padding:11px 13px;background:var(--surface-2);border-radius:11px';
        div.innerHTML = `<div class="bold small"></div><div class="small text-2 mt-1"></div><button class="mini-btn mt-1">+ Panoya ekle</button>`;
        div.children[0].textContent = f.idea || '';
        div.children[1].textContent = f.description || '';
        div.children[2].addEventListener('click', async () => {
            const r = await api('idea_save', { idea: f.idea, organization: topic, description: f.description || '' });
            if (r.ok) { div.children[2].textContent = '✓ Eklendi'; div.children[2].disabled = true; }
        });
        list.appendChild(div);
    }
}
</script>
<?php page_end(); ?>
