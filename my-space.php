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

$notRenkleri = [
    'default' => 'var(--surface)',
    'sari' => 'color-mix(in srgb, #f5a524 12%, var(--surface))',
    'yesil' => 'color-mix(in srgb, #35c66b 12%, var(--surface))',
    'mavi' => 'color-mix(in srgb, #3b9df0 12%, var(--surface))',
    'pembe' => 'color-mix(in srgb, #e86b82 12%, var(--surface))',
];

page_start('Alanım', 'my_space');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Kişisel Alanım</div><div class="sayfa-alt">Notların, yapılacakların ve yer imlerin — yalnızca sen görürsün</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" onclick="notSifirla();modalOpen('modalNot')"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Not</button></div>
</div>

<div class="izgara" style="grid-template-columns:1fr 320px">
    <div>
        <!-- Notes -->
        <?php if (!$notes): ?>
        <div class="kart orta" style="padding:36px">
            <div class="bos-ikon" style="margin-bottom:14px"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></div>
            <div class="bos-baslik">Henüz not yok</div>
            <div class="bos-metin">Fikirlerini, toplantı notlarını, aklında kalmasını istediklerini buraya yaz.</div>
        </div>
        <?php else: ?>
        <div class="izgara izgara-2">
            <?php foreach ($notes as $n): ?>
            <div class="kart" style="background:<?= $notRenkleri[$n['color']] ?? $notRenkleri['default'] ?>;padding:16px">
                <div class="satir-esnek arasi" style="align-items:flex-start">
                    <?php if ($n['title']): ?><div class="kalin" style="font-size:14.5px"><?= e($n['title']) ?></div><?php else: ?><span></span><?php endif; ?>
                    <div class="satir-esnek" style="gap:2px;flex-shrink:0">
                        <button class="ikon-eylem" style="width:26px;height:26px" onclick='notDuzenle(<?= json_encode($n, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="14"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                        <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-action="not_delete" data-id="<?= $n['id'] ?>" data-approval="Not silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="14"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                    </div>
                </div>
                <div class="kucuk metin-2 mt-1" style="white-space:pre-wrap;word-break:break-word"><?= e(mb_substr($n['text'], 0, 600)) ?><?= mb_strlen($n['text']) > 600 ? '…' : '' ?></div>
                <div class="hucre-alt mt-2"><?= time_ago($n['update'] ?: $n['created']) ?><?= $n['update'] ? ' (düzenlendi)' : '' ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Quick scratchpad -->
        <div class="kart mt-3">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik"><?= icon('item', 16) ?> Hızlı Karalama</div>
                <span class="hucre-alt" id="scratchpadStatus">otomatik kaydedilir</span>
            </div>
            <textarea class="metin-alani" id="scratchpadField" style="min-height:180px;font-family:inherit" placeholder="Buraya istediğini karala — yazdıkça kaydedilir, döndüğünde kaldığın yerde bulursun..."><?= e($u['scratchpad'] ?? '') ?></textarea>
        </div>
    </div>

    <div>
        <!-- Personal to-dos -->
        <div class="kart mb-2">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik" style="font-size:14px"><?= icon('approval', 15) ?> Yapılacaklarım</div>
                <span class="hucre-alt" id="isCounter"><?= count(array_filter($todos, fn($i) => !$i['is_done'])) ?> açık</span>
            </div>
            <div class="dikey" style="gap:2px" id="isList">
                <?php foreach ($todos as $is): ?>
                <div class="kontrol-oge <?= $is['is_done'] ? 'tamam' : '' ?>">
                    <input type="checkbox" <?= $is['is_done'] ? 'checked' : '' ?> onchange="isToggle(<?= $is['id'] ?>, this)">
                    <span class="kontrol-metin"><?= e($is['name']) ?></span>
                    <button class="ikon-eylem tehlike" style="width:24px;height:24px" data-action="personal_is_delete" data-id="<?= $is['id'] ?>" data-refresh="hayir" onclick="setTimeout(()=>{this.closest('.check-oge').remove();isCounterUpdate()},300)"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <?php endforeach; ?>
                <?php if (!$todos): ?><div class="metin-muted kucuk" style="padding:6px 0" id="isBos">Henüz madde yok.</div><?php endif; ?>
            </div>
            <form class="satir-esnek mt-2" style="gap:8px" onsubmit="return isAdd(event)">
                <input class="girdi" id="isNew" placeholder="Yeni madde...">
                <button type="submit" class="btn btn-sm">Ekle</button>
            </form>
        </div>

        <!-- Bookmarks -->
        <div class="kart">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik" style="font-size:14px"><?= icon('atac', 15) ?> Yer İmlerim</div>
                <button class="mini-btn" data-modal="modalLink">+ Ekle</button>
            </div>
            <?php if (!$links): ?><div class="metin-muted kucuk">Sık kullandığın linkleri buraya ekle (Drive klasörleri, araçlar...).</div>
            <?php else: foreach ($links as $l): ?>
            <div class="satir-esnek arasi mt-1" style="padding:7px 10px;background:var(--surface-2);border-radius:9px">
                <a href="<?= e($l['url']) ?>" target="_blank" class="satir-esnek kucuk kalin" style="gap:8px;min-width:0;color:var(--marka)">
                    <span style="display:inline-flex;color:var(--marka)"><?= icon('web', 14) ?></span><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($l['name']) ?></span>
                </a>
                <button class="ikon-eylem tehlike" style="width:24px;height:24px" data-action="link_delete" data-id="<?= $l['id'] ?>" data-approval="Yer imi silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Add/edit note -->
