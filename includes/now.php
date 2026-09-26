<?php
/**
 * SADA One — "Now" for a team member
 * What is theirs to do, in the order it should be done. Shared by the Şimdi card (Panel), the Bugün page and Mikasa.
 */

/** Active steps the user holds — plus unowned, skill-less steps of work assigned to them unless they opted out —
 *  overdue first, then by priority and date */
function now_my_steps(array $u, int $limit = 20): array {
    $mine = only_own_steps() ? 's.owner_id=?'
        : '(s.owner_id=? OR (s.owner_id IS NULL AND s.skill_id IS NULL AND (t.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees ta WHERE ta.task_id=t.id AND ta.user_id=?))))';
    $params = only_own_steps() ? [$u['id']] : [$u['id'], $u['id'], $u['id']];
    return rows("SELECT s.id step_id, s.name step_name, s.kind step_kind, s.activated_at, t.id task_id, t.title, t.due_date, t.publish_date, t.priority,
            p.name project_name, c.name client_name
        FROM task_steps s JOIN tasks t ON t.id=s.task_id JOIN projects p ON p.id=t.project_id JOIN clients c ON c.id=p.client_id
        WHERE s.status='active' AND $mine AND t.is_archived=0 AND t.status!='cancelled'
        ORDER BY (t.due_date IS NOT NULL AND t.due_date < CURDATE()) DESC, FIELD(t.priority,'urgent','high','normal','low'),
            COALESCE(t.due_date, t.publish_date) IS NULL, COALESCE(t.due_date, t.publish_date), s.activated_at
        LIMIT " . (int)$limit, $params);
}

/** Work without steps assigned to the user that is theirs to move (to do / in progress — not waiting on a review or the client) */
function now_my_plain_tasks(array $u, int $limit = 10): array {
    return rows("SELECT t.id task_id, t.title, t.status, t.due_date, t.publish_date, t.priority, p.name project_name
        FROM tasks t JOIN projects p ON p.id=t.project_id
        WHERE t.is_archived=0 AND t.status IN ('todo','in_progress') AND NOT EXISTS(SELECT 1 FROM task_steps s WHERE s.task_id=t.id)
            AND (t.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees ta WHERE ta.task_id=t.id AND ta.user_id=?))
        ORDER BY (t.due_date IS NOT NULL AND t.due_date < CURDATE()) DESC, t.due_date IS NULL, t.due_date, FIELD(t.priority,'urgent','high','normal','low')
        LIMIT " . (int)$limit, [$u['id'], $u['id']]);
}

/** Unowned steps waiting in the pools of the user's skills, longest waiting first */
function now_pool(array $u, int $limit = 10): array {
    $skills = user_skill_ids((int)$u['id']);
    if (!$skills) return [];
    [$in, $params] = in_clause($skills);
    return rows("SELECT s.id step_id, s.name step_name, s.activated_at, k.name skill_name, t.id task_id, t.title, t.due_date, p.name project_name
        FROM task_steps s JOIN skills k ON k.id=s.skill_id JOIN tasks t ON t.id=s.task_id JOIN projects p ON p.id=t.project_id
        WHERE s.status='active' AND s.owner_id IS NULL AND s.skill_id IN $in AND t.is_archived=0 AND t.status!='cancelled'
        ORDER BY t.due_date IS NULL, t.due_date, s.activated_at LIMIT " . (int)$limit, $params);
}

/** Today's shoots; `mine` marks the ones the user is on */
function now_shoots_today(array $u): array {
    return rows("SELECT e.id, e.title, e.start, e.place, c.name client_name,
            (e.created_by=? OR EXISTS(SELECT 1 FROM event_participants ep WHERE ep.event_id=e.id AND ep.user_id=?)) mine
        FROM events e LEFT JOIN projects p ON p.id=e.project_id LEFT JOIN clients c ON c.id=COALESCE(e.client_id, p.client_id)
        WHERE e.type='shoot' AND DATE(e.start)=CURDATE() ORDER BY e.start", [$u['id'], $u['id']]);
}

/** Client work planned to go out today */
function now_publish_today(): array {
    return rows("SELECT t.id task_id, t.title, t.publish_time, t.platforms, t.status, c.name client_name
        FROM tasks t JOIN projects p ON p.id=t.project_id JOIN clients c ON c.id=p.client_id
        WHERE t.kind='client' AND t.publish_date=CURDATE() AND t.status!='cancelled' AND t.is_archived=0
        ORDER BY t.publish_time IS NULL, t.publish_time");
}

/** What happened on the user's work lately: their unread notifications of the last two days */
function now_recent(array $u, int $limit = 6): array {
    return rows("SELECT id, title, message, link, created FROM notifications WHERE user_id=? AND is_read=0 AND created >= DATE_SUB(NOW(), INTERVAL 2 DAY)
        ORDER BY id DESC LIMIT " . (int)$limit, [$u['id']]);
}

/** One line about how long a step has been waiting */
function now_waiting(?string $since): string {
    if (!$since) return '';
    $days = (int)floor((time() - strtotime($since)) / 86400);
    return $days <= 0 ? 'bugün geldi' : ($days === 1 ? 'dünden beri' : "$days gündür bekliyor");
}

/** The due / publish hint shown next to a piece of work */
function now_due(array $w): string {
    $today = date('Y-m-d');
    if (!empty($w['due_date'])) {
        if ($w['due_date'] < $today) return '<span style="color:var(--danger)">son tarih geçti · ' . format_date($w['due_date']) . '</span>';
        if ($w['due_date'] === $today) return '<span style="color:var(--warning)">bugün teslim</span>';
        return 'son tarih ' . format_date($w['due_date']);
    }
    if (!empty($w['publish_date'])) return $w['publish_date'] === $today ? '<span style="color:var(--warning)">bugün yayında</span>' : 'yayın ' . format_date($w['publish_date']);
    return '';
}
