<?php
/**
 * SADA One 7.1 — one-time "one deliverable, one record" migration.
 * Contents and approvals used to live next to tasks as separate records; from 7.1 a task (İş)
 * carries its publish plan (date, time, platforms) and its approval history.
 *  - a content linked to tasks merges into the oldest of them; the others get a note
 *  - a content without a task becomes a new task (project: its own, else the client's
 *    monthly/latest project, else a new "<client> — İçerikler" project)
 *  - approvals point at their task; an approval attached to nothing becomes a task itself
 *  - statuses merge: published > completed > waiting on the client > the furthest stage
 * The contents table and tasks.content_id stay as a read-only archive (dropped in a later stage).
 * Runs once, after a full database dump; completion is marked by settings unified_work=1.
 */

/** Did the command list add the 7.1 columns and statuses? The data step must not run without them. */
function work_items_schema_ready(PDO $pdo): bool {
    $s = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tasks' AND column_name='status'");
    $status = (string) $s->fetchColumn();
    $cols = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tasks' AND column_name IN ('kind','publish_date','publish_time','platforms')")->fetchColumn();
    return str_contains($status, "'published'") && str_contains($status, "'cancelled'") && (int) $cols === 4;
}

/** Anything to convert? (fresh installs and already converted databases: no) */
function work_items_needed(PDO $pdo): bool {
    if (!$pdo->query("SHOW TABLES LIKE 'contents'")->fetchColumn()) return false;
    $contents = (int) $pdo->query('SELECT COUNT(*) FROM contents')->fetchColumn();
    $loose = (int) $pdo->query('SELECT COUNT(*) FROM approvals WHERE task_id IS NULL')->fetchColumn();
    return $contents + $loose > 0;
}

/** The merged status of one deliverable from what its task, content and last approval said. */
function work_merge_status(?string $task, ?string $content, ?string $lastApproval): string {
    if ($content === 'published') return 'published';
    if ($task === 'completed' || $content === 'approved') return 'completed';
    if ($lastApproval === 'pending') return 'awaiting_approval';
    $rank = ['todo' => 0, 'in_progress' => 1, 'in_review' => 2, 'awaiting_approval' => 3];
    $fromContent = ['draft' => 'todo', 'revision' => 'in_progress', 'internal_approval' => 'in_review', 'customer_approval' => 'awaiting_approval'];
    $candidates = ['todo'];
    if ($task !== null && isset($rank[$task])) $candidates[] = $task;
    if ($content !== null && isset($fromContent[$content])) $candidates[] = $fromContent[$content];
    if (in_array($lastApproval, ['revision', 'rejected'], true)) $candidates[] = 'in_progress';
    usort($candidates, fn ($a, $b) => $rank[$b] <=> $rank[$a]);
    return $candidates[0];
}

