<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
$project = row("SELECT p.*, d.name client_name, d.color client_color, uu.name pm_name FROM projects p JOIN clients d ON d.id=p.client_id LEFT JOIN users uu ON uu.id=p.pm_id WHERE p.id=?", [$id]);
if (!$project || !project_access($id)) { header('Location: projects.php'); exit; }

$tasks = rows("SELECT g.*, u.name assignee_name, u.color assignee_color, u.avatar assignee_avatar,
    bg.status bagimli_status, bg.title bagimli_title,
    (SELECT COUNT(*) FROM task_checklist k WHERE k.task_id=g.id) check_total,
    (SELECT COUNT(*) FROM task_checklist k WHERE k.task_id=g.id AND k.is_done=1) check_is_done,
    (SELECT COUNT(*) FROM task_assignees gaa WHERE gaa.task_id=g.id) assignee_count,
    (SELECT GROUP_CONCAT(u3.name SEPARATOR ', ') FROM task_assignees ga3 JOIN users u3 ON u3.id=ga3.user_id WHERE ga3.task_id=g.id) assignee_names
    FROM tasks g LEFT JOIN users u ON u.id=g.assignee_id LEFT JOIN tasks bg ON bg.id=g.bagimli_id
    WHERE g.project_id=? AND g.is_archived=0 ORDER BY g.sort_order, g.due_date IS NULL, g.due_date", [$id]);
$doneTask = count(array_filter($tasks, fn($g) => $g['status'] === 'tamamlandi'));
$rate = count($tasks) ? round($doneTask / count($tasks) * 100) : 0;

$contents = rows("SELECT * FROM contents WHERE project_id=? ORDER BY date DESC LIMIT 30", [$id]);
$approvals = rows("SELECT o.*, u.name sender_name FROM approvals o LEFT JOIN users u ON u.id=o.sender_id WHERE o.project_id=? ORDER BY o.id DESC", [$id]);
$archives = rows("SELECT a.*, u.name uploader_name FROM archive a LEFT JOIN users u ON u.id=a.uploader_id WHERE a.project_id=? ORDER BY a.id DESC", [$id]);
$activities = rows("SELECT a.*, u.name FROM activities a JOIN users u ON u.id=a.user_id WHERE (a.ref_type='proje' AND a.ref_id=?) ORDER BY a.id DESC LIMIT 30", [$id]);
$periods = $project['type'] === 'aylik' ? rows("SELECT d.*, (SELECT COUNT(*) FROM tasks g WHERE g.period_id=d.id) task_count FROM periods d WHERE d.project_id=? ORDER BY d.year DESC, d.month DESC", [$id]) : [];
$team = rows("SELECT id, name, color FROM users WHERE role IN ('yonetici','pm','ekip') AND is_active=1 ORDER BY name");
$templates = rows("SELECT * FROM workflow_templates ORDER BY name");
$projectMembers = rows("SELECT u.id, u.name, u.color, u.avatar, u.job_title FROM project_members pu JOIN users u ON u.id=pu.user_id WHERE pu.project_id=? AND u.is_active=1 ORDER BY u.name", [$id]);

// Station (SOP) data — visible only to staff
$budgetGor = permission('butce_gor');
$checkList = is_staff() ? rows("SELECT k.*, u.name owner_name FROM project_checklist k LEFT JOIN users u ON u.id=k.owner_id WHERE k.project_id=? ORDER BY k.sort_order", [$id]) : [];
$ekRequests = $budgetGor ? rows("SELECT t.*, u.name creator_name FROM project_extra_requests t LEFT JOIN users u ON u.id=t.created_by WHERE t.project_id=? ORDER BY t.id DESC", [$id]) : [];
$reviews = [];
if (is_staff()) foreach (rows("SELECT * FROM project_review WHERE project_id=?", [$id]) as $dg) $reviews[$dg['type']] = $dg;
$revisionCount = (int)val("SELECT COUNT(*) FROM approvals WHERE project_id=? AND status='revize'", [$id]);
$teamRoles = json_decode($project['team_roles'] ?? '', true) ?: [];

page_start($project['name'], 'projects');
?>
<div class="satir-esnek mb-3" style="gap:10px">
    <a href="client.php?id=<?= $project['client_id'] ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="metin-muted kucuk"><a href="client.php?id=<?= $project['client_id'] ?>" style="color:inherit"><?= e($project['client_name']) ?></a> / <?= e($project['name']) ?></span>
</div>

<div class="sayfa-ust">
    <div>
        <div class="satir-esnek" style="gap:10px">
            <span class="rozet rozet-tur"><?= PROJECT_TYPES[$project['type']] ?></span>
            <?= badge($project['status'], PROJECT_STATUSES) ?>
        </div>
        <div class="sayfa-baslik mt-1"><?= e($project['name']) ?></div>
        <?php if ($project['pm_name']): ?><div class="sayfa-alt">Proje Yöneticisi: <?= e($project['pm_name']) ?></div><?php endif; ?>
    </div>
    <?php if (is_staff()): ?>
    <div class="sayfa-ust-aksiyon">
        <a href="report.php?project=<?= $id ?>" target="_blank" class="btn"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 17h6M9 13h6M9 9h1m4 12H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6a1 1 0 01.7.3l5.4 5.4a1 1 0 01.3.7V19a2 2 0 01-2 2z"/></svg> Rapor</a>
        <button class="btn btn-marka" data-modal="modalTask"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Görev Ekle</button>
        <?php if (permission('dosya_yonet')): ?><button class="btn" onclick="modalOpen('modalProjectDuzen')"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="sekme-kap">
    <div class="sekmeler">
        <button class="sekme aktif" data-sekme="ozet">Özet</button>
        <button class="sekme" data-sekme="gorevler">Görevler <span class="rozet" style="padding:1px 7px"><?= count($tasks) ?></span></button>
        <?php if ($project['type'] === 'aylik'): ?><button class="sekme" data-sekme="donemler">Dönemler</button><?php endif; ?>
        <button class="sekme" data-sekme="onaylar">Onaylar <?php if ($b = count(array_filter($approvals, fn($o) => $o['status'] === 'bekliyor'))): ?><span class="rozet r-bekliyor" style="padding:1px 7px"><?= $b ?></span><?php endif; ?></button>
        <button class="sekme" data-sekme="icerik">İçerikler</button>
        <?php if (is_staff()): ?><button class="sekme" data-sekme="istasyon">İstasyon <?php if ($checkList && ($eksikK = count(array_filter($checkList, fn($k) => !$k['is_done'])))): ?><span class="rozet r-bekliyor" style="padding:1px 7px"><?= $eksikK ?></span><?php endif; ?></button><?php endif; ?>
        <button class="sekme" data-sekme="tartisma">Tartışma <?php if ($commentCount = (int)val("SELECT COUNT(*) FROM comments WHERE ref_type='proje' AND ref_id=?", [$id])): ?><span class="rozet" style="padding:1px 7px"><?= $commentCount ?></span><?php endif; ?></button>
        <button class="sekme" data-sekme="arsiv">Arşiv</button>
        <button class="sekme" data-sekme="aktivite">Aktivite</button>
    </div>

    <!-- SUMMARY -->
    <div class="sekme-icerik aktif" id="sekme-ozet">
        <div class="stat-izgara">
            <div class="stat-kart"><div class="stat-deger"><?= $rate ?>%</div><div class="stat-etiket">Tamamlanma</div><div class="ilerleme mt-2"><div class="ilerleme-dolu" data-rate="<?= $rate ?>" style="width:0"></div></div></div>
            <div class="stat-kart"><div class="stat-deger" data-counter="<?= count($tasks) ?>">0</div><div class="stat-etiket">Toplam Görev</div></div>
            <div class="stat-kart"><div class="stat-deger" data-counter="<?= count(array_filter($tasks, fn($g) => in_array($g['status'], ['devam','incelemede','onayda']))) ?>">0</div><div class="stat-etiket">Devam Eden</div></div>
            <?php if (is_pm() && $project['contract_amount'] > 0): ?>
            <div class="stat-kart"><div class="stat-deger" style="font-size:22px"><?= money($project['contract_amount']) ?></div><div class="stat-etiket">Sözleşme Tutarı</div></div>
            <?php endif; ?>
        </div>
        <div class="izgara izgara-2">
            <div class="kart">
                <div class="kart-baslik mb-2">Proje Bilgileri</div>
                <div class="dikey mt-2" style="gap:12px">
                    <div class="satir-esnek arasi"><span class="hucre-alt">Dosya</span><span class="hucre-ana"><?= e($project['client_name']) ?></span></div>
                    <div class="satir-esnek arasi"><span class="hucre-alt">Tür</span><span><?= PROJECT_TYPES[$project['type']] ?></span></div>
                    <div class="satir-esnek arasi"><span class="hucre-alt">Başlangıç</span><span><?= format_date($project['start']) ?></span></div>
                    <div class="satir-esnek arasi"><span class="hucre-alt">Bitiş</span><span><?= format_date($project['end']) ?></span></div>
                    <?php if ($projectMembers): ?>
                    <div class="satir-esnek arasi"><span class="hucre-alt">Atanan Ekip</span><?= member_avatars($projectMembers) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($project['description']): ?><div class="mt-3"><div class="hucre-alt mb-2">Açıklama</div><div class="kucuk metin-2"><?= nl2br(e($project['description'])) ?></div></div><?php endif; ?>
            </div>
            <div class="kart">
                <div class="kart-baslik mb-2">Yaklaşan Görevler</div>
                <?php $upcoming = array_filter($tasks, fn($g) => $g['status'] !== 'tamamlandi' && $g['due_date']);
                usort($upcoming, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));
                $upcoming = array_slice($upcoming, 0, 5);
                if (!$upcoming): ?><div class="metin-muted kucuk mt-2">Tarihi belirlenmiş görev yok.</div>
                <?php else: foreach ($upcoming as $gr): $overdue = $gr['due_date'] < date('Y-m-d'); ?>
                <a href="task.php?id=<?= $gr['id'] ?>" class="satir-esnek arasi" style="padding:10px 0;border-bottom:1px solid var(--border)">
                    <span class="kucuk kalin" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($gr['title']) ?></span>
                    <span class="rozet <?= $overdue ? 'r-acil' : 'r-normal' ?>"><?= format_date($gr['due_date']) ?></span>
                </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <?php if (is_customer()):
        $completed_items = array_filter($tasks, fn($g) => $g['status'] === 'tamamlandi');
        $gorevPuanlari = array_column(rows("SELECT ref_id, rating FROM ratings WHERE ref_type='gorev' AND user_id=? AND project_id=?", [$u['id'], $id]), 'rating', 'ref_id');
        if ($completed_items): ?>
    <!-- Client: rate completed work -->
    <div class="kart mt-3" id="sekme-ozet-puanlama">
        <div class="kart-baslik mb-2"><?= icon('star', 16) ?> Tamamlanan İşleri Değerlendirin</div>
        <div class="hucre-alt mb-3">Görüşleriniz hizmet kalitemizi doğrudan şekillendirir.</div>
        <div class="dikey" style="gap:6px">
            <?php foreach (array_slice($completed_items, 0, 10) as $tg): ?>
            <div class="satir-esnek arasi" style="padding:9px 12px;background:var(--surface-2);border-radius:10px;gap:10px">
                <span class="kucuk kalin" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($tg['title']) ?></span>
                <?php if (isset($gorevPuanlari[$tg['id']])): ?>
                <button class="btn btn-sm" onclick="ratingGive('gorev', <?= $tg['id'] ?>, '<?= e($tg['title']) ?>')" title="Puanı güncelle"><?= stars((float)$gorevPuanlari[$tg['id']], 13) ?></button>
                <?php else: ?>
                <button class="btn btn-marka btn-sm" onclick="ratingGive('gorev', <?= $tg['id'] ?>, '<?= e($tg['title']) ?>')" style="flex-shrink:0"><?= icon('star', 13) ?> Değerlendir</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; endif; ?>

    <!-- TASKS -->
    <div class="sekme-icerik" id="sekme-gorevler">
        <div class="satir-esnek arasi mb-2">
            <div class="metin-muted kucuk">Görevleri sürükleyerek durumlarını değiştirebilirsiniz</div>
            <a href="tasks.php?project=<?= $id ?>" class="btn btn-sm">Tam Kanban Görünümü →</a>
        </div>
        <?php task_kanban($tasks, $id); ?>
    </div>

    <?php if ($project['type'] === 'aylik'): ?>
    <!-- PERIODS -->
    <div class="sekme-icerik" id="sekme-donemler">
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik">Aylık Dönemler</div>
            <?php if (is_staff()): ?><button class="btn btn-marka btn-sm" data-modal="modalPeriod"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Dönem Aç</button><?php endif; ?>
        </div>
        <?php if (!$periods): ?>
        <div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div><div class="bos-baslik">Henüz dönem açılmamış</div><div class="bos-metin">Aylık düzenli hizmet için ilk dönemi açın; şablondan görevler otomatik oluşturulabilir.</div></div>
        <?php else: ?>
        <div class="izgara izgara-3">
            <?php foreach ($periods as $d): ?>
            <a href="tasks.php?project=<?= $id ?>&period=<?= $d['id'] ?>" class="kart kart-tik">
                <div class="satir-esnek arasi mb-2"><div class="kart-baslik" style="font-size:15px"><?= period_name($d) ?></div><?= badge($d['status'], ['acik' => 'Açık', 'kapali' => 'Kapalı']) ?></div>
                <div class="hucre-alt"><?= $d['task_count'] ?> görev</div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- APPROVALS -->
    <div class="sekme-icerik" id="sekme-onaylar">
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik">Onay Süreçleri</div>
            <?php if (is_staff()): ?><button class="btn btn-marka btn-sm" data-modal="modalApproval"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Onaya Gönder</button><?php endif; ?>
        </div>
        <?php if (!$approvals): ?>
        <div class="metin-muted kucuk orta kart" style="padding:30px">Henüz onay süreci başlatılmamış.</div>
        <?php else: foreach ($approvals as $o): ?>
        <div class="kart mb-2">
            <div class="satir-esnek arasi">
                <div style="min-width:0">
                    <div class="satir-esnek" style="gap:9px"><span class="kalin"><?= e($o['title']) ?></span><?= badge($o['status'], APPROVAL_STATUSES) ?></div>
                    <?php if ($o['description']): ?><div class="hucre-alt mt-1"><?= e($o['description']) ?></div><?php endif; ?>
                    <div class="hucre-alt mt-1"><?= e($o['sender_name']) ?> · <?= time_ago($o['created']) ?><?php if ($o['archive_id']): $ar = row("SELECT * FROM archive WHERE id=?", [$o['archive_id']]); if ($ar): ?> · <a href="uploads/<?= e($ar['file_path']) ?>" target="_blank" style="color:var(--marka)"><?= icon('atac', 12) ?> <?= e($ar['name']) ?></a><?php endif; endif; ?></div>
                    <?php if ($o['reply_note']): ?><div class="mt-2" style="padding:10px 14px;background:var(--surface-2);border-radius:10px;font-size:13px"><b>Müşteri notu:</b> <?= nl2br(e($o['reply_note'])) ?></div><?php endif; ?>
                </div>
                <?php if ($o['status'] === 'bekliyor' && (is_customer() || is_admin())): ?>
                <div class="satir-esnek" style="gap:6px;flex-shrink:0">
                    <button class="btn btn-sm" style="color:var(--basari)" data-action="approval_reply" data-id="<?= $o['id'] ?>" data-status="onaylandi">Onayla</button>
                    <button class="btn btn-sm" onclick="approvalNot(<?= $o['id'] ?>,'revize')">Revize</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- CONTENTS -->
    <div class="sekme-icerik" id="sekme-icerik">
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik">İçerikler</div>
            <a href="content-calendar.php?project=<?= $id ?>" class="btn btn-sm">Takvim Görünümü →</a>
        </div>
        <?php if (!$contents): ?>
        <div class="metin-muted kucuk orta kart" style="padding:30px">Bu proje için içerik planlanmamış.</div>
        <?php else: ?>
        <div class="tablo-sar"><table class="tablo"><thead><tr><th>İçerik</th><th>Platform</th><th>Tarih</th><th>Durum</th></tr></thead><tbody>
            <?php foreach ($contents as $internal): ?>
            <tr><td class="hucre-ana"><?= e($internal['title']) ?></td><td><?= platform_badges($internal['platform']) ?></td><td><?= format_date($internal['date']) ?></td><td><?= badge($internal['status'], CONTENT_STATUSES) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>

    <!-- DISCUSSION -->
    <?php if (is_staff()): ?>
    <!-- STATION: SOP project brief + budget + technical checks + review -->
    <div class="sekme-icerik" id="sekme-istasyon">
        <div class="izgara izgara-2" style="align-items:start">
            <div class="dikey" style="gap:16px">
                <!-- Project brief -->
                <div class="kart">
                    <div class="kart-baslik mb-2">Proje Künyesi</div>
                    <form data-ajax="station_save" data-refresh="hayir">
                        <input type="hidden" name="project_id" value="<?= $id ?>">
                        <input type="hidden" name="team_roles" id="st_roles">
                        <div class="dikey kucuk mb-2" style="gap:7px">
                            <div class="satir-esnek arasi"><span class="metin-muted">Müşteri / Marka</span><b><?= e($project['client_name']) ?></b></div>
                            <div class="satir-esnek arasi"><span class="metin-muted">Proje Tipi</span><b><?= PROJECT_TYPES[$project['type']] ?? $project['type'] ?></b></div>
                            <div class="satir-esnek arasi"><span class="metin-muted">Tarih Aralığı</span><b><?= $project['start'] ? format_date($project['start']) : '—' ?> → <?= $project['end'] ? format_date($project['end']) : '—' ?></b></div>
                        </div>
                        <div class="form-grup"><label class="form-etiket">Devralma Noktası</label><input name="handover" class="girdi" value="<?= e($project['handover'] ?? '') ?>" placeholder="Örn. Etkinliğe 2 hafta kala devralındı / Sıfırdan lansman" <?= permission('dosya_yonet') ? '' : 'disabled' ?>></div>
                        <div class="form-grup">
                            <label class="form-etiket">Ekip ve Rol Dağılımı</label>
                            <div class="dikey" id="stRoleList" style="gap:7px"></div>
                            <?php if (permission('dosya_yonet')): ?><button type="button" class="btn btn-sm btn-hayalet mt-1" onclick="stRoleAdd()">+ Rol Ekle</button><?php endif; ?>
                        </div>
                        <?php if ($budgetGor): ?>
                        <div class="form-satir">
                            <div class="form-grup"><label class="form-etiket">Onaylı Ana Bütçe (₺)</label><input name="budget" class="girdi" value="<?= $project['budget'] > 0 ? number_format((float)$project['budget'], 2, '.', '') : '' ?>" placeholder="0.00"></div>
                            <div class="form-grup"><label class="form-etiket">Revize Hakkı</label><input name="revision_limit" type="number" class="girdi" value="<?= (int)($project['revision_limit'] ?? 2) ?>" min="0" max="20"></div>
                        </div>
                        <?php endif; ?>
                        <?php if (permission('dosya_yonet')): ?><button type="submit" class="btn btn-marka">Künyeyi Kaydet</button><?php endif; ?>
                    </form>
                </div>

                <?php if ($budgetGor): ?>
                <!-- Budget & Extra Requests (only for those with budget permission) -->
                <div class="kart" style="border-color:var(--uyari)">
                    <div class="satir-esnek arasi mb-2">
                        <div class="kart-baslik">Bütçe & Ek Talepler <span class="rozet r-bekliyor" style="padding:1px 8px">Kısıtlı görünüm</span></div>
                        <button class="btn btn-sm" data-modal="modalEkRequest">+ Ek Talep</button>
                    </div>
                    <div class="dikey kucuk mb-2" style="gap:6px">
                        <div class="satir-esnek arasi"><span class="metin-muted">Onaylı ana bütçe</span><b><?= number_format((float)$project['budget'], 2, ',', '.') ?> ₺</b></div>
                        <div class="satir-esnek arasi"><span class="metin-muted">Onaylanan ek talepler</span><b>+<?= number_format(array_sum(array_map(fn($t) => $t['status'] === 'onaylandi' ? (float)$t['amount'] : 0, $ekRequests)), 2, ',', '.') ?> ₺</b></div>
                        <div class="satir-esnek arasi"><span class="metin-muted">Kullanılan revize</span><b style="color:<?= $revisionCount > (int)$project['revision_limit'] ? 'var(--tehlike)' : 'inherit' ?>"><?= $revisionCount ?> / <?= (int)$project['revision_limit'] ?></b></div>
                    </div>
                    <?php if ($revisionCount > (int)$project['revision_limit']): ?><div class="kucuk mb-2" style="color:var(--tehlike)">⚠️ Revize limiti aşıldı — SOP gereği sonraki majör revizeler ek bütçeye tabidir.</div><?php endif; ?>
                    <?php if ($ekRequests): ?>
                    <div class="dikey" style="gap:6px">
                        <?php foreach ($ekRequests as $t): ?>
                        <div class="satir-esnek arasi kucuk" style="padding:9px 11px;background:var(--surface-2);border-radius:9px;gap:8px">
                            <div style="min-width:0"><b><?= e($t['title']) ?></b><?= $t['out_of_scope'] ? ' <span class="rozet rozet-tur" style="padding:0 7px">Kapsam dışı</span>' : '' ?><div class="hucre-alt"><?= e($t['creator_name']) ?> · <?= format_date($t['created']) ?><?= $t['description'] ? ' — ' . e($t['description']) : '' ?></div></div>
                            <div class="satir-esnek" style="gap:7px;flex-shrink:0">
                                <b><?= number_format((float)$t['amount'], 2, ',', '.') ?> ₺</b>
                                <?php if ($t['status'] === 'bekliyor'): ?>
                                <button class="mini-btn" style="color:var(--basari)" onclick="extraRequestStatus(<?= $t['id'] ?>, 'onaylandi', this)">Onayla</button>
                                <button class="mini-btn" style="color:var(--tehlike)" onclick="extraRequestStatus(<?= $t['id'] ?>, 'reddedildi', this)">Reddet</button>
                                <?php else: ?><?= badge($t['status'], ['onaylandi' => 'Onaylandı', 'reddedildi' => 'Reddedildi', 'bekliyor' => 'Bekliyor']) ?><?php endif; ?>
                                <button class="ikon-eylem tehlike" style="width:24px;height:24px" data-action="extra_request_delete" data-id="<?= $t['id'] ?>" data-approval="Ek talep silinsin mi?"><?= icon('cop', 12) ?></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?><div class="metin-muted kucuk">Süreç içi ek talep kaydı yok. Müşteriden gelen kapsam dışı istekleri buraya işleyin.</div><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="dikey" style="gap:16px">
                <!-- Technical Checklist -->
                <div class="kart">
                    <div class="satir-esnek arasi mb-2">
                        <div class="kart-baslik">Saha & Teknik Kontrol</div>
                        <div class="satir-esnek" style="gap:6px">
                            <?php if (!$checkList): ?><button class="mini-btn" data-action="pcheck_standard" data-project_id="<?= $id ?>">SOP standart listesini yükle</button><?php endif; ?>
                        </div>
                    </div>
                    <div class="dikey" style="gap:6px" id="pkList">
                        <?php foreach ($checkList as $k): ?>
                        <div class="satir-esnek kucuk" style="padding:9px 11px;background:var(--surface-2);border-radius:9px;gap:9px">
                            <input type="checkbox" <?= $k['is_done'] ? 'checked' : '' ?> onchange="pkToggle(<?= $k['id'] ?>, 'tamam')" title="Kontrol tamam">
                            <div style="flex:1;min-width:0">
                                <b <?= $k['is_done'] ? 'style="text-decoration:line-through;opacity:.6"' : '' ?>><?= e($k['item']) ?></b>
                                <?php if ($k['check_note']): ?><div class="hucre-alt"><?= e($k['check_note']) ?></div><?php endif; ?>
                            </div>
                            <button class="mini-btn" onclick="pkOwner(<?= $k['id'] ?>)" title="Sorumlu ata"><?= $k['owner_name'] ? e(explode(' ', $k['owner_name'])[0]) : '+ sorumlu' ?></button>
                            <button class="mini-btn <?= $k['is_delivered'] ? '' : '' ?>" style="color:<?= $k['is_delivered'] ? 'var(--basari)' : 'var(--muted)' ?>" onclick="pkToggle(<?= $k['id'] ?>, 'teslim', this)" title="Ekipman teslim edildi mi?"><?= $k['is_delivered'] ? '✓ Teslim' : 'Teslim?' ?></button>
                            <button class="ikon-eylem tehlike" style="width:24px;height:24px" onclick="pkDelete(<?= $k['id'] ?>, this)"><?= icon('cop', 12) ?></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <form class="satir-esnek mt-2" style="gap:8px" onsubmit="pkAdd(event)">
                        <input class="girdi" id="pkNew" placeholder="Yeni kontrol kalemi (ekipman, izin, hazırlık...)" style="flex:1">
                        <button class="btn btn-sm" type="submit">Ekle</button>
                    </form>
                </div>

                <!-- Review (Post-Mortem) -->
                <div class="kart">
                    <div class="kart-baslik mb-2">Değerlendirme (Post-Mortem)</div>
                    <?php $reviewTypes = ['ic' => ['İç Değerlendirme (Ekip)', 'Neleri iyi yaptık? Nerede zaman/bütçe/enerji kaybı yaşandı? Bir sonraki projede neyi farklı yapmalıyız? Yaşanan aksilikler...'], 'dis' => ['Dış Değerlendirme (Kurum)', 'Müşteri memnuniyeti, alınan olumlu/olumsuz geri bildirimler...'], 'case_study' => ['Web Sitesi Case Study İçeriği', 'Projenin amacı, erişim/etkileşim metrikleri, öne çıkan görsel ve videolar...']];
                    foreach ($reviewTypes as $dtCode => [$dtTitle, $dtIpucu]): $dg = $reviews[$dtCode] ?? null; ?>
                    <div class="form-grup">
                        <label class="form-etiket"><?= $dtTitle ?><?php if ($dg): ?> <span class="metin-muted" style="font-weight:400">· <?= format_date($dg['updated'], true) ?></span><?php endif; ?></label>
                        <textarea class="metin-alani" rows="3" id="deg_<?= $dtCode ?>" placeholder="<?= e($dtIpucu) ?>"><?= e($dg['content'] ?? '') ?></textarea>
                        <button class="mini-btn mt-1" onclick="reviewSave('<?= $dtCode ?>')">Kaydet</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="sekme-icerik" id="sekme-tartisma">
        <div class="kart">
            <div class="kart-baslik mb-2">Proje Tartışması</div>
            <div class="hucre-alt mb-3">Ekip ve müşteri bu alanda proje hakkında konuşabilir. @ yazarak birini etiketleyin.</div>
            <?php comment_feed('proje', $id); ?>
        </div>
    </div>

    <!-- ARCHIVE -->
    <div class="sekme-icerik" id="sekme-arsiv">
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik">Dosya Arşivi</div>
            <button class="btn btn-marka btn-sm" data-modal="modalUpload"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Dosya Yükle</button>
        </div>
        <?php if (!$archives): ?>
        <div class="metin-muted kucuk orta kart" style="padding:30px">Arşivde dosya yok.</div>
        <?php else: ?>
        <div class="izgara izgara-auto">
            <?php foreach ($archives as $a): ?>
            <div class="kart" style="padding:14px">
                <div class="satir-esnek arasi">
                    <a href="uploads/<?= e($a['file_path']) ?>" target="_blank" class="satir-esnek" style="gap:10px;min-width:0">
                        <div class="dosya-avatar" style="width:36px;height:36px;font-size:11px;background:var(--parlak);color:var(--marka)"><?= e(mb_strtoupper($a['extension'] ?: '?')) ?></div>
                        <div style="min-width:0"><div class="hucre-ana kucuk" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($a['name']) ?></div><div class="hucre-alt"><?= format_size($a['size']) ?> · <?= time_ago($a['created']) ?></div></div>
                    </a>
                    <?php if (is_staff()): ?><button class="ikon-eylem tehlike" data-action="arsiv_sil" data-id="<?= $a['id'] ?>" data-approval="Silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/></svg></button><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ACTIVITY -->
    <div class="sekme-icerik" id="sekme-aktivite">
        <div class="kart">
            <div class="kart-baslik mb-3">Proje Geçmişi</div>
            <?php if (!$activities): ?><div class="metin-muted kucuk">Henüz aktivite yok.</div>
            <?php else: ?><div class="zaman-tunel"><?php foreach ($activities as $a): ?>
            <div class="tunel-oge"><div class="tunel-metin"><b><?= e($a['name']) ?></b> <?= e($a['description']) ?></div><div class="tunel-zaman"><?= format_date($a['created'], true) ?></div></div>
            <?php endforeach; ?></div><?php endif; ?>
        </div>
    </div>
