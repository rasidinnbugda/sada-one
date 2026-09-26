<?php
/**
 * SADA One 7.2 — step engine, one-time data step (after a full dump; settings step_engine=1).
 *  - skills are seeded (Koordinasyon, Tasarım, Kurgu, Çekim, Metin, Geliştirme)
 *  - every task-type step and task step gets a kind (work / review / client_approval / publish) and
 *    a skill, read from its name; the fixed "Revizyon" step goes (revisions are send-back loops now)
 *  - tasks with steps get their task type when the step names match one, and their status is
 *    recomputed from the steps; closed tasks stay closed and their leftover steps are marked done
 *  - people get skills from their job title (the admin confirms them on the users page)
 *  - project templates point at task types instead of workflow templates
 */

const STEP_SKILLS = ['Koordinasyon', 'Tasarım', 'Kurgu', 'Çekim', 'Metin', 'Geliştirme'];

/** Turkish lower case: mb_strtolower turns İ into i + combining dot, which breaks word matching */
function step_tr_lower(string $s): string { return mb_strtolower(strtr($s, ['İ' => 'i', 'I' => 'ı'])); }

/** Kind and skill name of a step, read from its name. */
function step_infer(string $name): array {
    $n = step_tr_lower($name);
    if (preg_match('~müşteri\s*onay~u', $n)) return ['client_approval', 'Koordinasyon'];
    if (preg_match('~yayın|yayına~u', $n)) return ['publish', 'Koordinasyon'];
    if (preg_match('~iç\s*onay|kontrol|test|onay~u', $n)) return ['review', 'Koordinasyon'];
    if (preg_match('~senaryo|metin|yazı|copy|içerik girişi|altyazı~u', $n)) return ['work', 'Metin'];
    if (preg_match('~tasarım|görsel|grafik|afiş~u', $n)) return ['work', 'Tasarım'];
    if (preg_match('~kurgu|montaj|edit~u', $n)) return ['work', 'Kurgu'];
    if (preg_match('~çekim|fotoğraf|kamera~u', $n)) return ['work', 'Çekim'];
    if (preg_match('~geliştir|kodlama|yazılım~u', $n)) return ['work', 'Geliştirme'];
    return ['work', 'Koordinasyon']; // brief, plan, analiz, teslim…
}

/** Skills a job title suggests. */
function skills_from_title(string $title, string $role): array {
    $t = step_tr_lower($title);
    $s = [];
    if (preg_match('~tasar|grafik|illüstr|art direkt~u', $t)) $s[] = 'Tasarım';
    if (preg_match('~kurgu|montaj|video edit|editör~u', $t)) $s[] = 'Kurgu';
    if (preg_match('~çekim|kamera|fotoğraf|görüntü yönet~u', $t)) $s[] = 'Çekim';
    if (preg_match('~metin|copy|yazar|içerik uzman|editör~u', $t)) $s[] = 'Metin';
    if (preg_match('~geliştir|yazılım|developer|frontend|backend|web~u', $t)) $s[] = 'Geliştirme';
    if (preg_match('~proje|koordinat|yönetici|kurucu|account|müşteri temsil|sosyal medya~u', $t) || in_array($role, ['admin', 'pm'], true)) $s[] = 'Koordinasyon';
    return array_values(array_unique($s));
}