<div class="modal-katman" id="modalNot">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="notTitleTop">Yeni Not</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="not_save">
        <input type="hidden" name="id" id="n_id">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık</label><input name="title" id="n_title" class="girdi" placeholder="Opsiyonel"></div>
            <div class="form-grup"><label class="form-etiket">Not <span class="zorunlu">*</span></label><textarea name="text" id="n_text" class="metin-alani" style="min-height:140px" required></textarea></div>
            <div class="form-grup">
                <label class="form-etiket">Renk</label>
                <div class="satir-esnek" style="gap:10px">
                    <?php foreach (['default' => 'var(--surface-3)', 'sari' => '#f5a524', 'yesil' => '#35c66b', 'mavi' => '#3b9df0', 'pembe' => '#e86b82'] as $rk => $rv): ?>
                    <label style="cursor:pointer"><input type="radio" name="color" value="<?= $rk ?>" <?= $rk === 'default' ? 'checked' : '' ?> class="renk-radio" style="display:none"><span class="etiket-nokta not-renk" data-color="<?= $rk ?>" style="width:26px;height:26px;background:<?= $rv ?>;border:2px solid transparent"></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- Add bookmark -->
<div class="modal-katman" id="modalLink">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yer İmi Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="link_add">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Ad <span class="zorunlu">*</span></label><input name="name" class="girdi" required placeholder="Örn. Marka X Drive klasörü"></div>
            <div class="form-grup"><label class="form-etiket">Adres <span class="zorunlu">*</span></label><input name="url" class="girdi" required placeholder="https://..."></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Ekle</button></div>
    </form></div>
</div>

<script>
/* Note color selection highlight */
document.querySelectorAll('.not-color').forEach(n => n.addEventListener('click', () => {
    document.querySelectorAll('.not-color').forEach(x => x.style.borderColor = 'transparent');
    n.style.borderColor = 'var(--text)';
}));
function notSifirla() {
    document.getElementById('n_id').value = '';
    document.getElementById('n_title').value = '';
    document.getElementById('n_text').value = '';
    document.getElementById('notTitleTop').textContent = 'Yeni Not';
    document.querySelector('input[name=color][value=default]').checked = true;
}
function notEdit(n) {
    document.getElementById('n_id').value = n.id;
    document.getElementById('n_title').value = n.title || '';
    document.getElementById('n_text').value = n.text || '';
    document.getElementById('notTitleTop').textContent = 'Notu Düzenle';
    const radio = document.querySelector(`input[name=renk][value=${n.color}]`);
    if (radio) radio.checked = true;
    modalOpen('modalNot');
}

/* Personal to-dos: without page reload */
function isCounterUpdate() {
    const open = document.querySelectorAll('#isList .kontrol-oge:not(.tamam)').length;
    document.getElementById('isCounter').textContent = open + ' açık';
}
async function isAdd(e) {
    e.preventDefault();
    const girdi = document.getElementById('isNew');
    const name = girdi.value.trim(); if (!name) return false;
    const j = await api('personal_is_add', { name });
    if (j.ok) {
        girdi.value = '';
        const bos = document.getElementById('isBos'); if (bos) bos.remove();
        const div = document.createElement('div');
        div.className = 'kontrol-oge';
        div.innerHTML = `<input type="checkbox" onchange="isToggle(${j.id}, this)"><span class="kontrol-metin"></span><button class="ikon-eylem tehlike" style="width:24px;height:24px" data-action="personal_is_delete" data-id="${j.id}" data-refresh="hayir" onclick="setTimeout(()=>{this.closest('.kontrol-oge').remove();isSayacGuncelle()},300)"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
        div.querySelector('.check-text').textContent = j.name;
        document.getElementById('isList').appendChild(div);
        isCounterUpdate();
    }
    return false;
}
async function isToggle(id, box) {
    const j = await api('personal_is_toggle', { id });
    if (j.ok) { box.closest('.check-oge').classList.toggle('tamam', box.checked); isCounterUpdate(); }
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
