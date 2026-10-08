<?php
/**
 * SADA One — Brand kit of a client file
 * One board for everything the team needs to make work in the client's look and voice: colours (click a colour to
 * copy its code), logos (checked on light, dark and transparent backgrounds, downloaded in one click), typefaces,
 * tone of voice with do / don't rules, the brand folder and free notes. Each part is edited in place.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/brand.php';
$u = require_staff();

$id = (int)($_GET['id'] ?? 0);
$client = row("SELECT * FROM clients WHERE id=?", [$id]);
if (!$client || !client_access($id)) { header('Location: clients.php'); exit; }
$b = brand_data($client);
$canEdit = permission('client_manage');
$rgb = fn(string $hex) => implode(', ', sscanf($hex, '#%02x%02x%02x'));

page_start('Marka Kiti — ' . $client['name'], 'clients');
?>
<div class="row-flex mb-3" style="gap:10px">
    <a href="client.php?id=<?= $id ?>" class="icon-action" title="Dosyaya dön"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="text-muted small"><a href="clients.php">Dosyalar</a> / <a href="client.php?id=<?= $id ?>"><?= e($client['name']) ?></a> / Marka Kiti</span>
</div>

<div class="page-top">
    <div class="row-flex" style="gap:16px">
        <?= client_logo($client, 56, 22) ?>
        <div>
            <div class="page-title"><?= e($client['name']) ?> — Marka Kiti</div>
            <div class="page-bottom">Renkler, logolar, yazı tipleri, ses tonu ve kurallar: işi markanın diliyle yapmak için gereken her şey</div>
        </div>
    </div>
    <?php if ($b['folder'] !== ''): ?>
    <div class="page-top-action"><a class="btn" href="<?= e($b['folder']) ?>" target="_blank" rel="noopener"><?= icon('folder', 16) ?> Marka klasörü ↗</a></div>
    <?php endif; ?>
</div>

<!-- Colours -->
<section class="card bk-section" data-bk="colors">
    <div class="bk-head">
        <div><div class="bk-title">Renkler</div><div class="cell-bottom">Kodunu kopyalamak için renge tıklayın</div></div>
        <?php if ($canEdit): ?><button type="button" class="mini-btn bk-edit-open">Düzenle</button><?php endif; ?>
    </div>
    <div class="bk-view">
        <?php if (!$b['colors']): ?>
        <div class="bk-empty">Henüz renk yok.<?= $canEdit ? ' Ana renk, ikincil renkler ve vurgu rengini HEX kodlarıyla ekleyin.' : '' ?></div>
        <?php else: ?>
        <div class="bk-swatches">
            <?php foreach ($b['colors'] as $c): $hex = brand_hex((string)($c['hex'] ?? '')) ?? '#000000'; ?>
            <button type="button" class="bk-swatch" data-copy="<?= $hex ?>" style="--sw:<?= $hex ?>;--sw-ink:<?= brand_ink($hex) ?>">
                <span class="bk-swatch-fill"><span class="bk-swatch-copy">Kopyala</span></span>
                <span class="bk-swatch-meta">
                    <span class="bk-swatch-name"><?= e(($c['name'] ?? '') ?: 'Renk') ?></span>
                    <span class="bk-swatch-code"><?= $hex ?></span>
                    <span class="bk-swatch-rgb">RGB <?= $rgb($hex) ?></span>
                </span>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
    <form class="bk-edit" data-ajax="brand_save" hidden>
        <input type="hidden" name="client_id" value="<?= $id ?>"><input type="hidden" name="part" value="colors"><input type="hidden" name="items" class="bk-items">
        <div class="bk-rows" data-row="color"></div>
        <button type="button" class="btn btn-sm btn-ghost bk-row-add" data-row="color"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Renk ekle</button>
        <div class="bk-edit-actions"><button type="button" class="btn btn-ghost bk-edit-cancel">Vazgeç</button><button type="submit" class="btn btn-brand">Renkleri kaydet</button></div>
    </form>
    <?php endif; ?>
</section>

<!-- Logos -->
<section class="card bk-section" data-bk="logos">
    <div class="bk-head">
        <div><div class="bk-title">Logolar</div><div class="cell-bottom">Açık, koyu ve şeffaf zeminde kontrol edin; tek tıkla indirin</div></div>
        <?php if ($canEdit): ?><button type="button" class="mini-btn bk-edit-open">+ Logo yükle</button><?php endif; ?>
    </div>
    <div class="bk-view">
        <?php if (!$b['logos']): ?>
        <div class="bk-empty">Henüz logo yok.<?= $canEdit ? ' Ana logo, beyaz/negatif sürüm ve ikon gibi varyasyonları yükleyin (PNG, JPG, WebP; AI, PSD, PDF dosyaları indirilebilir olarak durur).' : '' ?></div>
        <?php else: ?>
        <div class="bk-logos">
            <?php foreach ($b['logos'] as $l): $preview = in_array($l['ext'] ?? '', BRAND_LOGO_PREVIEW, true); ?>
            <div class="bk-logo" data-bg="check">
                <div class="bk-logo-stage"><?php if ($preview): ?><img src="<?= e($l['path']) ?>" alt="<?= e($l['name'] ?? 'Logo') ?>" loading="lazy"><?php else: ?><span class="bk-logo-file"><?= e(strtoupper($l['ext'] ?? '')) ?></span><?php endif; ?></div>
                <div class="bk-logo-meta">
                    <div style="min-width:0"><div class="bold small bk-logo-name"><?= e(($l['name'] ?? '') ?: 'Logo') ?></div><div class="cell-bottom"><?= e(strtoupper($l['ext'] ?? '')) ?></div></div>
                    <div class="row-flex" style="gap:4px">
                        <?php if ($preview): ?>
                        <span class="bk-bg-switch" title="Zemin">
                            <button type="button" data-bg-set="check" aria-label="Şeffaf zemin"></button><button type="button" data-bg-set="light" aria-label="Açık zemin"></button><button type="button" data-bg-set="dark" aria-label="Koyu zemin"></button>
                        </span>
                        <?php endif; ?>
                        <a class="icon-action" href="<?= e($l['path']) ?>" download title="İndir"><svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24" width="16"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14"/></svg></a>
                        <?php if ($canEdit): ?><button type="button" class="icon-action danger" data-action="brand_logo_delete" data-client_id="<?= $id ?>" data-logo="<?= e($l['id'] ?? '') ?>" data-confirm="Logo kitten kaldırılsın mı?" title="Kaldır"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14"><path d="M6 18L18 6M6 6l12 12"/></svg></button><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
    <form class="bk-edit" data-ajax="brand_logo_add" hidden>
        <input type="hidden" name="client_id" value="<?= $id ?>">
        <div class="form-row">
            <div class="form-group"><label class="form-label">Dosya <span class="required">*</span></label><input type="file" name="logo" class="input" required accept=".png,.jpg,.jpeg,.webp,.gif,.pdf,.ai,.psd"></div>
            <div class="form-group"><label class="form-label">Adı</label><input name="name" class="input" placeholder="Ana logo, Beyaz logo, İkon..."></div>
        </div>
        <div class="bk-edit-actions"><button type="button" class="btn btn-ghost bk-edit-cancel">Vazgeç</button><button type="submit" class="btn btn-brand">Yükle</button></div>
    </form>
    <?php endif; ?>
</section>

<div class="bk-pair">
    <!-- Typefaces -->
    <section class="card bk-section" data-bk="fonts">
        <div class="bk-head">
            <div><div class="bk-title">Yazı Tipleri</div><div class="cell-bottom">Bilgisayarınızda yüklüyse kendi harfleriyle görünür</div></div>
            <?php if ($canEdit): ?><button type="button" class="mini-btn bk-edit-open">Düzenle</button><?php endif; ?>
        </div>
        <div class="bk-view">
            <?php if (!$b['fonts']): ?>
            <div class="bk-empty">Henüz yazı tipi yok.<?= $canEdit ? ' Başlık ve metin yazı tiplerini, nerede kullanıldıklarıyla ekleyin.' : '' ?></div>
            <?php else: foreach ($b['fonts'] as $f): ?>
            <div class="bk-font">
                <span class="bk-font-sample" style="font-family:'<?= e(str_replace(["'", '"', ';', '\\'], '', (string)($f['name'] ?? ''))) ?>', var(--font-display)">Aa</span>
                <div style="min-width:0">
                    <div class="bk-font-name" style="font-family:'<?= e(str_replace(["'", '"', ';', '\\'], '', (string)($f['name'] ?? ''))) ?>', var(--font-display)"><?= e($f['name'] ?? '') ?></div>
                    <div class="cell-bottom"><?= e(($f['usage'] ?? '') ?: 'Kullanım belirtilmedi') ?><?php if (!empty($f['url'])): ?> · <a href="<?= e($f['url']) ?>" target="_blank" rel="noopener">indir / gör ↗</a><?php endif; ?></div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <?php if ($canEdit): ?>
        <form class="bk-edit" data-ajax="brand_save" hidden>
            <input type="hidden" name="client_id" value="<?= $id ?>"><input type="hidden" name="part" value="fonts"><input type="hidden" name="items" class="bk-items">
            <div class="bk-rows" data-row="font"></div>
            <button type="button" class="btn btn-sm btn-ghost bk-row-add" data-row="font"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yazı tipi ekle</button>
            <div class="bk-edit-actions"><button type="button" class="btn btn-ghost bk-edit-cancel">Vazgeç</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
        </form>
        <?php endif; ?>
    </section>

    <!-- Tone of voice and rules -->
    <section class="card bk-section" data-bk="voice">
        <div class="bk-head">
            <div><div class="bk-title">Ses Tonu ve Kurallar</div><div class="cell-bottom">Marka nasıl konuşur, neyi asla yapmaz</div></div>
            <?php if ($canEdit): ?><button type="button" class="mini-btn bk-edit-open">Düzenle</button><?php endif; ?>
        </div>
        <div class="bk-view">
            <?php if (trim($b['voice']) === '' && !$b['do'] && !$b['dont']): ?>
            <div class="bk-empty">Henüz ses tonu yazılmadı.<?= $canEdit ? ' Hitap şekli, üslup, sevilen ve kaçınılan ifadeleri ekleyin.' : '' ?></div>
            <?php else: ?>
            <?php if (trim($b['voice']) !== ''): ?><div class="bk-voice"><?= nl2br(e(trim($b['voice']))) ?></div><?php endif; ?>
            <?php if ($b['do'] || $b['dont']): ?>
            <div class="bk-rules">
                <div class="bk-rule-col"><div class="bk-rule-head bk-yes">✓ Yap</div><?php foreach ($b['do'] as $r): ?><div class="bk-rule"><?= e($r) ?></div><?php endforeach; ?><?php if (!$b['do']): ?><div class="cell-bottom">—</div><?php endif; ?></div>
                <div class="bk-rule-col"><div class="bk-rule-head bk-no">✕ Yapma</div><?php foreach ($b['dont'] as $r): ?><div class="bk-rule"><?= e($r) ?></div><?php endforeach; ?><?php if (!$b['dont']): ?><div class="cell-bottom">—</div><?php endif; ?></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
        <form class="bk-edit" data-ajax="brand_save" hidden>
            <input type="hidden" name="client_id" value="<?= $id ?>"><input type="hidden" name="part" value="voice">
            <div class="form-group"><label class="form-label">Ses tonu</label><textarea name="voice" class="text-area" rows="4" placeholder="Samimi ama özenli; okura 'sen' diye hitap eder, kısa cümleler kurar..."><?= e($b['voice']) ?></textarea></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">✓ Yap <span class="text-muted" style="font-weight:400">(her satır bir madde)</span></label><textarea name="do" class="text-area" rows="5" placeholder="Emoji en fazla bir tane"><?= e(implode("\n", $b['do'])) ?></textarea></div>
                <div class="form-group"><label class="form-label">✕ Yapma <span class="text-muted" style="font-weight:400">(her satır bir madde)</span></label><textarea name="dont" class="text-area" rows="5" placeholder="Rakip marka adı geçmez"><?= e(implode("\n", $b['dont'])) ?></textarea></div>
            </div>
            <div class="bk-edit-actions"><button type="button" class="btn btn-ghost bk-edit-cancel">Vazgeç</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
        </form>
        <?php endif; ?>
    </section>
</div>

<!-- Folder and notes -->
<section class="card bk-section" data-bk="notes">
    <div class="bk-head">
        <div><div class="bk-title">Klasör ve Notlar</div><div class="cell-bottom">Kaynak dosyaların durduğu yer ve kalan her şey</div></div>
        <?php if ($canEdit): ?><button type="button" class="mini-btn bk-edit-open">Düzenle</button><?php endif; ?>
    </div>
    <div class="bk-view">
        <?php if ($b['folder'] === '' && $b['notes'] === ''): ?>
        <div class="bk-empty">Marka klasörünün linki ve diğer notlar burada durur.</div>
        <?php else: ?>
        <?php if ($b['folder'] !== ''): ?><div class="mb-2"><a href="<?= e($b['folder']) ?>" target="_blank" rel="noopener" class="bold small"><?= icon('folder', 15) ?> <?= e(preg_replace('~^https?://(www\.)?~', '', $b['folder'])) ?> ↗</a></div><?php endif; ?>
        <?php if ($b['notes'] !== ''): ?><div class="small text-2" style="white-space:pre-wrap"><?= e($b['notes']) ?></div><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
    <form class="bk-edit" data-ajax="brand_save" hidden>
        <input type="hidden" name="client_id" value="<?= $id ?>"><input type="hidden" name="part" value="notes">
        <div class="form-group"><label class="form-label">Marka klasörü linki</label><input name="folder" class="input" value="<?= e($b['folder']) ?>" placeholder="drive.google.com/..."></div>
        <div class="form-group"><label class="form-label">Notlar</label><textarea name="notes" class="text-area" rows="5" placeholder="Logo kullanım alanı, fotoğraf dili, sık kullanılan etiketler..."><?= e($b['notes']) ?></textarea></div>
        <div class="bk-edit-actions"><button type="button" class="btn btn-ghost bk-edit-cancel">Vazgeç</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form>
    <?php endif; ?>
</section>

<script>
(() => {
    // Logo backgrounds: transparent (checker), light, dark
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-bg-set]');
        if (b) b.closest('.bk-logo').dataset.bg = b.dataset.bgSet;
    });
<?php if ($canEdit): ?>
    const COLORS = <?= json_encode(array_map(fn($c) => ['name' => (string)($c['name'] ?? ''), 'hex' => brand_hex((string)($c['hex'] ?? '')) ?? '#000000'], $b['colors']), JSON_UNESCAPED_UNICODE) ?>;
    const FONTS = <?= json_encode(array_map(fn($f) => ['name' => (string)($f['name'] ?? ''), 'usage' => (string)($f['usage'] ?? ''), 'url' => (string)($f['url'] ?? '')], $b['fonts']), JSON_UNESCAPED_UNICODE) ?>;
    const removeBtn = '<button type="button" class="icon-action danger bk-row-remove" title="Kaldır"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14"><path d="M6 18L18 6M6 6l12 12"/></svg></button>';
    const rows = {
        color: (c = {}) => {
            const hex = c.hex || '#182F5D';
            return `<div class="bk-row bk-row-color"><input type="color" class="bk-color" value="${esc(hex)}" aria-label="Renk seç">
                <input class="input bk-hex" value="${esc(hex)}" maxlength="7" placeholder="#182F5D">
                <input class="input bk-name" value="${esc(c.name || '')}" maxlength="60" placeholder="Ana renk, ikincil, vurgu...">${removeBtn}</div>`;
        },
        font: (f = {}) => `<div class="bk-row bk-row-font"><input class="input bk-name" value="${esc(f.name || '')}" maxlength="80" placeholder="Yazı tipi adı">
            <input class="input bk-usage" value="${esc(f.usage || '')}" maxlength="80" placeholder="Başlıklar, metin...">
            <input class="input bk-url" value="${esc(f.url || '')}" maxlength="300" placeholder="Link (isteğe bağlı)">${removeBtn}</div>`,
    };
    const addRow = (box, html) => { box.insertAdjacentHTML('beforeend', html); return box.lastElementChild; };
    document.querySelectorAll('.bk-section').forEach(section => {
        const view = section.querySelector('.bk-view'), form = section.querySelector('.bk-edit');
        const open = section.querySelector('.bk-edit-open');
        if (!form || !open) return;
        const box = form.querySelector('.bk-rows');
        const fill = () => {
            if (!box) return;
            box.innerHTML = '';
            const list = box.dataset.row === 'color' ? COLORS : FONTS;
            (list.length ? list : [{}]).forEach(item => addRow(box, rows[box.dataset.row](item)));
        };
        open.addEventListener('click', () => { fill(); view.hidden = true; form.hidden = false; open.hidden = true; form.querySelector('input:not([type=hidden]), textarea')?.focus(); });
        form.querySelector('.bk-edit-cancel').addEventListener('click', () => { view.hidden = false; form.hidden = true; open.hidden = false; });
        form.querySelector('.bk-row-add')?.addEventListener('click', () => addRow(box, rows[box.dataset.row]()).querySelector('input:not([type=color])').focus());
        form.addEventListener('click', e => { if (e.target.closest('.bk-row-remove')) e.target.closest('.bk-row').remove(); });
        // colour picker ↔ HEX field stay in step
        form.addEventListener('input', e => {
            const row = e.target.closest('.bk-row-color');
            if (!row) return;
            if (e.target.matches('.bk-color')) row.querySelector('.bk-hex').value = e.target.value.toUpperCase();
            if (e.target.matches('.bk-hex')) {
                let v = e.target.value.trim().replace('#', '').toLowerCase();
                if (/^[0-9a-f]{3}$/.test(v)) v = v.replace(/./g, c => c + c);
                if (/^[0-9a-f]{6}$/.test(v)) row.querySelector('.bk-color').value = '#' + v;
            }
        });
        form.addEventListener('submit', () => {
            const items = form.querySelector('.bk-items');
            if (!items) return;
            items.value = JSON.stringify([...box.querySelectorAll('.bk-row')].map(r => box.dataset.row === 'color'
                ? { hex: r.querySelector('.bk-hex').value.trim(), name: r.querySelector('.bk-name').value.trim() }
                : { name: r.querySelector('.bk-name').value.trim(), usage: r.querySelector('.bk-usage').value.trim(), url: r.querySelector('.bk-url').value.trim() }));
        });
    });
<?php endif; ?>
})();
</script>
<?php page_end(); ?>