function step_engine_migration(PDO $pdo): array {
    $log = [];
    $one = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC) ?: null; };
    $all = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };
    $run = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->rowCount(); };

    // skills
    if (!(int) $one('SELECT COUNT(*) n FROM skills')['n']) {
        foreach (STEP_SKILLS as $i => $name) $run('INSERT INTO skills (name, sort_order) VALUES (?, ?)', [$name, $i + 1]);
        $log[] = 'skills: ' . implode(', ', STEP_SKILLS);
    }
    $skillId = array_column($all('SELECT id, name FROM skills'), 'id', 'name');

    $pdo->beginTransaction();
    try {
        // Revizyon is a loop now, not a step
        $gone = $run("DELETE FROM task_type_steps WHERE LOWER(TRIM(name)) IN ('revizyon', 'revize')");
        $goneTask = $run("DELETE FROM task_steps WHERE LOWER(TRIM(name)) IN ('revizyon', 'revize') AND status='pending'");
        if ($gone + $goneTask) $log[] = "fixed revision steps removed: $gone in task types, $goneTask pending in tasks";

        // kinds and skills from step names (only steps nobody has classified yet)
        $n = 0;
        foreach (['task_type_steps', 'task_steps'] as $table) {
            foreach ($all("SELECT id, name FROM $table WHERE skill_id IS NULL AND kind='work'") as $st) {
                [$kind, $skill] = step_infer($st['name']);
                $n += $run("UPDATE $table SET kind=?, skill_id=? WHERE id=?", [$kind, $skillId[$skill] ?? null, $st['id']]);
            }
        }
        $log[] = "steps classified: $n";

        // task types of existing tasks: the one whose step names match
        $types = [];
        foreach ($all('SELECT id FROM task_types') as $t) $types[$t['id']] = implode('|', array_column($all('SELECT name FROM task_type_steps WHERE type_id=? ORDER BY sort_order', [$t['id']]), 'name'));
        $matched = 0;
        foreach ($all('SELECT DISTINCT task_id FROM task_steps') as $row) {
            $sig = implode('|', array_column($all('SELECT name FROM task_steps WHERE task_id=? ORDER BY sort_order', [$row['task_id']]), 'name'));
            $typeId = array_search($sig, $types, true);
            if ($typeId !== false) $matched += $run('UPDATE tasks SET type_id=? WHERE id=? AND type_id IS NULL', [$typeId, $row['task_id']]);
        }
        $log[] = "tasks matched to their task type: $matched";

        // the active step: exactly the first unfinished one
        $recomputed = 0; $closed = 0;
        foreach ($all('SELECT t.id, t.status, t.completion FROM tasks t WHERE EXISTS (SELECT 1 FROM task_steps s WHERE s.task_id=t.id)') as $t) {
            $steps = $all('SELECT id, status, kind FROM task_steps WHERE task_id=? ORDER BY sort_order, id', [$t['id']]);
            if (in_array($t['status'], ['completed', 'published'], true)) {
                $closed += $run("UPDATE task_steps SET status='done', done_date=COALESCE(done_date, ?) WHERE task_id=? AND status!='done'", [$t['completion'] ?: date('Y-m-d H:i:s'), $t['id']]);
                continue;
            }
            $firstOpen = null;
            foreach ($steps as $s) if ($s['status'] !== 'done') { $firstOpen = $s['id']; break; }
            if ($firstOpen) {
                $run("UPDATE task_steps SET status='pending' WHERE task_id=? AND status='active' AND id!=?", [$t['id'], $firstOpen]);
                $run("UPDATE task_steps SET status='active' WHERE id=?", [$firstOpen]);
            }
            if ($t['status'] === 'cancelled') continue;
            $status = step_status_for(array_map(fn ($s) => ['status' => $s['id'] === $firstOpen ? 'active' : ($s['status'] === 'done' ? 'done' : 'pending'), 'kind' => $s['kind']], $steps));
            $recomputed += $run('UPDATE tasks SET status=?, completion=IF(? IN (\'completed\',\'published\'), COALESCE(completion, NOW()), NULL) WHERE id=? AND status!=?', [$status, $status, $t['id'], $status]);
        }
        $log[] = "task statuses recomputed from steps: $recomputed; leftover steps of closed tasks marked done: $closed";

        // project templates: workflow_id → type_id
        foreach ($all('SELECT id, tasks FROM project_templates') as $pt) {
            $list = json_decode((string) $pt['tasks'], true);
            if (!is_array($list)) continue;
            $changed = false;
            foreach ($list as &$item) if (array_key_exists('workflow_id', $item)) { $item['type_id'] = $item['workflow_id']; unset($item['workflow_id']); $changed = true; }
            unset($item);
            if ($changed) $run('UPDATE project_templates SET tasks=? WHERE id=?', [json_encode($list, JSON_UNESCAPED_UNICODE), $pt['id']]);
        }

        // people: skills from job titles, only for people who have none yet
        $given = 0;
        foreach ($all("SELECT id, role, COALESCE(job_title, '') job_title FROM users WHERE role!='customer' AND NOT EXISTS (SELECT 1 FROM user_skills us WHERE us.user_id=users.id)") as $u) {
            foreach (skills_from_title($u['job_title'], $u['role']) as $skill) if (isset($skillId[$skill])) $given += $run('INSERT IGNORE INTO user_skills (user_id, skill_id) VALUES (?, ?)', [$u['id'], $skillId[$skill]]);
        }
        $log[] = "skills suggested from job titles: $given (check them on the users page)";
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $log;
}

/**
 * The task status a list of steps implies (ordered; each ['status' => pending|active|done, 'kind' => …]).
 * Kept here and not in init.php so the migration runs without the application loaded.
 */
function step_status_for(array $steps): ?string {
    if (!$steps) return null;
    $active = null; $anyDone = false;
    foreach ($steps as $s) {
        if ($s['status'] === 'done') { $anyDone = true; continue; }
        if ($active === null) $active = $s;
    }
    if ($active === null) return end($steps)['kind'] === 'publish' ? 'published' : 'completed';
    return match ($active['kind']) {
        'review' => 'in_review',
        'client_approval' => 'awaiting_approval',
        'publish' => 'completed',
        default => $anyDone ? 'in_progress' : 'todo',
    };
}