function work_items_migration(PDO $pdo): array {
    $log = [];
    $n = ['merged' => 0, 'shared' => 0, 'from_content' => 0, 'from_approval' => 0, 'approvals_linked' => 0, 'orphans' => 0, 'projects_created' => 0, 'project_fallback' => 0];
    $one = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC) ?: null; };
    $all = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };
    $run = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->rowCount(); };
    $lastApproval = function (string $where, array $p) use ($one) { $r = $one("SELECT status FROM approvals WHERE $where ORDER BY id DESC LIMIT 1", $p); return $r['status'] ?? null; };
    $nextSort = fn (int $projectId) => (int) ($one('SELECT COALESCE(MAX(sort_order),0)+1 n FROM tasks WHERE project_id=?', [$projectId])['n'] ?? 1);
    $periodFor = function (int $projectId, ?string $date) use ($one): ?int {
        if (!$date) return null;
        $p = $one('SELECT type FROM projects WHERE id=?', [$projectId]);
        if (($p['type'] ?? '') !== 'monthly') return null;
        $r = $one('SELECT id FROM periods WHERE project_id=? AND year=? AND month=?', [$projectId, (int) substr($date, 0, 4), (int) substr($date, 5, 2)]);
        return $r ? (int) $r['id'] : null;
    };
    // A client's natural home for loose content: its active monthly project, else its latest project
    $homeProject = function (int $clientId) use ($one, $pdo, &$n): ?int {
        $p = $one("SELECT id FROM projects WHERE client_id=? ORDER BY status='active' DESC, type='monthly' DESC, created DESC LIMIT 1", [$clientId]);
        if ($p) { $n['project_fallback']++; return (int) $p['id']; }
        $c = $one('SELECT name, manager_id FROM clients WHERE id=?', [$clientId]);
        if (!$c) return null;
        $s = $pdo->prepare("INSERT INTO projects (client_id, name, type, status, pm_id, created) VALUES (?, ?, 'monthly', 'active', ?, NOW())");
        $s->execute([$clientId, $c['name'] . ' — İçerikler', $c['manager_id']]);
        $n['projects_created']++;
        return (int) $pdo->lastInsertId();
    };

    $pdo->beginTransaction();
    try {
        // ---- 1. contents
        foreach ($all('SELECT * FROM contents ORDER BY id') as $c) {
            $cid = (int) $c['id'];
            $linked = $all('SELECT * FROM tasks WHERE content_id=? ORDER BY id', [$cid]);
            $approvalStatus = $lastApproval('content_id=?', [$cid]);
            if ($linked) {
                $t = $linked[0];
                $status = work_merge_status($t['status'], $c['status'], $lastApproval('task_id=? OR content_id=?', [$t['id'], $cid]) ?? $approvalStatus);
                $description = trim((string) $t['description']);
                $text = trim((string) $c['description']);
                // The content's own name is often the client-facing one: keep it when it differs from the task title
                if (trim((string) $c['title']) !== '' && mb_strtolower(trim($c['title'])) !== mb_strtolower(trim((string) $t['title']))) $text = 'İçerik adı: ' . trim($c['title']) . ($text === '' ? '' : "\n" . $text);
                if ($text !== '' && $text !== $description) $description = $description === '' ? $text : $description . "\n\n— İçerik metni —\n" . $text;
                $completion = in_array($status, ['completed', 'published'], true) ? ($t['completion'] ?: ($c['date'] . ' 12:00:00')) : null;
                $run('UPDATE tasks SET publish_date=?, publish_time=?, platforms=?, status=?, description=?, completion=?, kind=\'client\' WHERE id=?',
                    [$c['date'], $c['time'], $c['platform'], $status, $description === '' ? null : $description, $completion, $t['id']]);
                $n['merged']++;
                // Other tasks that pointed at the same content stay as their own deliverables
                foreach (array_slice($linked, 1) as $other) {
                    $note = 'Bu iş eskiden #' . $t['id'] . ' ile aynı içeriğe ("' . $c['title'] . '") bağlıydı.';
                    $run('UPDATE tasks SET description=CONCAT(COALESCE(description, \'\'), IF(COALESCE(description, \'\')=\'\', \'\', \'\n\n\'), ?) WHERE id=?', [$note, $other['id']]);
                    $n['shared']++;
                }
                $taskId = (int) $t['id'];
            } else {
                $projectId = $c['project_id'] ? (int) $c['project_id'] : null;
                if ($projectId && !$one('SELECT id FROM projects WHERE id=?', [$projectId])) $projectId = null;
                if (!$projectId && $c['client_id']) $projectId = $homeProject((int) $c['client_id']);
                if (!$projectId) { $log[] = "content #$cid has neither a client nor a project — left in the archive"; $n['orphans']++; continue; }
                $status = work_merge_status(null, $c['status'], $approvalStatus);
                $s = $pdo->prepare('INSERT INTO tasks (project_id, kind, period_id, title, description, created_by, priority, status, publish_date, publish_time, platforms, completion, sort_order, content_id, created)
                    VALUES (?, \'client\', ?, ?, ?, ?, \'normal\', ?, ?, ?, ?, ?, ?, ?, ?)');
                $s->execute([$projectId, $periodFor($projectId, $c['date']), $c['title'], $c['description'] ?: null, (int) $c['created_by'], $status,
                    $c['date'], $c['time'], $c['platform'], in_array($status, ['completed', 'published'], true) ? $c['date'] . ' 12:00:00' : null,
                    $nextSort($projectId), $cid, $c['created']]);
                $taskId = (int) $pdo->lastInsertId();
                $n['from_content']++;
            }
            $n['approvals_linked'] += $run('UPDATE approvals SET task_id=? WHERE content_id=? AND task_id IS NULL', [$taskId, $cid]);
        }

        // ---- 2. approvals attached to nothing: each one was a deliverable sent to the client
        $fromApproval = ['pending' => 'awaiting_approval', 'approved' => 'completed', 'revision' => 'in_progress', 'rejected' => 'in_progress'];
        foreach ($all('SELECT * FROM approvals WHERE task_id IS NULL ORDER BY id') as $a) {
            if (!$one('SELECT id FROM projects WHERE id=?', [(int) $a['project_id']])) { $log[] = "approval #{$a['id']} points at a missing project — left as it is"; $n['orphans']++; continue; }
            $status = $fromApproval[$a['status']] ?? 'in_progress';
            $s = $pdo->prepare('INSERT INTO tasks (project_id, kind, title, description, created_by, priority, status, completion, sort_order, created)
                VALUES (?, \'client\', ?, ?, ?, \'normal\', ?, ?, ?, ?)');
            $s->execute([(int) $a['project_id'], $a['title'], $a['description'] ?: null, (int) $a['sender_id'], $status,
                $status === 'completed' ? ($a['reply_date'] ?: $a['created']) : null, $nextSort((int) $a['project_id']), $a['created']]);
            $run('UPDATE approvals SET task_id=? WHERE id=?', [(int) $pdo->lastInsertId(), $a['id']]);
            $n['from_approval']++;
        }

        // ---- 3. a task whose last approval is still unanswered is waiting on the client
        $waiting = $run("UPDATE tasks t JOIN approvals a ON a.id=(SELECT MAX(a2.id) FROM approvals a2 WHERE a2.task_id=t.id)
            SET t.status='awaiting_approval' WHERE a.status='pending' AND t.status IN ('todo','in_progress','in_review')");

        // ---- 4. files sent for approval show up among the task's attachments
        $files = $run('UPDATE archive ar JOIN approvals a ON a.archive_id=ar.id SET ar.task_id=a.task_id WHERE ar.task_id IS NULL AND a.task_id IS NOT NULL');

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $log[] = "contents merged into their task: {$n['merged']}, became a new task: {$n['from_content']} (placed in a client project: {$n['project_fallback']}, new projects: {$n['projects_created']})";
    if ($n['shared']) $log[] = "tasks that shared a content with another task (noted): {$n['shared']}";
    $log[] = "approvals linked to their task: {$n['approvals_linked']}, approvals that became a task: {$n['from_approval']}, tasks now waiting on the client: $waiting, approval files attached to tasks: $files";
    if ($n['orphans']) $log[] = "left untouched: {$n['orphans']}";
    return $log;
}
