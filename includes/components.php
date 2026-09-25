<?php
/**
 * SADA One — Shared visual components
 * Render functions used across multiple pages.
 */

/** Renders the kanban board */
function task_kanban(array $tasks, int $projectId = 0): void {
?>
<div class="kanban">
    <?php foreach (TASK_STATUSES as $status => $label):
        $group = array_filter($tasks, fn($g) => $g['status'] === $status); ?>
    <div class="kanban-column" data-status="<?= $status ?>">
        <div class="kanban-column-top"><span class="kanban-dot" style="background:<?= TASK_STATUS_COLORS[$status] ?>"></span><span class="kanban-title"><?= $label ?></span><span class="kanban-count"><?= count($group) ?></span></div>
        <div class="kanban-list">
            <?php foreach ($group as $gr):
                // Locked? (dependency task unfinished and no admin has bypassed the lock)
                $locked = !empty($gr['dependency_status']) && $gr['dependency_status'] !== 'completed' && empty($gr['lock_bypassed']);
                $drag = is_staff() ? 'draggable="true"' : ''; ?>
            <div class="kanban-card <?= $locked ? 'locked' : '' ?>" <?= $drag ?> data-task="<?= $gr['id'] ?>" data-status="<?= $status ?>" <?= $locked && !empty($gr['dependency_title']) ? 'title="Kilitli — bağlı olduğu görev: ' . e($gr['dependency_title']) . '"' : '' ?> onclick="if(!event.defaultPrevented)location.href='task.php?id=<?= $gr['id'] ?>'">
                <div class="kanban-card-title"><?= e($gr['title']) ?></div>
                <?php if (!empty($gr['project_name'])): ?><div class="kanban-label" style="margin-bottom:6px"><span class="label-dot" style="width:7px;height:7px;background:<?= e($gr['client_color'] ?? 'var(--brand)') ?>"></span><?= e($gr['project_name']) ?></div><?php endif; ?>
                <?php if (!empty($gr['tags'])): ?><div class="row-flex wrap" style="gap:4px;margin-bottom:7px"><?= tag_chips($gr['tags']) ?></div><?php endif; ?>
                <div class="kanban-card-meta">
                    <?php if ($gr['priority'] !== 'normal'): ?><?= badge($gr['priority'], PRIORITIES) ?><?php endif; ?>
                    <?php if (!empty($gr['repeat']) && $gr['repeat'] !== 'none'): ?><span class="kanban-label" title="<?= REPEAT_OPTIONS[$gr['repeat']] ?>"><?= icon('repeat', 12) ?></span><?php endif; ?>
                    <?php if ($gr['due_date']): $overdue = $gr['due_date'] < date('Y-m-d') && $status !== 'completed'; ?><span class="kanban-label" style="<?= $overdue ? 'color:var(--danger)' : '' ?>"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><?= date('j.n', strtotime($gr['due_date'])) ?></span><?php endif; ?>
                    <?php if (!empty($gr['check_total'])): ?><span class="kanban-label" style="<?= $gr['check_is_done'] == $gr['check_total'] ? 'color:var(--success)' : '' ?>"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2m-6 9l2 2 4-4"/></svg><?= $gr['check_is_done'] ?>/<?= $gr['check_total'] ?></span><?php endif; ?>
                </div>
                <?php if (!empty($gr['assignee_name'])): ?><div class="kanban-card-bottom" <?= !empty($gr['assignee_names']) ? 'title="' . e($gr['assignee_names']) . '"' : '' ?>><?= avatar(['name' => $gr['assignee_name'], 'color' => $gr['assignee_color'], 'avatar' => $gr['assignee_avatar'] ?? null], 26) ?><span class="kanban-label"><?= e(explode(' ', $gr['assignee_name'])[0]) ?><?= !empty($gr['assignee_count']) && $gr['assignee_count'] > 1 ? ' +' . ($gr['assignee_count'] - 1) : '' ?></span></div><?php endif; ?>
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
<div class="modal-overlay" id="modalTask">
    <div class="modal"><div class="modal-top"><div class="modal-title">Yeni Görev</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="task_save">
        <input type="hidden" name="project_id" value="<?= $projectId ?>" <?= $projectId ? '' : 'disabled' ?> id="taskProjectId">
        <div class="modal-body">
            <?php if (!$projectId): ?>
            <div class="form-group"><label class="form-label">Proje <span class="required">*</span></label><select name="project_id" class="select" required id="taskProjectSelect"><option value="">Seçin...</option><?php foreach (rows("SELECT id, name FROM projects WHERE status='active' ORDER BY name") as $pr): ?><option value="<?= $pr['id'] ?>"><?= e($pr['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="form-group"><label class="form-label">Görev Başlığı <span class="required">*</span></label><input name="title" class="input" required></div>
            <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" class="text-area"></textarea></div>
            <div class="form-group">
                <label class="form-label">Atanan Kişiler <span class="text-muted" style="font-weight:400">(birden fazla seçilebilir)</span></label>
                <input type="hidden" name="assignees" class="assignees-json">
                <div class="grid grid-2" style="gap:6px;max-height:150px;overflow-y:auto;padding:2px">
                    <?php foreach ($team as $k): ?>
                    <label class="row-flex small" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="assigned-box" value="<?= $k['id'] ?>"> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($k['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Öncelik</label><select name="priority" class="select"><?php foreach (PRIORITIES as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <?php if ($periods): ?><div class="form-group"><label class="form-label">Dönem</label><select name="period_id" class="select"><option value="">—</option><?php foreach ($periods as $d): ?><option value="<?= $d['id'] ?>"><?= period_name($d) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Başlangıç Tarihi</label><input type="date" name="start_date" class="input"></div>
                <div class="form-group"><label class="form-label">Son Tarih</label><input type="date" name="due_date" class="input"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tahmini Süre (saat)</label><input name="estimated_time" class="input" placeholder="Örn. 4,5"></div>
                <div class="form-group"><label class="form-label">Etiketler</label><input name="tags" class="input" placeholder="video, instagram (virgülle)"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tekrar</label><select name="repeat" class="select"><?php foreach (REPEAT_OPTIONS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select><div class="form-hint">Her hafta/ay başında taze kopyası oluşturulur.</div></div>
                <?php if ($projectId): $projectTasks = rows("SELECT id, title FROM tasks WHERE project_id=? AND status!='completed' ORDER BY title", [$projectId]); ?>
                <div class="form-group"><label class="form-label">Bağlı Olduğu Görev</label><select name="depends_on_id" class="select"><option value="">— Bağımsız</option><?php foreach ($projectTasks as $pg): ?><option value="<?= $pg['id'] ?>"><?= e($pg['title']) ?></option><?php endforeach; ?></select><div class="form-hint">Seçilen görev bitmeden bu görev ilerleyemez.</div></div>
                <?php endif; ?>
            </div>
            <div class="form-group"><label class="form-label">Akış Şablonu (opsiyonel)</label><select name="template_id" class="select"><option value="">Akışsız görev</option><?php foreach ($templates as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select><div class="form-hint">Seçilirse görev, şablondaki adımlar üzerinden ilerler.</div></div>
            <?php if ($projectId):
                $projectClientId = (int)val("SELECT client_id FROM projects WHERE id=?", [$projectId]);
                $plannedContents = rows("SELECT i.id, i.title, i.date FROM contents i WHERE COALESCE(i.client_id, (SELECT client_id FROM projects p2 WHERE p2.id=i.project_id))=? AND i.status!='published' AND i.date>=CURDATE() AND NOT EXISTS(SELECT 1 FROM tasks g2 WHERE g2.content_id=i.id) ORDER BY i.date LIMIT 30", [$projectClientId]); ?>
            <div class="form-group">
                <label class="form-label">İçerik Görevi <span class="text-muted" style="font-weight:400">(sosyal medya içeriğine bağla)</span></label>
                <select name="content_select" class="select" onchange="document.getElementById('yeniIcerikAlan-<?= $projectId ?>').style.display=this.value==='new'?'grid':'none'">
                    <option value="">— İçerik görevi değil</option>
                    <option value="new">+ Yeni içerik oluştur ve bağla</option>
                    <?php foreach ($plannedContents as $pi): ?><option value="<?= $pi['id'] ?>"><?= e($pi['title']) ?> (<?= format_date($pi['date']) ?>)</option><?php endforeach; ?>
                </select>
                <div class="form-row mt-2" id="yeniIcerikAlan-<?= $projectId ?>" style="display:none">
                    <div><label class="form-label">Yayın Tarihi</label><input type="date" name="content_date" class="input"></div>
                    <div><label class="form-label">Platform</label><select name="content_platform" class="select"><?php foreach (PLATFORMS as $pk => $pv): ?><option value="<?= $pk ?>"><?= $pv ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-hint">Görev tamamlanınca içerik onaylanır; içerik yayınlanınca görev tamamlanır.</div>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Oluştur</button></div>
    </form></div>
</div>
<?php }

/** Multi-member picker: checkbox list + hidden JSON field (app.js serializes automatically) */
function member_picker(array $selectedIds = [], string $label = 'Atanan Ekip Üyeleri'): void {
    $team = rows("SELECT id, name, color, avatar FROM users WHERE role IN ('admin','pm','team','finance') AND is_active=1 ORDER BY name");
?>
<div class="form-group">
    <label class="form-label"><?= e($label) ?></label>
    <input type="hidden" name="members" class="member-json">
    <div class="grid grid-2" style="gap:6px;max-height:180px;overflow-y:auto;padding:2px">
        <?php foreach ($team as $e): ?>
        <label class="row-flex small" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
            <input type="checkbox" class="member-box" value="<?= $e['id'] ?>" <?= in_array($e['id'], $selectedIds) ? 'checked' : '' ?>>
            <?= avatar($e, 24) ?> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($e['name']) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
</div>
<?php }

/** Shows member avatars stacked on top of each other */
function member_avatars(array $members, int $size = 28): string {
    if (!$members) return '';
    $h = '<span class="avatar-stack">';
    foreach (array_slice($members, 0, 5) as $member) $h .= avatar($member, $size);
    if (count($members) > 5) $h .= '<span class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;background:var(--surface-3);color:var(--text-2);margin-left:-8px;border:2px solid var(--surface)">+' . (count($members) - 5) . '</span>';
    return $h . '</span>';
}

/** Customer rating modal + JS (printed once per page) */
function rating_modal(): void {
    static $printed = false;
    if ($printed || !is_customer()) return;
    $printed = true;
?>
<div class="modal-overlay" id="modalRating">
    <div class="modal"><div class="modal-top"><div class="modal-title">İşi Değerlendir</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="rating_give">
        <input type="hidden" name="ref_type" id="p_ref_type"><input type="hidden" name="ref_id" id="p_ref_id"><input type="hidden" name="rating" id="p_rating" value="5">
        <div class="modal-body">
            <div class="cell-bottom mb-2" id="p_title"></div>
            <div class="orta mb-3" id="ratingStars" style="font-size:34px;cursor:pointer;letter-spacing:6px">
                <?php for ($i = 1; $i <= 5; $i++): ?><span data-rating="<?= $i ?>" style="opacity:1">★</span><?php endfor; ?>
            </div>
            <div class="form-group"><label class="form-label">Yorumunuz (opsiyonel)</label><textarea name="comment" class="text-area" placeholder="Bu iş hakkında düşünceleriniz..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn-brand">Gönder</button></div>
    </form></div>
</div>
<script>
function ratingGive(refType, refId, title) {
    document.getElementById('p_ref_type').value = refType;
    document.getElementById('p_ref_id').value = refId;
    document.getElementById('p_title').textContent = title;
    ratingSelect(5);
    modalOpen('modalRating');
}
function ratingSelect(n) {
    document.getElementById('p_rating').value = n;
    document.querySelectorAll('#ratingStars span').forEach(s => {
        s.style.opacity = parseInt(s.dataset.rating) <= n ? '1' : '.25';
        s.style.color = parseInt(s.dataset.rating) <= n ? 'var(--warning)' : 'inherit';
    });
}
document.querySelectorAll('#ratingStars span').forEach(s => {
    s.addEventListener('click', () => ratingSelect(parseInt(s.dataset.rating)));
    s.addEventListener('mouseenter', () => ratingSelect(parseInt(s.dataset.rating)));
});
</script>
<?php }

/** Embeds the mentionable user list once per page (for mention autocomplete) */
function mention_script(): void {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    $people = is_customer()
        ? rows("SELECT id, name FROM users WHERE is_active=1 AND role!='customer' ORDER BY name")
        : rows("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
    echo '<script>window.sadaPeople = ' . json_encode($people, JSON_UNESCAPED_UNICODE) . ';</script>';
}

/** Renders a single comment (root or reply) */
function comment_show(array $y, array $reactions, bool $answer = false): void {
    $u = user();
    $mine = $y['user_id'] == $u['id'];
    $canDelete = $mine || is_admin();
    $ar = $y['archive_id'] ? row("SELECT * FROM archive WHERE id=?", [$y['archive_id']]) : null;
    $imageMi = $ar && in_array($ar['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    $cReactions = $reactions[$y['id']] ?? [];
?>
<div class="comment <?= $answer ? 'comment-reply' : '' ?>" id="comment-<?= $y['id'] ?>">
    <div class="row-flex" style="gap:11px;align-items:flex-start">
        <?= avatar($y, $answer ? 28 : 34) ?>
        <div style="flex:1;min-width:0">
            <div class="row-flex wrap" style="gap:8px">
                <span class="cell-main small"><?= e($y['name']) ?></span>
                <span class="cell-bottom"><?= time_ago($y['created']) ?><?= $y['is_edited'] ? ' · düzenlendi' : '' ?></span>
            </div>
            <div class="small text-2 mt-1 comment-text" style="white-space:pre-wrap"><?= highlight_mentions(e($y['message'])) ?></div>
            <?php if ($ar): ?>
            <div class="mt-1">
                <?php if ($imageMi): ?><a href="uploads/<?= e($ar['file_path']) ?>" target="_blank"><img src="uploads/<?= e($ar['file_path']) ?>" style="max-width:220px;max-height:150px;border-radius:10px;border:1px solid var(--border)"></a>
                <?php else: ?><a href="uploads/<?= e($ar['file_path']) ?>" target="_blank" class="btn btn-sm"><?= icon('paperclip', 12) ?> <?= e($ar['name']) ?></a><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="row-flex wrap mt-1" style="gap:6px">
                <!-- Reactions -->
                <?php foreach ($cReactions as $emoji => $info): ?>
                <button class="reaction-chip <?= in_array($u['id'], $info['ids']) ? 'mine' : '' ?>" data-comment="<?= $y['id'] ?>" data-emoji="<?= e($emoji) ?>" onclick="reaction(<?= $y['id'] ?>,'<?= e($emoji) ?>')" title="<?= e(implode(', ', $info['names'])) ?>"><?= e($emoji) ?> <span class="reaction-count"><?= count($info['ids']) ?></span></button>
                <?php endforeach; ?>
                <div class="dropdown" data-dropdown style="display:inline-block">
                    <button class="reaction-chip" data-dropdown-btn title="Tepki ver">☺+</button>
                    <div class="dropdown-panel" style="min-width:auto;display:flex;gap:2px;padding:5px">
                        <?php foreach (['👍', '❤️', '🎉', '🔥', '😂', '👀'] as $em): ?>
                        <button class="reaction-select" onclick="reaction(<?= $y['id'] ?>,'<?= $em ?>')"><?= $em ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!$answer): ?><button class="mini-btn" onclick="answerOpen(<?= $y['id'] ?>)">Yanıtla</button><?php endif; ?>
                <?php if ($mine): ?><button class="mini-btn" onclick="commentEdit(<?= $y['id'] ?>)">Düzenle</button><?php endif; ?>
                <?php if ($canDelete): ?><button class="mini-btn" style="color:var(--danger)" data-action="comment_delete" data-id="<?= $y['id'] ?>" data-confirm="Yorum silinsin mi?">Sil</button><?php endif; ?>
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
        foreach (rows("SELECT t.*, u.name FROM comment_reactions t JOIN users u ON u.id=t.user_id WHERE t.comment_id IN ($ids)") as $t) {
            $reactions[$t['comment_id']][$t['emoji']]['ids'][] = (int)$t['user_id'];
            $reactions[$t['comment_id']][$t['emoji']]['names'][] = $t['name'];
        }
    }
    $roots = array_filter($comments, fn($y) => !$y['parent_id']);
    $replies = [];
    foreach ($comments as $y) if ($y['parent_id']) $replies[$y['parent_id']][] = $y;
?>
<div class="vertical" style="gap:16px" id="yorumAkis-<?= e($refType) ?>-<?= $refId ?>">
    <?php foreach ($roots as $y): ?>
    <div>
        <?php comment_show($y, $reactions); ?>
        <?php foreach ($replies[$y['id']] ?? [] as $yy): comment_show($yy, $reactions, true); endforeach; ?>
        <!-- Reply form (hidden) -->
        <form data-ajax="comment_add" class="comment-reply mention-wrap hidden mt-1" id="answerForm-<?= $y['id'] ?>" style="display:flex;gap:8px;align-items:flex-end">
            <input type="hidden" name="ref_type" value="<?= e($refType) ?>"><input type="hidden" name="ref_id" value="<?= $refId ?>">
            <input type="hidden" name="parent_id" value="<?= $y['id'] ?>"><input type="hidden" name="mention_ids" class="mention-ids">
            <textarea name="message" class="text-area" data-mention style="min-height:40px" placeholder="Yanıt yazın... (@ ile etiketleyin)" required></textarea>
            <button type="submit" class="btn btn-brand btn-sm">Yanıtla</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php if (!$roots): ?><div class="text-muted small">Henüz yorum yok. İlk yorumu siz yazın — @ yazarak birini etiketleyebilirsiniz.</div><?php endif; ?>
</div>
<form data-ajax="comment_add" class="mention-wrap mt-3" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="ref_type" value="<?= e($refType) ?>"><input type="hidden" name="ref_id" value="<?= $refId ?>">
    <input type="hidden" name="mention_ids" class="mention-ids">
    <textarea name="message" class="text-area" data-mention style="min-height:44px;flex:1;min-width:200px" placeholder="Yorum yazın... (@ ile etiketleyin)" required></textarea>
    <label class="icon-action" title="Dosya ekle" style="cursor:pointer">
        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="18"><path d="M21.4 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.2-9.19a4 4 0 015.65 5.66l-9.2 9.19a2 2 0 01-2.82-2.83l8.49-8.48"/></svg>
        <input type="file" name="file" style="display:none" onchange="this.parentElement.style.color=this.files.length?'var(--brand)':''">
    </label>
    <button type="submit" class="btn btn-brand">Gönder</button>
</form>
<script>
function answerOpen(id) { const f = document.getElementById('answerForm-' + id); f.classList.toggle('hidden'); if (!f.classList.contains('hidden')) f.querySelector('textarea').focus(); }
async function reaction(commentId, emoji) {
    const j = await api('reaction_toggle', { comment_id: commentId, emoji });
    if (!j.ok) return;
    // Without refresh: update / create / remove the existing chip
    let chip = document.querySelector(`.reaction-chip[data-comment="${commentId}"][data-emoji="${CSS.escape(emoji)}"]`);
    if (j.qty === 0) { if (chip) chip.remove(); }
    else if (chip) {
        chip.querySelector('.reaction-count').textContent = j.qty;
        chip.classList.toggle('mine', !!j.mine);
    } else {
        chip = document.createElement('button');
        chip.className = 'reaction-chip' + (j.mine ? ' mine' : '');
        chip.dataset.comment = commentId; chip.dataset.emoji = emoji;
        chip.onclick = () => reaction(commentId, emoji);
        chip.innerHTML = emoji + ' <span class="reaction-count">' + j.qty + '</span>';
        const targetRow = document.querySelector('#comment-' + commentId + ' .dropdown');
        if (targetRow) targetRow.parentElement.insertBefore(chip, targetRow);
    }
    if (window.liveRefresh) liveRefresh();
}
function commentEdit(id) {
    const box = document.querySelector('#comment-' + id + ' .comment-text');
    if (box.dataset.editing) return;
    box.dataset.editing = '1';
    const old = box.innerText;
    box.innerHTML = '';
    const ta = document.createElement('textarea'); ta.className = 'text-area'; ta.value = old; ta.style.minHeight = '60px';
    const save = document.createElement('button'); save.className = 'btn btn-brand btn-sm mt-1'; save.textContent = 'Kaydet';
    save.onclick = async () => { const j = await api('comment_edit', { id, message: ta.value }); if (j.ok) location.reload(); };
    box.append(ta, save);
    ta.focus();
}
</script>
<?php }
