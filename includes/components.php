<?php
/**
 * SADA One — Shared visual components
 * Render functions used across multiple pages.
 */

/** Renders the kanban board (cancelled work is not on the board) */
function task_kanban(array $tasks, int $projectId = 0): void {
?>
<div class="kanban">
    <?php foreach (TASK_STATUSES as $status => $label): if ($status === 'cancelled') continue;
        $group = array_filter($tasks, fn($g) => $g['status'] === $status); ?>
    <div class="kanban-column" data-status="<?= $status ?>">
        <div class="kanban-column-top"><span class="kanban-dot" style="background:<?= TASK_STATUS_COLORS[$status] ?>"></span><span class="kanban-title"><?= $label ?></span><span class="kanban-count"><?= count($group) ?></span></div>
        <div class="kanban-list">
            <?php foreach ($group as $gr):
                // Locked? (dependency still open and no admin has bypassed the lock)
                $locked = !empty($gr['dependency_status']) && task_is_open($gr['dependency_status']) && empty($gr['lock_bypassed']);
                $drag = is_staff() && empty($gr['step_total']) ? 'draggable="true"' : ''; ?>
            <div class="kanban-card <?= $locked ? 'locked' : '' ?>" <?= $drag ?> data-task="<?= $gr['id'] ?>" data-status="<?= $status ?>" <?= $locked && !empty($gr['dependency_title']) ? 'title="Kilitli — bağlı olduğu iş: ' . e($gr['dependency_title']) . '"' : '' ?> onclick="if(!event.defaultPrevented)location.href='task.php?id=<?= $gr['id'] ?>'">
                <div class="kanban-card-title"><?= e($gr['title']) ?></div>
                <?php if (!empty($gr['project_name'])): ?><div class="kanban-label" style="margin-bottom:6px"><span class="label-dot" style="width:7px;height:7px;background:<?= e($gr['client_color'] ?? 'var(--brand)') ?>"></span><?= e($gr['project_name']) ?></div><?php endif; ?>
                <?php if (!empty($gr['tags'])): ?><div class="row-flex wrap" style="gap:4px;margin-bottom:7px"><?= tag_chips($gr['tags']) ?></div><?php endif; ?>
                <div class="kanban-card-meta">
                    <?php if (($gr['kind'] ?? 'client') === 'internal'): ?><span class="badge badge-type" style="padding:1px 7px">İç iş</span><?php endif; ?>
                    <?php if ($gr['priority'] !== 'normal'): ?><?= badge($gr['priority'], PRIORITIES) ?><?php endif; ?>
                    <?php if (!empty($gr['repeat']) && $gr['repeat'] !== 'none'): ?><span class="kanban-label" title="<?= REPEAT_OPTIONS[$gr['repeat']] ?>"><?= icon('repeat', 12) ?></span><?php endif; ?>
                    <?php if (!empty($gr['publish_date'])): ?><span class="kanban-label" style="color:var(--brand)" title="Yayın tarihi"><?= platform_badges($gr['platforms'] ?? '', true) ?><?= date('j.n', strtotime($gr['publish_date'])) ?></span><?php endif; ?>
                    <?php if ($gr['due_date']): $overdue = $gr['due_date'] < date('Y-m-d') && task_is_open($status); ?><span class="kanban-label" style="<?= $overdue ? 'color:var(--danger)' : '' ?>" title="Son tarih"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><?= date('j.n', strtotime($gr['due_date'])) ?></span><?php endif; ?>
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

/** Kind (client / internal) and publish plan fields, shared by the new-task, edit and calendar forms */
function task_publish_fields(?array $task = null, bool $withKind = true): void {
    $kind = $task['kind'] ?? 'client';
    $selected = array_filter(explode(',', (string)($task['platforms'] ?? '')));
?>
    <?php if ($withKind): ?>
    <div class="form-group">
        <label class="form-label">Tür</label>
        <div class="row-flex wrap" style="gap:8px">
            <?php foreach (TASK_KINDS as $k => $v): ?>
            <label class="row-flex small" style="gap:7px;padding:7px 12px;background:var(--surface-2);border-radius:9px;cursor:pointer"><input type="radio" name="kind" value="<?= $k ?>" <?= $kind === $k ? 'checked' : '' ?>> <?= $v ?></label>
            <?php endforeach; ?>
        </div>
        <div class="form-hint">İç işler müşteriye gönderilmez ve yayın planı olmaz; ay planı sayımlarına girmez.</div>
    </div>
    <?php else: ?><input type="hidden" name="kind" value="client"><?php endif; ?>
    <div class="publish-fields" <?= $kind === 'client' ? '' : 'hidden' ?>>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Yayın Tarihi</label><input type="date" name="publish_date" class="input" value="<?= e($task['publish_date'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Yayın Saati</label><input type="time" name="publish_time" class="input" value="<?= e(substr((string)($task['publish_time'] ?? ''), 0, 5)) ?>"></div>
        </div>
        <div class="form-group">
            <label class="form-label">Platformlar <span class="text-muted" style="font-weight:400">(birden fazla seçilebilir)</span></label>
            <input type="hidden" name="platforms" class="platforms-json">
            <div class="row-flex wrap" style="gap:6px">
                <?php foreach (PLATFORMS as $k => $v): ?>
                <label class="row-flex small" style="gap:7px;padding:7px 12px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                    <input type="checkbox" class="platform-box" value="<?= $k ?>" <?= in_array($k, $selected, true) ? 'checked' : '' ?>> <?= icon(isset(ICONS[$k]) ? $k : 'other', 14) ?> <?= $v ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="form-hint">Yayın tarihi olan işler içerik takviminde görünür.</div>
        </div>
    </div>
<?php }

/** Renders the task creation modal */
function task_modal(int $projectId, array $team, array $templates, array $periods = []): void {
?>
<div class="modal-overlay" id="modalTask">
    <div class="modal"><div class="modal-top"><div class="modal-title">Yeni İş</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="task_save">
        <input type="hidden" name="project_id" value="<?= $projectId ?>" <?= $projectId ? '' : 'disabled' ?> id="taskProjectId" data-pm="<?= $projectId ? (int)val("SELECT pm_id FROM projects WHERE id=?", [$projectId]) : '' ?>">
        <div class="modal-body">
            <?php if (!$projectId): ?>
            <div class="form-group"><label class="form-label">Proje <span class="required">*</span></label><select name="project_id" class="select" required id="taskProjectSelect"><option value="">Seçin...</option><?php foreach (rows("SELECT id, name, pm_id FROM projects WHERE status='active' ORDER BY name") as $pr): ?><option value="<?= $pr['id'] ?>" data-pm="<?= (int)$pr['pm_id'] ?>"><?= e($pr['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="form-group"><label class="form-label">İş Başlığı <span class="required">*</span></label><input name="title" class="input" required></div>
            <div class="form-group">
                <label class="form-label">İş Türü</label>
                <select name="type_id" class="select native-select task-type-select"><option value="">Adımsız iş — durumu elle yönetilir</option><?php foreach ($templates as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
                <input type="hidden" name="step_owners" class="step-owners-json">
                <div class="type-steps vertical mt-2" style="gap:6px"></div>
                <div class="form-hint type-hint">Tür seçilirse iş, türün adımlarından geçer; durumu adımlar belirler. Kişi seçilmeyen adım uzmanlığın havuzuna düşer.</div>
            </div>
            <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" class="text-area"></textarea></div>
            <?php task_publish_fields(); ?>
            <div class="form-group assignee-block">
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
                <?php if ($projectId): $projectTasks = rows("SELECT id, title FROM tasks WHERE project_id=? AND " . task_open_sql() . " ORDER BY title", [$projectId]); ?>
                <div class="form-group"><label class="form-label">Bağlı Olduğu İş</label><select name="depends_on_id" class="select"><option value="">— Bağımsız</option><?php foreach ($projectTasks as $pg): ?><option value="<?= $pg['id'] ?>"><?= e($pg['title']) ?></option><?php endforeach; ?></select><div class="form-hint">Seçilen iş bitmeden bu iş ilerleyemez.</div></div>
                <?php endif; ?>
            </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Oluştur</button></div>
    </form></div>
</div>
<?php task_type_picker_script($team); ?>
<?php }

/** Data + behaviour of the task-type picker (printed once per page) */
function task_type_picker_script(array $team): void {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    $types = [];
    foreach (rows("SELECT id, name, kind FROM task_types ORDER BY name") as $t) {
        $t['steps'] = rows("SELECT s.id, s.name, s.kind, s.owner_id, s.skill_id, k.name skill FROM task_type_steps s LEFT JOIN skills k ON k.id=s.skill_id WHERE s.type_id=? ORDER BY s.sort_order, s.id", [$t['id']]);
        $types[$t['id']] = $t;
    }
    $holders = [];
    foreach (rows("SELECT skill_id, user_id FROM user_skills") as $r) $holders[$r['skill_id']][] = (int)$r['user_id'];
    $coordination = (int)val("SELECT id FROM skills WHERE name='Koordinasyon'");
?>
<script>
(() => {
    const TYPES = <?= json_encode($types, JSON_UNESCAPED_UNICODE) ?>;
    const TEAM = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name']], $team), JSON_UNESCAPED_UNICODE) ?>;
    const HOLDERS = <?= json_encode($holders) ?>;
    const COORDINATION = <?= $coordination ?>;
    const pmOf = form => {
        const fixed = form.querySelector('input[name=project_id]:not([disabled])');
        if (fixed && fixed.dataset.pm) return fixed.dataset.pm;
        return form.querySelector('select[name=project_id]')?.selectedOptions[0]?.dataset.pm || '';
    };
    const render = form => {
        const box = form.querySelector('.type-steps'), select = form.querySelector('.task-type-select');
        if (!box || !select) return;
        const type = TYPES[select.value];
        box.innerHTML = '';
        form.querySelectorAll('.assignee-block').forEach(b => { b.hidden = !!(type && type.steps.length); });
        if (!type) return;
        const kind = form.querySelector(`input[name=kind][value="${type.kind}"]`);
        if (kind && !kind.checked) { kind.checked = true; kind.dispatchEvent(new Event('change', { bubbles: true })); }
        const pm = pmOf(form);
        type.steps.forEach(step => {
            const skilled = new Set((HOLDERS[step.skill_id] || []).map(String));
            const preset = step.owner_id ? String(step.owner_id) : (Number(step.skill_id) === COORDINATION && pm ? String(pm) : '0');
            const people = [...TEAM].sort((a, b) => skilled.has(String(b.id)) - skilled.has(String(a.id)));
            const row = document.createElement('div');
            row.className = 'row-flex between';
            row.style.cssText = 'gap:8px;padding:6px 10px;background:var(--surface-2);border-radius:9px';
            row.innerHTML = `<span class="small" style="min-width:0"><b>${esc(step.name)}</b> <span class="cell-bottom">· ${esc(step.skill || 'uzmanlık yok')}</span></span>
                <select class="select native-select step-owner" data-step="${step.id}" style="width:auto;max-width:200px;padding:5px 28px 5px 10px;font-size:12px">
                    <option value="0">${step.skill ? 'Havuz — ' + esc(step.skill) : 'Kişisiz'}</option>
                    ${people.map(p => `<option value="${p.id}" ${String(p.id) === preset ? 'selected' : ''}>${skilled.has(String(p.id)) ? '★ ' : ''}${esc(p.name)}</option>`).join('')}
                </select>`;
            box.appendChild(row);
        });
    };
    document.addEventListener('change', e => {
        if (e.target.matches('.task-type-select') || e.target.matches('select[name=project_id]')) render(e.target.form);
    });
})();
</script>
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
