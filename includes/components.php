<?php
/**
 * SADA One — Shared visual components
 * Render functions used across multiple pages.
 */

/** Renders the kanban board */
function task_kanban(array $tasks, int $projectId = 0): void {
    $colors = ['yapilacak' => 'var(--muted)', 'devam' => 'var(--info)', 'incelemede' => 'var(--warning)', 'onayda' => '#a58bf0', 'tamamlandi' => 'var(--basari)'];
?>
<div class="kanban">
    <?php foreach (TASK_STATUSES as $status => $tag):
        $grup = array_filter($tasks, fn($g) => $g['status'] === $status); ?>
    <div class="kanban-sutun" data-status="<?= $status ?>">
        <div class="kanban-sutun-ust"><span class="kanban-nokta" style="background:<?= $colors[$status] ?>"></span><span class="kanban-baslik"><?= $tag ?></span><span class="kanban-sayi"><?= count($grup) ?></span></div>
        <div class="kanban-liste">
            <?php foreach ($grup as $gr):
                // Locked? (dependency task unfinished and no admin has bypassed the lock)
                $locked = !empty($gr['bagimli_status']) && $gr['bagimli_status'] !== 'tamamlandi' && empty($gr['lock_bypassed']);
                $surukle = is_staff() ? 'draggable="true"' : ''; ?>
            <div class="kanban-kart <?= $locked ? 'kilitli' : '' ?>" <?= $surukle ?> data-task="<?= $gr['id'] ?>" data-status="<?= $status ?>" <?= $locked && !empty($gr['bagimli_title']) ? 'title="Kilitli — bağlı olduğu görev: ' . e($gr['bagimli_title']) . '"' : '' ?> onclick="if(!event.defaultPrevented)location.href='task.php?id=<?= $gr['id'] ?>'">
                <div class="kanban-kart-baslik"><?= e($gr['title']) ?></div>
                <?php if (!empty($gr['project_name'])): ?><div class="kanban-etiket" style="margin-bottom:6px"><span class="etiket-nokta" style="width:7px;height:7px;background:<?= e($gr['client_color'] ?? 'var(--marka)') ?>"></span><?= e($gr['project_name']) ?></div><?php endif; ?>
                <?php if (!empty($gr['tags'])): ?><div class="satir-esnek sarma" style="gap:4px;margin-bottom:7px"><?= tag_chips($gr['tags']) ?></div><?php endif; ?>
                <div class="kanban-kart-meta">
                    <?php if ($gr['priority'] !== 'normal'): ?><?= badge($gr['priority'], PRIORITIES) ?><?php endif; ?>
                    <?php if (!empty($gr['repeat']) && $gr['repeat'] !== 'yok'): ?><span class="kanban-etiket" title="<?= REPEAT_OPTIONS[$gr['repeat']] ?>"><?= icon('repeat', 12) ?></span><?php endif; ?>
                    <?php if ($gr['due_date']): $overdue = $gr['due_date'] < date('Y-m-d') && $status !== 'tamamlandi'; ?><span class="kanban-etiket" style="<?= $overdue ? 'color:var(--tehlike)' : '' ?>"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><?= date('j.n', strtotime($gr['due_date'])) ?></span><?php endif; ?>
                    <?php if (!empty($gr['check_total'])): ?><span class="kanban-etiket" style="<?= $gr['check_is_done'] == $gr['check_total'] ? 'color:var(--basari)' : '' ?>"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2m-6 9l2 2 4-4"/></svg><?= $gr['check_is_done'] ?>/<?= $gr['check_total'] ?></span><?php endif; ?>
                </div>
                <?php if (!empty($gr['assignee_name'])): ?><div class="kanban-kart-alt" <?= !empty($gr['assignee_names']) ? 'title="' . e($gr['assignee_names']) . '"' : '' ?>><?= avatar(['name' => $gr['assignee_name'], 'color' => $gr['assignee_color'], 'avatar' => $gr['assignee_avatar'] ?? null], 26) ?><span class="kanban-etiket"><?= e(explode(' ', $gr['assignee_name'])[0]) ?><?= !empty($gr['assignee_count']) && $gr['assignee_count'] > 1 ? ' +' . ($gr['assignee_count'] - 1) : '' ?></span></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php }

