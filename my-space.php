<?php
/**
 * SADA One — Personal Workspace
 * Notes, personal to-dos, bookmarks, and quick scratchpad — visible only to the owner.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();
if (is_customer()) { header('Location: index.php'); exit; }

$notes = rows("SELECT * FROM personal_notes WHERE user_id=? ORDER BY COALESCE(`update`, created) DESC", [$u['id']]);
$todos = rows("SELECT * FROM personal_todos WHERE user_id=? ORDER BY is_done, sort_order", [$u['id']]);
$links = rows("SELECT * FROM personal_links WHERE user_id=? ORDER BY name", [$u['id']]);

$noteColors = [
    'default' => 'var(--surface)',
    'yellow' => 'color-mix(in srgb, #f5a524 12%, var(--surface))',
    'green' => 'color-mix(in srgb, #35c66b 12%, var(--surface))',
    'blue' => 'color-mix(in srgb, #3b9df0 12%, var(--surface))',
    'pink' => 'color-mix(in srgb, #e86b82 12%, var(--surface))',
];

page_start('Alanım', 'my_space');
?>
<div class="page-top">
    <div><div class="page-title">Kişisel Alanım</div><div class="page-bottom">Notların, yapılacakların ve yer imlerin — yalnızca sen görürsün</div></div>
    <div class="page-top-action"><button class="btn btn-brand" onclick="noteReset();modalOpen('modalNote')"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Not</button></div>
</div>

<div class="grid" style="grid-template-columns:1fr 320px">
    <div>
        <!-- Notes -->
        <?php if (!$notes): ?>
        <div class="card orta" style="padding:36px">
            <div class="empty-icon" style="margin-bottom:14px"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></div>
            <div class="empty-title">Henüz not yok</div>
            <div class="empty-text">Fikirlerini, toplantı notlarını, aklında kalmasını istediklerini buraya yaz.</div>
        </div>
        <?php else: ?>
        <div class="grid grid-2">
            <?php foreach ($notes as $n): ?>
            <div class="card" style="background:<?= $noteColors[$n['color']] ?? $noteColors['default'] ?>;padding:16px">
                <div class="row-flex between" style="align-items:flex-start">
                    <?php if ($n['title']): ?><div class="bold" style="font-size:14.5px"><?= e($n['title']) ?></div><?php else: ?><span></span><?php endif; ?>
                    <div class="row-flex" style="gap:2px;flex-shrink:0">
                        <button class="icon-action" style="width:26px;height:26px" onclick='noteEdit(<?= json_encode($n, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="14"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                        <button class="icon-action danger" style="width:26px;height:26px" data-action="note_delete" data-id="<?= $n['id'] ?>" data-confirm="Not silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="14"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                    </div>
                </div>
                <div class="small text-2 mt-1" style="white-space:pre-wrap;word-break:break-word"><?= e(mb_substr($n['text'], 0, 600)) ?><?= mb_strlen($n['text']) > 600 ? '…' : '' ?></div>
                <div class="cell-bottom mt-2"><?= time_ago($n['update'] ?: $n['created']) ?><?= $n['update'] ? ' (düzenlendi)' : '' ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Quick scratchpad -->
        <div class="card mt-3">
            <div class="row-flex between mb-2">
                <div class="card-title"><?= icon('item', 16) ?> Hızlı Karalama</div>
                <span class="cell-bottom" id="scratchpadStatus">otomatik kaydedilir</span>
            </div>
            <textarea class="text-area" id="scratchpadField" style="min-height:180px;font-family:inherit" placeholder="Buraya istediğini karala — yazdıkça kaydedilir, döndüğünde kaldığın yerde bulursun..."><?= e($u['scratchpad'] ?? '') ?></textarea>
        </div>
    </div>

    <div>
        <!-- Personal to-dos -->
        <div class="card mb-2">
            <div class="row-flex between mb-2">
                <div class="card-title" style="font-size:14px"><?= icon('approval', 15) ?> Yapılacaklarım</div>
                <span class="cell-bottom" id="todoCounter"><?= count(array_filter($todos, fn($i) => !$i['is_done'])) ?> açık</span>
            </div>
            <div class="vertical" style="gap:2px" id="todoList">
                <?php foreach ($todos as $todo): ?>
                <div class="check-item <?= $todo['is_done'] ? 'done' : '' ?>">
                    <input type="checkbox" <?= $todo['is_done'] ? 'checked' : '' ?> onchange="todoToggle(<?= $todo['id'] ?>, this)">
                    <span class="check-text"><?= e($todo['name']) ?></span>
                    <button class="icon-action danger" style="width:24px;height:24px" data-action="personal_todo_delete" data-id="<?= $todo['id'] ?>" data-refresh="no" onclick="setTimeout(()=>{this.closest('.check-item').remove();todoCounterUpdate()},300)"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <?php endforeach; ?>
                <?php if (!$todos): ?><div class="text-muted small" style="padding:6px 0" id="todoEmpty">Henüz madde yok.</div><?php endif; ?>
            </div>
            <form class="row-flex mt-2" style="gap:8px" onsubmit="return todoAdd(event)">
                <input class="input" id="isNew" placeholder="Yeni madde...">
                <button type="submit" class="btn btn-sm">Ekle</button>
            </form>
        </div>

        <!-- Bookmarks -->
        <div class="card">
            <div class="row-flex between mb-2">
                <div class="card-title" style="font-size:14px"><?= icon('paperclip', 15) ?> Yer İmlerim</div>
                <button class="mini-btn" data-modal="modalLink">+ Ekle</button>
            </div>
            <?php if (!$links): ?><div class="text-muted small">Sık kullandığın linkleri buraya ekle (Drive klasörleri, araçlar...).</div>
            <?php else: foreach ($links as $l): ?>
            <div class="row-flex between mt-1" style="padding:7px 10px;background:var(--surface-2);border-radius:9px">
                <a href="<?= e($l['url']) ?>" target="_blank" class="row-flex small bold" style="gap:8px;min-width:0;color:var(--brand)">
                    <span style="display:inline-flex;color:var(--brand)"><?= icon('web', 14) ?></span><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($l['name']) ?></span>
                </a>
                <button class="icon-action danger" style="width:24px;height:24px" data-action="link_delete" data-id="<?= $l['id'] ?>" data-confirm="Yer imi silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Add/edit note -->
<div class="modal-overlay" id="modalNote">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="noteModalTitle">Yeni Not</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="note_save">
        <input type="hidden" name="id" id="n_id">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık</label><input name="title" id="n_title" class="input" placeholder="Opsiyonel"></div>
            <div class="form-group"><label class="form-label">Not <span class="required">*</span></label><textarea name="text" id="n_text" class="text-area" style="min-height:140px" required></textarea></div>
            <div class="form-group">
                <label class="form-label">Renk</label>
                <div class="row-flex" style="gap:10px">
                    <?php foreach (['default' => 'var(--surface-3)', 'yellow' => '#f5a524', 'green' => '#35c66b', 'blue' => '#3b9df0', 'pink' => '#e86b82'] as $rk => $rv): ?>
                    <label style="cursor:pointer"><input type="radio" name="color" value="<?= $rk ?>" <?= $rk === 'default' ? 'checked' : '' ?> class="color-radio" style="display:none"><span class="label-dot note-color" data-color="<?= $rk ?>" style="width:26px;height:26px;background:<?= $rv ?>;border:2px solid transparent"></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<!-- Add bookmark -->
<div class="modal-overlay" id="modalLink">
    <div class="modal"><div class="modal-top"><div class="modal-title">Yer İmi Ekle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="link_add">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Ad <span class="required">*</span></label><input name="name" class="input" required placeholder="Örn. Marka X Drive klasörü"></div>
            <div class="form-group"><label class="form-label">Adres <span class="required">*</span></label><input name="url" class="input" required placeholder="https://..."></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Ekle</button></div>
    </form></div>
</div>

<script>
/* Note color selection highlight */
document.querySelectorAll('.note-color').forEach(n => n.addEventListener('click', () => {
    document.querySelectorAll('.note-color').forEach(x => x.style.borderColor = 'transparent');
    n.style.borderColor = 'var(--text)';
}));
function noteReset() {
    document.getElementById('n_id').value = '';
    document.getElementById('n_title').value = '';
    document.getElementById('n_text').value = '';
    document.getElementById('noteModalTitle').textContent = 'Yeni Not';
    document.querySelector('input[name=color][value=default]').checked = true;
}
function noteEdit(n) {
    document.getElementById('n_id').value = n.id;
    document.getElementById('n_title').value = n.title || '';
    document.getElementById('n_text').value = n.text || '';
    document.getElementById('noteModalTitle').textContent = 'Notu Düzenle';
    const radio = document.querySelector(`input[name=color][value=${n.color}]`);
    if (radio) radio.checked = true;
    modalOpen('modalNote');
}