</div>

<?php
// ---- Modals ----
if (is_staff()) task_modal($id, $team, $templates, $periods);
?>

<!-- Send-for-approval modal -->
<div class="modal-katman" id="modalApproval">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Müşteri Onayına Gönder</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="onay_gonder" data-refresh="evet">
        <input type="hidden" name="project_id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="title" class="girdi" required placeholder="Örn. Ekim ayı 1. gönderi tasarımı"></div>
            <div class="form-grup"><label class="form-etiket">Açıklama / Not</label><textarea name="description" class="metin-alani" placeholder="Müşteriye iletmek istedikleriniz..."></textarea></div>
            <div class="form-grup"><label class="form-etiket">Dosya Eki</label><input type="file" name="client" class="girdi"><div class="form-ipucu">Görsel, PDF, video vb. (max 50MB)</div></div>
            <div class="form-grup"><label class="form-etiket">veya Drive Linki</label><input name="drive_link" class="girdi" placeholder="https://drive.google.com/..."></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Onaya Gönder</button></div>
    </form></div>
</div>

<!-- File upload modal -->
<div class="modal-katman" id="modalUpload">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Dosya Yükle</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="archive_upload" data-refresh="evet">
        <input type="hidden" name="project_id" value="<?= $id ?>">
        <div class="modal-govde"><div class="form-grup"><label class="form-etiket">Dosya Seç <span class="zorunlu">*</span></label><input type="file" name="client" class="girdi" required></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Yükle</button></div>
    </form></div>
