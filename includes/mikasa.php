<?php
/**
 * SADA One — Mikasa, the team's assistant in the corner
 * Builds the day's lines for one team member from their own work (at most six; the page shows them sparingly).
 * Every line is written for SADA; customers never see Mikasa.
 */
require_once __DIR__ . '/now.php';

/** Is Mikasa on for this user? (admin switch + the user's own preference; never for customers) */
function mikasa_on(?array $u): bool {
    if (!$u || !in_array($u['role'], ['admin', 'pm', 'team', 'intern', 'finance'], true)) return false;
    if (setting('mikasa_enabled', '1') === '0') return false;
    $pref = json_decode((string)($u['notification_preferences'] ?? ''), true);
    return !is_array($pref) || !isset($pref['mikasa']) || (int)$pref['mikasa'] === 1;
}

/** "14:00'te", "10:30'da" — the locative suffix follows the last number word as it is read aloud */
function mikasa_at(string $hhmm): string {
    [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);
    $n = $m ?: $h;
    $units = [1 => 'de', 'de', 'te', 'te', 'te', 'da', 'de', 'de', 'da'];
    $tens = [0 => 'da', 10 => 'da', 20 => 'de', 30 => 'da', 40 => 'ta', 50 => 'de'];
    return sprintf('%02d:%02d', $h, $m) . "'" . ($n % 10 ? $units[$n % 10] : $tens[$n]);
}

/** Up to six lines for today: [['text' => …, 'link' => …|null], …] */
function mikasa_lines(array $u): array {
    $first = explode(' ', trim($u['name']))[0];
    $hour = (int)date('G'); $weekday = (int)date('N');
    $steps = now_my_steps($u, 6);
    $plain = now_my_plain_tasks($u, 3);
    $pool = now_pool($u, 6);
    $shoots = now_shoots_today($u);
    $publish = now_publish_today();
    $lines = [];
    $add = function (string $text, ?string $link = null) use (&$lines) { $lines[] = ['text' => $text, 'link' => $link]; };

    // 1. The greeting carries the shape of the day
    $greet = $hour < 12 ? 'Günaydın' : ($hour < 18 ? 'Merhaba' : 'İyi akşamlar');
    $load = count($steps) + count($plain);
    if ($weekday === 1 && $hour < 12) $add("$greet $first, yeni hafta. " . ($load ? "Sende $load iş bekliyor; ilkini birlikte bulalım." : 'Masan temiz başlıyor.'), $load ? 'today.php' : null);
    else $add("$greet $first. " . ($load ? "Bugün sırada $load işin var." : 'Şu an sende bekleyen bir adım yok.'), $load ? 'today.php' : null);

    // 2. The first thing to do
    if ($steps) {
        $s = $steps[0];
        $late = $s['due_date'] && $s['due_date'] < date('Y-m-d');
        $add(($late ? 'Önce bunu kapatalım, son tarihi geçti: ' : 'İlk sıradaki: ') . $s['step_name'] . ' — ' . $s['title'] . '.', 'task.php?id=' . $s['task_id']);
    } elseif ($plain) {
        $add('Adımsız işlerinden ilki: ' . $plain[0]['title'] . '.', 'task.php?id=' . $plain[0]['task_id']);
    }

    // 3. Shoots today
    foreach (array_slice($shoots, 0, 1) as $sh) {
        $time = mikasa_at(substr($sh['start'], 11, 5));
        $add($sh['mine'] ? "Bugün $time çekimdesin: {$sh['title']}. Kartlar boş, bataryalar dolu mu?" : "Bugün $time {$sh['title']} çekimi var.", 'shoot-list.php');
    }

    // 4. The pool
    if ($pool) {
        $bySkill = array_count_values(array_column($pool, 'skill_name'));
        arsort($bySkill);
        $skill = array_key_first($bySkill);
        $add("$skill havuzunda sahipsiz {$bySkill[$skill]} iş var. Uygunsan birini alabilirsin.", 'tasks.php');
    }

    // 5. Publishing today
    $toGo = array_filter($publish, fn($p) => $p['status'] !== 'published');
    if ($hour >= 17 && $weekday <= 5 && !(int)val("SELECT COUNT(*) FROM work_logs WHERE user_id=? AND date=CURDATE()", [$u['id']]))
        $add('Gün bitmeden bugün yaptıklarını Çalışma Defteri\'ne yazmayı unutma.', 'worklog.php');
    if ($toGo) $add('Bugün yayına çıkacak ' . count($toGo) . ' iş var' . (($t = current($toGo)['publish_time']) ? '; ilki ' . mikasa_at(substr($t, 0, 5)) . '.' : '.'), 'content-calendar.php');

    // 6. Managers: the month and the client
    if (in_array($u['role'], ['admin', 'pm'], true)) {
        $waiting = (int)val("SELECT COUNT(*) FROM approvals WHERE status='pending' AND created < DATE_SUB(NOW(), INTERVAL 3 DAY)");
        if ($waiting) $add("$waiting onay üç gündür müşteride. Linki WhatsApp'tan yeniden hatırlatmak işe yarayabilir.", 'approvals.php');
        $closing = (int)val("SELECT COUNT(*) FROM periods d JOIN projects p ON p.id=d.project_id WHERE p.status='active' AND d.phase!='closed'
            AND d.year*12+d.month < ?", [(int)date('Y') * 12 + (int)date('n')]);
        if ($closing) $add("Kapanmamış geçmiş $closing ay var. Raporu yazıp kapatınca pano ferahlar.", 'tower.php');
        if ((int)date('j') >= (int)date('t') - 3) $add('Ay bitiyor. Gelecek ayın planı hazır mı?', 'projects.php');
    }

    // 7. The rhythm of the week
    if ($weekday === 5 && $hour >= 14) {
        $pending = (int)val("SELECT COUNT(*) FROM events WHERE type='shoot' AND drive_status='pending' AND start < NOW() AND start > DATE_SUB(NOW(), INTERVAL 14 DAY)");
        $add($pending ? "Cuma. Drive'a aktarılmamış $pending çekim var; hafta sonuna kalmasın." : 'Cuma. Bu hafta çekilenlerin hepsi Drive\'da görünüyor, güzel.', $pending ? 'shoot-list.php' : null);
    }

    // 8. A quiet day gets a tip that fits the panel
    if (count($lines) < 4) {
        $tips = [
            ['İpucu: İş sayfasındaki "Teslim et" dosyanı ekler ve adımı tek seferde bitirir.', null],
            ['İpucu: Müşterinin hesabı yoksa onay linkini kopyalayıp WhatsApp\'tan gönderebilirsin.', null],
            ['İpucu: Çekimi işe bağlarsan, görüntüler Drive\'a geçince çekim adımı kendiliğinden biter.', null],
            ['İpucu: Ay sayfasında "Gündem işi" ay içinde çıkan işleri plandan ayrı tutar.', 'projects.php'],
            ['İpucu: Dosyanın Strateji & Marka kartındaki marka kiti, müşteri işlerinin sayfasında görünür.', 'clients.php'],
            ['Aklındaki yarım fikir kaybolmasın; Fikir Panosu tam bunun için.', 'ideas.php'],
            ['Karalama defterin sağ alttaki kalem düğmesinde; otomatik kaydeder.', null],
        ];
        [$text, $link] = $tips[(int)date('z') % count($tips)];
        $add($text, $link);
    }
    return array_slice($lines, 0, 6);
}