/* Personal to-dos: without page reload */
function todoCounterUpdate() {
    const open = document.querySelectorAll('#todoList .check-item:not(.done)').length;
    document.getElementById('todoCounter').textContent = open + ' açık';
}
async function todoAdd(e) {
    e.preventDefault();
    const input = document.getElementById('isNew');
    const name = input.value.trim(); if (!name) return false;
    const j = await api('personal_todo_add', { name });
    if (j.ok) {
        input.value = '';
        const empty = document.getElementById('todoEmpty'); if (empty) empty.remove();
        const div = document.createElement('div');
        div.className = 'check-item';
        div.innerHTML = `<input type="checkbox" onchange="todoToggle(${j.id}, this)"><span class="check-text"></span><button class="icon-action danger" style="width:24px;height:24px" data-action="personal_todo_delete" data-id="${j.id}" data-refresh="no" onclick="setTimeout(()=>{this.closest('.check-item').remove();todoCounterUpdate()},300)"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
        div.querySelector('.check-text').textContent = j.name;
        document.getElementById('todoList').appendChild(div);
        todoCounterUpdate();
    }
    return false;
}
async function todoToggle(id, box) {
    const j = await api('personal_todo_toggle', { id });
    if (j.ok) { box.closest('.check-item').classList.toggle('done', box.checked); todoCounterUpdate(); }
    else box.checked = !box.checked;
}

/* Scratchpad: auto-save after 1.2s of inactivity */
const scratchpad = document.getElementById('scratchpadField');
const scratchpadStatus = document.getElementById('scratchpadStatus');
let scratchpadTime = null;
scratchpad.addEventListener('input', () => {
    scratchpadStatus.textContent = 'yazılıyor...';
    clearTimeout(scratchpadTime);
    scratchpadTime = setTimeout(async () => {
        const j = await api('scratchpad_save', { text: scratchpad.value });
        scratchpadStatus.textContent = j.ok ? '✓ kaydedildi' : 'kaydedilemedi!';
    }, 1200);
});
</script>
<?php page_end(); ?>