</div>

<?php if ($project['type'] === 'aylik' && is_staff()): ?>
<!-- Open-period modal -->
<div class="modal-katman" id="modalPeriod">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Dönem Aç</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="period_open" data-refresh="evet">
        <input type="hidden" name="project_id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Ay</label><select name="month" class="secim"><?php foreach (MONTHS as $k => $v): ?><option value="<?= $k ?>" <?= $k == date('n') ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Yıl</label><select name="year" class="secim"><?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?><option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Akış Şablonundan Görev Oluştur</label><select name="template_id" class="secim"><option value="">Boş dönem</option><?php foreach ($templates as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilen şablonun adımları görev akışı olarak eklenir.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Dönemi Aç</button></div>
    </form></div>
</div>
<?php endif; ?>

<?php if (permission('dosya_yonet')):
$clients = rows("SELECT id, name FROM clients WHERE status='aktif' ORDER BY name");
$pmler = rows("SELECT id, name FROM users WHERE role IN ('yonetici','pm') AND is_active=1 ORDER BY name"); ?>
<!-- Edit project -->
<div class="modal-katman" id="modalProjectDuzen">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Projeyi Düzenle</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="project_save">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Proje Adı</label><input name="name" class="girdi" value="<?= e($project['name']) ?>" required></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Dosya</label><select name="client_id" class="secim"><?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $d['id'] == $project['client_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Tür</label><select name="type" class="secim"><?php foreach (PROJECT_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $project['type'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Durum</label><select name="status" class="secim"><?php foreach (PROJECT_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $project['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">PM</label><select name="pm_id" class="secim"><option value="">—</option><?php foreach ($pmler as $pm): ?><option value="<?= $pm['id'] ?>" <?= $pm['id'] == $project['pm_id'] ? 'selected' : '' ?>><?= e($pm['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Başlangıç</label><input type="date" name="start" class="girdi" value="<?= e($project['start']) ?>"></div>
                <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="date" name="end" class="girdi" value="<?= e($project['end']) ?>"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Sözleşme Tutarı (₺)</label><input name="contract_amount" class="girdi" value="<?= $project['contract_amount'] ?>"></div>
            <?php member_picker(array_column($projectMembers, 'id')); ?>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="description" class="metin-alani"><?= e($project['description']) ?></textarea></div>
        </div>
        <div class="modal-alt">
            <?php if (is_admin()): ?><button type="button" class="btn btn-tehlike" data-action="project_delete" data-id="<?= $id ?>" data-approval="Proje ve tüm görevleri silinecek. Emin misiniz?" style="margin-right:auto">Sil</button><?php endif; ?>
            <button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button>
        </div>
    </form></div>
</div>
<?php endif; ?>

<?php rating_modal(); ?>

<!-- Approval note (revision) modal -->
<div class="modal-katman" id="modalApprovalNot">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Revize / Not Ekle</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="approval_reply">
        <input type="hidden" name="id" id="approvalNotId"><input type="hidden" name="status" id="approvalNotStatus">
        <div class="modal-govde"><div class="form-grup"><label class="form-etiket">Notunuz</label><textarea name="not" class="metin-alani" required placeholder="Değişiklik taleplerinizi yazın..."></textarea></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Gönder</button></div>
    </form></div>
</div>
<script>
function approvalNot(id, status) { document.getElementById('approvalNotId').value = id; document.getElementById('approvalNotStatus').value = status; modalOpen('modalApprovalNot'); }
</script>

<?php if (is_staff()): ?>
<!-- Extra request modal (budget permission) -->
<?php if ($budgetGor): ?>
<div class="modal-katman" id="modalEkRequest">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Ek Talep Kaydet</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="extra_request_save">
        <input type="hidden" name="project_id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Talep <span class="zorunlu">*</span></label><input name="title" class="girdi" required placeholder="Örn. Ek drone çekimi istendi"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tahmini Tutar (₺)</label><input name="amount" class="girdi" placeholder="0.00"></div>
                <div class="form-grup" style="display:flex;align-items:flex-end"><label class="satir-esnek" style="gap:8px;cursor:pointer;padding-bottom:10px"><input type="checkbox" name="out_of_scope" value="1" checked> Kapsam dışı (ek bütçe)</label></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="description" class="metin-alani" placeholder="Talebin detayı, kim istedi..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
/* ---- Station: team role distribution editor ---- */
const stTeam = <?= json_encode(array_map(fn($e2) => ['id' => $e2['id'], 'name' => $e2['name']], $team), JSON_UNESCAPED_UNICODE) ?>;
const stRoles = <?= json_encode($teamRoles, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
const istDuzenlenebilir = <?= permission('dosya_yonet') ? 'true' : 'false' ?>;
function stRoleAdd(role = '', person = '') {
    const list = document.getElementById('stRoleList');
    if (!list) return;
    const div = document.createElement('div');
    div.className = 'satir-esnek ist-rol';
    div.style.gap = '7px';
    let ops = '<option value="">Kişi seçin</option>';
    stTeam.forEach(k => ops += `<option value="${k.name.replace(/"/g, '&quot;')}" ${k.name === person ? 'selected' : ''}>${k.name}</option>`);
    div.innerHTML = `<input class="girdi ist-rol-ad" placeholder="Rol (örn. Art Director)" value="${role.replace(/"/g, '&quot;')}" style="flex:1" ${istDuzenlenebilir ? '' : 'disabled'}>
        <select class="secim native-kal ist-rol-kisi" style="flex:1" ${istDuzenlenebilir ? '' : 'disabled'}>${ops}</select>
        ${istDuzenlenebilir ? '<button type="button" class="ikon-eylem tehlike" onclick="this.parentElement.remove();stRoleWrite()">✕</button>' : ''}`;
    list.appendChild(div);
}
function stRoleWrite() {
    const roles = Array.from(document.querySelectorAll('.ist-rol')).map(s => ({
        role: s.querySelector('.ist-rol-ad').value.trim(),
        person: s.querySelector('.ist-rol-kisi').value,
    })).filter(r => r.role || r.person);
    const gizli = document.getElementById('st_roles');
    if (gizli) gizli.value = JSON.stringify(roles);
}
(stRoles.length ? stRoles : (istDuzenlenebilir ? [{role: '', person: ''}] : [])).forEach(r => stRoleAdd(r.role || '', r.person || ''));
stRoleWrite();
document.getElementById('stRoleList')?.closest('form')?.addEventListener('submit', stRoleWrite);
document.getElementById('stRoleList')?.addEventListener('input', stRoleWrite);
document.getElementById('stRoleList')?.addEventListener('change', stRoleWrite);

/* ---- Station: checklist ---- */
async function pkAdd(e) {
    e.preventDefault();
    const girdi = document.getElementById('pkNew');
    if (!girdi.value.trim()) return;
    const j = await api('pcheck_add', { project_id: <?= $id ?>, item: girdi.value.trim() });
    if (j.ok) location.reload();
}
async function pkToggle(id, field, btn) {
    const j = await api('pcheck_toggle', { id, field });
    if (j.ok && field === 'tamam') location.reload();
    if (j.ok && btn) { const open = btn.textContent.includes('?'); btn.textContent = open ? '✓ Teslim' : 'Teslim?'; btn.style.color = open ? 'var(--basari)' : 'var(--muted)'; }
}
async function pkDelete(id, btn) {
    if (!confirm('Kontrol kalemi silinsin mi?')) return;
    const j = await api('pcheck_delete', { id });
    if (j.ok) btn.closest('.satir-esnek').remove();
}
async function pkOwner(id) {
    let option = 'Sorumlu seçin:\n0 — Sorumsuz bırak\n';
    stTeam.forEach((k, i) => option += (i + 1) + ' — ' + k.name + '\n');
    const sec = prompt(option);
    if (sec === null) return;
    const idx = parseInt(sec);
    const j = await api('pcheck_owner', { id, owner_id: idx > 0 && stTeam[idx - 1] ? stTeam[idx - 1].id : 0 });
    if (j.ok) location.reload();
}

/* ---- Station: review + extra request ---- */
async function reviewSave(type) {
    const j = await api('review_save', { project_id: <?= $id ?>, type, content: document.getElementById('review_' + type).value });
    if (j.ok) toast(j.message, 'basari');
}
async function extraRequestStatus(id, status, btn) {
    const j = await api('extra_request_status', { id, status });
    if (j.ok) location.reload();
}
</script>
<?php endif; ?>

<?php
page_end();