/** Renders the task creation modal */
function task_modal(int $projectId, array $team, array $templates, array $periods = []): void {
?>
<div class="modal-katman" id="modalTask">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Görev</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="task_save">
        <input type="hidden" name="project_id" value="<?= $projectId ?>" <?= $projectId ? '' : 'disabled' ?> id="taskProjectId">
        <div class="modal-govde">
            <?php if (!$projectId): ?>
            <div class="form-grup"><label class="form-etiket">Proje <span class="zorunlu">*</span></label><select name="project_id" class="secim" required id="taskProjectSelect"><option value="">Seçin...</option><?php foreach (rows("SELECT id, name FROM projects WHERE status='aktif' ORDER BY name") as $pr): ?><option value="<?= $pr['id'] ?>"><?= e($pr['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="form-grup"><label class="form-etiket">Görev Başlığı <span class="zorunlu">*</span></label><input name="title" class="girdi" required></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="description" class="metin-alani"></textarea></div>
            <div class="form-grup">
                <label class="form-etiket">Atanan Kişiler <span class="metin-muted" style="font-weight:400">(birden fazla seçilebilir)</span></label>
                <input type="hidden" name="assignees" class="atananlar-json">
                <div class="izgara izgara-2" style="gap:6px;max-height:150px;overflow-y:auto;padding:2px">
                    <?php foreach ($team as $k): ?>
                    <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="atanan-kutu" value="<?= $k['id'] ?>"> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($k['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Öncelik</label><select name="priority" class="secim"><?php foreach (PRIORITIES as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <?php if ($periods): ?><div class="form-grup"><label class="form-etiket">Dönem</label><select name="period_id" class="secim"><option value="">—</option><?php foreach ($periods as $d): ?><option value="<?= $d['id'] ?>"><?= period_name($d) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Başlangıç Tarihi</label><input type="date" name="start_date" class="girdi"></div>
                <div class="form-grup"><label class="form-etiket">Son Tarih</label><input type="date" name="due_date" class="girdi"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tahmini Süre (saat)</label><input name="estimated_time" class="girdi" placeholder="Örn. 4,5"></div>
                <div class="form-grup"><label class="form-etiket">Etiketler</label><input name="tags" class="girdi" placeholder="video, instagram (virgülle)"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tekrar</label><select name="repeat" class="secim"><?php foreach (REPEAT_OPTIONS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select><div class="form-ipucu">Her hafta/ay başında taze kopyası oluşturulur.</div></div>
                <?php if ($projectId): $projectTasks = rows("SELECT id, title FROM tasks WHERE project_id=? AND status!='tamamlandi' ORDER BY title", [$projectId]); ?>
                <div class="form-grup"><label class="form-etiket">Bağlı Olduğu Görev</label><select name="bagimli_id" class="secim"><option value="">— Bağımsız</option><?php foreach ($projectTasks as $pg): ?><option value="<?= $pg['id'] ?>"><?= e($pg['title']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilen görev bitmeden bu görev ilerleyemez.</div></div>
                <?php endif; ?>
            </div>
            <div class="form-grup"><label class="form-etiket">Akış Şablonu (opsiyonel)</label><select name="template_id" class="secim"><option value="">Akışsız görev</option><?php foreach ($templates as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilirse görev, şablondaki adımlar üzerinden ilerler.</div></div>
            <?php if ($projectId):
                $projectClientId = (int)val("SELECT client_id FROM projects WHERE id=?", [$projectId]);
                $planliContents = rows("SELECT i.id, i.title, i.date FROM contents i WHERE COALESCE(i.client_id, (SELECT client_id FROM projects p2 WHERE p2.id=i.project_id))=? AND i.status!='yayinlandi' AND i.date>=CURDATE() AND NOT EXISTS(SELECT 1 FROM tasks g2 WHERE g2.content_id=i.id) ORDER BY i.date LIMIT 30", [$projectClientId]); ?>
            <div class="form-grup">
                <label class="form-etiket">İçerik Görevi <span class="metin-muted" style="font-weight:400">(sosyal medya içeriğine bağla)</span></label>
                <select name="content_select" class="secim" onchange="document.getElementById('yeniIcerikAlan-<?= $projectId ?>').style.display=this.value==='yeni'?'grid':'none'">
                    <option value="">— İçerik görevi değil</option>
                    <option value="yeni">+ Yeni içerik oluştur ve bağla</option>
                    <?php foreach ($planliContents as $pi): ?><option value="<?= $pi['id'] ?>"><?= e($pi['title']) ?> (<?= format_date($pi['date']) ?>)</option><?php endforeach; ?>
                </select>
                <div class="form-satir mt-2" id="yeniIcerikAlan-<?= $projectId ?>" style="display:none">
                    <div><label class="form-etiket">Yayın Tarihi</label><input type="date" name="content_date" class="girdi"></div>
                    <div><label class="form-etiket">Platform</label><select name="content_platform" class="secim"><?php foreach (PLATFORMS as $pk => $pv): ?><option value="<?= $pk ?>"><?= $pv ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-ipucu">Görev tamamlanınca içerik onaylanır; içerik yayınlanınca görev tamamlanır.</div>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
    </form></div>
</div>
<?php }

/** Multi-member picker: checkbox list + hidden JSON field (app.js serializes automatically) */
function member_picker(array $selectedIds = [], string $tag = 'Atanan Ekip Üyeleri'): void {
    $team = rows("SELECT id, name, color, avatar FROM users WHERE role IN ('yonetici','pm','ekip','finans') AND is_active=1 ORDER BY name");
?>
<div class="form-grup">
    <label class="form-etiket"><?= e($tag) ?></label>
    <input type="hidden" name="members" class="uye-json">
    <div class="izgara izgara-2" style="gap:6px;max-height:180px;overflow-y:auto;padding:2px">
        <?php foreach ($team as $e): ?>
        <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
            <input type="checkbox" class="uye-kutu" value="<?= $e['id'] ?>" <?= in_array($e['id'], $selectedIds) ? 'checked' : '' ?>>
            <?= avatar($e, 24) ?> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($e['name']) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
</div>
<?php }

/** Shows member avatars stacked on top of each other */
function member_avatars(array $members, int $size = 28): string {
    if (!$members) return '';
    $h = '<span class="avatar-dizi">';
    foreach (array_slice($members, 0, 5) as $member) $h .= avatar($member, $size);
    if (count($members) > 5) $h .= '<span class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;background:var(--surface-3);color:var(--text-2);margin-left:-8px;border:2px solid var(--surface)">+' . (count($members) - 5) . '</span>';
    return $h . '</span>';
}

/** Customer rating modal + JS (printed once per page) */
function rating_modal(): void {
    static $basildi = false;
    if ($basildi || !is_customer()) return;
    $basildi = true;
?>
<div class="modal-katman" id="modalRating">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">İşi Değerlendir</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="rating_give">
        <input type="hidden" name="ref_type" id="p_ref_type"><input type="hidden" name="ref_id" id="p_ref_id"><input type="hidden" name="rating" id="p_rating" value="5">
        <div class="modal-govde">
            <div class="hucre-alt mb-2" id="p_title"></div>
            <div class="orta mb-3" id="ratingStars" style="font-size:34px;cursor:pointer;letter-spacing:6px">
                <?php for ($i = 1; $i <= 5; $i++): ?><span data-rating="<?= $i ?>" style="opacity:1">★</span><?php endfor; ?>
            </div>
            <div class="form-grup"><label class="form-etiket">Yorumunuz (opsiyonel)</label><textarea name="comment_box" class="metin-alani" placeholder="Bu iş hakkında düşünceleriniz..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>Vazgeç</button><button type="submit" class="btn btn-marka">Gönder</button></div>
    </form></div>
</div>
<script>
function ratingGive(refType, refId, title) {
    document.getElementById('p_ref_type').value = refType;
    document.getElementById('p_ref_id').value = refId;
    document.getElementById('p_title').textContent = title;
    ratingSec(5);
    modalOpen('modalRating');
}
function ratingSec(n) {
    document.getElementById('p_rating').value = n;
    document.querySelectorAll('#ratingStars span').forEach(s => {
        s.style.opacity = parseInt(s.dataset.rating) <= n ? '1' : '.25';
        s.style.color = parseInt(s.dataset.rating) <= n ? 'var(--warning)' : 'inherit';
    });
}
document.querySelectorAll('#ratingStars span').forEach(s => {
    s.addEventListener('click', () => ratingSec(parseInt(s.dataset.rating)));
    s.addEventListener('mouseenter', () => ratingSec(parseInt(s.dataset.rating)));
});
</script>
<?php }

/** Embeds the mentionable user list once per page (for mention autocomplete) */
function mention_script(): void {
    static $basildi = false;
    if ($basildi) return;
    $basildi = true;
    $people = is_customer()
        ? rows("SELECT id, name FROM users WHERE is_active=1 AND role!='musteri' ORDER BY name")
        : rows("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
    echo '<script>window.sadaKisiler = ' . json_encode($people, JSON_UNESCAPED_UNICODE) . ';</script>';
}

/** Renders a single comment (root or reply) */
function comment_show(array $y, array $reactions, bool $answer = false): void {
    $u = user();
    $benimki = $y['user_id'] == $u['id'];
    $silebilir = $benimki || is_admin();
    $ar = $y['archive_id'] ? row("SELECT * FROM archive WHERE id=?", [$y['archive_id']]) : null;
    $imageMi = $ar && in_array($ar['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    $yTepkiler = $reactions[$y['id']] ?? [];
?>
<div class="yorum <?= $answer ? 'yorum-yanit' : '' ?>" id="yorum-<?= $y['id'] ?>">
    <div class="satir-esnek" style="gap:11px;align-items:flex-start">
        <?= avatar($y, $answer ? 28 : 34) ?>
        <div style="flex:1;min-width:0">
            <div class="satir-esnek sarma" style="gap:8px">
                <span class="hucre-ana kucuk"><?= e($y['name']) ?></span>
                <span class="hucre-alt"><?= time_ago($y['created']) ?><?= $y['is_edited'] ? ' · düzenlendi' : '' ?></span>
            </div>
            <div class="kucuk metin-2 mt-1 yorum-metin" style="white-space:pre-wrap"><?= highlight_mentions(e($y['message'])) ?></div>
            <?php if ($ar): ?>
            <div class="mt-1">
                <?php if ($imageMi): ?><a href="uploads/<?= e($ar['file_path']) ?>" target="_blank"><img src="uploads/<?= e($ar['file_path']) ?>" style="max-width:220px;max-height:150px;border-radius:10px;border:1px solid var(--border)"></a>
                <?php else: ?><a href="uploads/<?= e($ar['file_path']) ?>" target="_blank" class="btn btn-sm"><?= icon('atac', 12) ?> <?= e($ar['name']) ?></a><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="satir-esnek sarma mt-1" style="gap:6px">
                <!-- Reactions -->
                <?php foreach ($yTepkiler as $emoji => $info): ?>
                <button class="tepki-cip <?= in_array($u['id'], $info['ids']) ? 'benim' : '' ?>" data-comment_box="<?= $y['id'] ?>" data-emoji="<?= e($emoji) ?>" onclick="reaction(<?= $y['id'] ?>,'<?= e($emoji) ?>')" title="<?= e(implode(', ', $info['names'])) ?>"><?= e($emoji) ?> <span class="tepki-adet"><?= count($info['ids']) ?></span></button>
                <?php endforeach; ?>
                <div class="acilir" data-acilir style="display:inline-block">
                    <button class="tepki-cip" data-acilir-btn title="Tepki ver">☺+</button>
                    <div class="acilir-panel" style="min-width:auto;display:flex;gap:2px;padding:5px">
                        <?php foreach (['👍', '❤️', '🎉', '🔥', '😂', '👀'] as $em): ?>
                        <button class="tepki-sec" onclick="reaction(<?= $y['id'] ?>,'<?= $em ?>')"><?= $em ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!$answer): ?><button class="mini-btn" onclick="answerOpen(<?= $y['id'] ?>)">Yanıtla</button><?php endif; ?>
                <?php if ($benimki): ?><button class="mini-btn" onclick="commentEdit(<?= $y['id'] ?>)">Düzenle</button><?php endif; ?>
                <?php if ($silebilir): ?><button class="mini-btn" style="color:var(--tehlike)" data-action="comment_box_delete" data-id="<?= $y['id'] ?>" data-approval="Yorum silinsin mi?">Sil</button><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php }

/** Comment stream: supports threads + reactions + files + mentions */
function comment_feed(string $refType, int $refId): void {
    mention_script();
    $comments = rows("SELECT y.*, u.name, u.color, u.avatar FROM comments y JOIN users u ON u.id=y.user_id WHERE y.ref_type=? AND y.ref_id=? ORDER BY y.id", [$refType, $refId]);
    // Collect reactions
    $reactions = [];
    if ($comments) {
        $ids = implode(',', array_map(fn($y) => (int)$y['id'], $comments));
        foreach (rows("SELECT t.*, u.name FROM comment_box_reactions t JOIN users u ON u.id=t.user_id WHERE t.comment_box_id IN ($ids)") as $t) {
            $reactions[$t['comment_box_id']][$t['emoji']]['ids'][] = (int)$t['user_id'];
            $reactions[$t['comment_box_id']][$t['emoji']]['names'][] = $t['name'];
        }
    }
    $kokler = array_filter($comments, fn($y) => !$y['parent_id']);
    $yanitlar = [];
    foreach ($comments as $y) if ($y['parent_id']) $yanitlar[$y['parent_id']][] = $y;
?>
<div class="dikey" style="gap:16px" id="yorumAkis-<?= e($refType) ?>-<?= $refId ?>">
    <?php foreach ($kokler as $y): ?>
    <div>
        <?php comment_show($y, $reactions); ?>
        <?php foreach ($yanitlar[$y['id']] ?? [] as $yy): comment_show($yy, $reactions, true); endforeach; ?>
        <!-- Reply form (hidden) -->
        <form data-ajax="comment_box_add" class="yorum-yanit mention-kap gizli mt-1" id="yanitForm-<?= $y['id'] ?>" style="display:flex;gap:8px;align-items:flex-end">
            <input type="hidden" name="ref_type" value="<?= e($refType) ?>"><input type="hidden" name="ref_id" value="<?= $refId ?>">
            <input type="hidden" name="parent_id" value="<?= $y['id'] ?>"><input type="hidden" name="mention_ids" class="mention-idler">
            <textarea name="message" class="metin-alani" data-mention style="min-height:40px" placeholder="Yanıt yazın... (@ ile etiketleyin)" required></textarea>
            <button type="submit" class="btn btn-marka btn-sm">Yanıtla</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php if (!$kokler): ?><div class="metin-muted kucuk">Henüz yorum yok. İlk yorumu siz yazın — @ yazarak birini etiketleyebilirsiniz.</div><?php endif; ?>
</div>
<form data-ajax="comment_box_add" class="mention-kap mt-3" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="ref_type" value="<?= e($refType) ?>"><input type="hidden" name="ref_id" value="<?= $refId ?>">
    <input type="hidden" name="mention_ids" class="mention-idler">
    <textarea name="message" class="metin-alani" data-mention style="min-height:44px;flex:1;min-width:200px" placeholder="Yorum yazın... (@ ile etiketleyin)" required></textarea>
    <label class="ikon-eylem" title="Dosya ekle" style="cursor:pointer">
        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="18"><path d="M21.4 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.2-9.19a4 4 0 015.65 5.66l-9.2 9.19a2 2 0 01-2.82-2.83l8.49-8.48"/></svg>
        <input type="file" name="client" style="display:none" onchange="this.parentElement.style.color=this.files.length?'var(--marka)':''">
    </label>
    <button type="submit" class="btn btn-marka">Gönder</button>
</form>
<script>
function answerOpen(id) { const f = document.getElementById('answerForm-' + id); f.classList.toggle('gizli'); if (!f.classList.contains('gizli')) f.querySelector('textarea').focus(); }
async function reaction(commentId, emoji) {
    const j = await api('reaction_toggle', { comment_box_id: commentId, emoji });
    if (!j.ok) return;
    // Without refresh: update / create / remove the existing chip
    let chip = document.querySelector(`.tepki-cip[data-yorum="${commentId}"][data-emoji="${CSS.escape(emoji)}"]`);
    if (j.adet === 0) { if (chip) chip.remove(); }
    else if (chip) {
        chip.querySelector('.tepki-adet').textContent = j.adet;
        chip.classList.toggle('benim', !!j.mine);
    } else {
        chip = document.createElement('button');
        chip.className = 'tepki-cip' + (j.mine ? ' benim' : '');
        chip.dataset.comment_box = commentId; chip.dataset.emoji = emoji;
        chip.onclick = () => reaction(commentId, emoji);
        chip.innerHTML = emoji + ' <span class="tepki-adet">' + j.adet + '</span>';
        const hedefSatir = document.querySelector('#comment_box-' + commentId + ' .acilir');
        if (hedefSatir) hedefSatir.parentElement.insertBefore(chip, hedefSatir);
    }
    if (window.liveRefresh) liveRefresh();
}
function commentEdit(id) {
    const box = document.querySelector('#comment_box-' + id + ' .yorum-metin');
    if (box.dataset.editing) return;
    box.dataset.editing = '1';
    const old = box.innerText;
    box.innerHTML = '';
    const ta = document.createElement('textarea'); ta.className = 'metin-alani'; ta.value = old; ta.style.minHeight = '60px';
    const save = document.createElement('button'); save.className = 'btn btn-marka btn-sm mt-1'; save.textContent = 'Kaydet';
    save.onclick = async () => { const j = await api('comment_box_edit', { id, message: ta.value }); if (j.ok) location.reload(); };
    box.append(ta, save);
    ta.focus();
}
</script>
<?php }
