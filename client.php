<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
$client = row("SELECT * FROM clients WHERE id=?", [$id]);
if (!$client || !client_access($id)) { header('Location: clients.php'); exit; }
$customerView = is_customer();
$clientMembers = rows("SELECT u.id, u.name, u.color, u.avatar, u.job_title FROM client_members du JOIN users u ON u.id=du.user_id WHERE du.client_id=? AND u.is_active=1 ORDER BY u.name", [$id]);

$projects = rows("SELECT p.*, u.name pm_name,
    (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id) task_count,
    (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id AND g.status='completed') is_done_count
    FROM projects p LEFT JOIN users u ON u.id=p.pm_id WHERE p.client_id=? ORDER BY p.created DESC", [$id]);
$customers = rows("SELECT * FROM users WHERE client_id=? AND role='customer'", [$id]);
$archiveCount = (int)val("SELECT COUNT(*) FROM archive WHERE client_id=?", [$id]);
$contracts = rows("SELECT s.*, a.file_path, a.name ek_name FROM contracts s LEFT JOIN archive a ON a.id=s.archive_id WHERE s.client_id=? ORDER BY s.end IS NULL, s.end", [$id]);

// Social media accounts + metric history
$socialAccounts = rows("SELECT * FROM social_accounts WHERE client_id=? ORDER BY platform, username", [$id]);
foreach ($socialAccounts as &$sh) {
    $sh['metrics'] = rows("SELECT * FROM social_metrics WHERE account_id=? ORDER BY date DESC LIMIT 10", [$sh['id']]);
}
unset($sh);

page_start($client['name'], 'clients');
?>
<div class="row-flex mb-3" style="gap:10px">
    <a href="clients.php" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="text-muted small">Dosyalar / <?= e($client['name']) ?></span>
</div>

<div class="page-top">
    <div class="row-flex" style="gap:16px">
        <?= client_logo($client, 56, 22) ?>
        <div>
            <div class="page-title" style="font-size:24px"><?= e($client['name']) ?></div>
            <div class="row-flex mt-1" style="gap:8px">
                <span class="badge badge-type"><?= CLIENT_TYPES[$client['type']] ?></span>
                <?= badge($client['status'], CLIENT_STATUSES) ?>
            </div>
        </div>
    </div>
    <?php if (permission('client_manage')): ?>
    <div class="page-top-action">
        <button class="btn btn-brand" data-modal="modalProject"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Proje Ekle</button>
        <button class="btn" onclick="clientEdit()"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg> Düzenle</button>
    </div>
    <?php endif; ?>
</div>

<div class="grid" style="grid-template-columns:1fr 320px" id="clientEditBox">
    <div>
        <!-- Projects -->
        <div class="row-flex between mb-2"><div class="card-title">Projeler (<?= count($projects) ?>)</div></div>
        <?php if (!$projects): ?>
        <div class="card orta text-muted small" style="padding:30px">Bu dosyada henüz proje yok.</div>
        <?php else: foreach (PROJECT_TYPES as $typeKey => $typeLabel):
            $group = array_filter($projects, fn($p) => $p['type'] === $typeKey);
            if (!$group) continue; ?>
        <div class="nav-section" style="padding:14px 0 8px"><?= $typeLabel ?> Hizmetler</div>
        <div class="grid grid-2">
            <?php foreach ($group as $p):
                $rate = $p['task_count'] ? round($p['is_done_count'] / $p['task_count'] * 100) : 0; ?>
            <a href="project.php?id=<?= $p['id'] ?>" class="card card-tick" style="padding:16px">
                <div class="row-flex between mb-2">
                    <div class="card-title" style="font-size:15px"><?= e($p['name']) ?></div>
                    <?= badge($p['status'], PROJECT_STATUSES) ?>
                </div>
                <?php if ($p['pm_name']): ?><div class="cell-bottom">PM: <?= e($p['pm_name']) ?></div><?php endif; ?>
                <div class="progress mt-2"><div class="progress-full" data-rate="<?= $rate ?>" style="width:0"></div></div>
                <div class="cell-bottom mt-1"><?= $p['is_done_count'] ?>/<?= $p['task_count'] ?> görev · %<?= $rate ?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; endif; ?>

        <!-- Social media tracking -->
        <div class="row-flex between mb-2 mt-3">
            <div class="card-title"><?= icon('chart', 16) ?> Sosyal Medya (<?= count($socialAccounts) ?>)</div>
            <?php if (permission('content_manage')): ?><button class="btn btn-sm btn-brand" data-modal="modalSocialAccount"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Hesap Ekle</button><?php endif; ?>
        </div>
        <?php if (!$socialAccounts): ?>
        <div class="card orta text-muted small" style="padding:24px">Bu dosya için sosyal medya hesabı eklenmemiş.<?= permission('content_manage') ? ' Hesap ekleyip takipçi verilerini düzenli girerek büyümeyi izleyin.' : '' ?></div>
        <?php else: ?>
        <div class="grid grid-2">
            <?php foreach ($socialAccounts as $sh):
                $last = $sh['metrics'][0] ?? null;
                $previous = $sh['metrics'][1] ?? null;
                $diff = ($last && $previous) ? (int)$last['followers'] - (int)$previous['followers'] : null;
                $maxFollowers = $sh['metrics'] ? max(array_column($sh['metrics'], 'followers')) : 1; ?>
            <div class="card" style="padding:16px">
                <div class="row-flex between">
                    <div class="row-flex" style="gap:10px;min-width:0">
                        <span class="file-avatar" style="width:40px;height:40px;background:var(--bright);color:var(--brand)"><?= icon(isset(ICONS[$sh['platform']]) ? $sh['platform'] : 'other', 20) ?></span>
                        <div style="min-width:0">
                            <div class="bold small"><?php if ($sh['url']): ?><a href="<?= e($sh['url']) ?>" target="_blank" style="color:var(--brand)">@<?= e(ltrim($sh['username'], '@')) ?></a><?php else: ?>@<?= e(ltrim($sh['username'], '@')) ?><?php endif; ?></div>
                            <div class="cell-bottom"><?= PLATFORMS[$sh['platform']] ?? $sh['platform'] ?></div>
                        </div>
                    </div>
                    <?php if (permission('content_manage')): ?>
                    <button class="icon-action danger" style="width:26px;height:26px" data-action="social_account_delete" data-id="<?= $sh['id'] ?>" data-confirm="Hesap ve tüm metrik geçmişi silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                    <?php endif; ?>
                </div>
                <div class="row-flex mt-2" style="gap:14px;align-items:baseline">
                    <span class="stat-value" style="font-size:26px"><?= $last ? number_format((int)$last['followers'], 0, ',', '.') : '—' ?></span>
                    <span class="cell-bottom">takipçi</span>
                    <?php if ($diff !== null): ?>
                    <span class="small bold" style="color:<?= $diff >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= $diff >= 0 ? '▲ +' : '▼ ' ?><?= number_format($diff, 0, ',', '.') ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($last && ($last['post'] !== null || $last['engagement'] !== null)): ?>
                <div class="cell-bottom mt-1">
                    <?= $last['post'] !== null ? $last['post'] . ' gönderi' : '' ?><?= $last['post'] !== null && $last['engagement'] !== null ? ' · ' : '' ?><?= $last['engagement'] !== null ? number_format((int)$last['engagement'], 0, ',', '.') . ' etkileşim' : '' ?>
                </div>
                <?php endif; ?>
                <?php if (count($sh['metrics']) > 1): ?>
                <!-- Mini history chart (old→new) -->
                <div style="display:flex;gap:3px;align-items:flex-end;height:36px;margin-top:10px" title="Son <?= count($sh['metrics']) ?> kayıt">
                    <?php foreach (array_reverse($sh['metrics']) as $m): ?>
                    <div style="flex:1;background:var(--brand);opacity:.75;border-radius:3px 3px 0 0;height:<?= max(8, round((int)$m['followers'] / max(1, $maxFollowers) * 100)) ?>%" title="<?= format_date($m['date']) ?>: <?= number_format((int)$m['followers'], 0, ',', '.') ?>"></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="row-flex between mt-2">
                    <span class="cell-bottom"><?= $last ? 'Son veri: ' . format_date($last['date']) : 'Henüz veri girilmedi' ?></span>
                    <?php if (is_staff()): ?><button class="mini-btn" onclick="metricEnter(<?= $sh['id'] ?>, '<?= e($sh['username']) ?>')">+ Veri Gir</button><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$customerView):
            $people = rows("SELECT * FROM client_contacts WHERE client_id=? ORDER BY name", [$id]); ?>
        <!-- Contact people (team only) -->
        <div class="row-flex between mb-2 mt-3">
            <div class="card-title"><?= icon('team', 16) ?> İletişim Kişileri (<?= count($people) ?>)</div>
            <?php if (permission('client_manage')): ?><button class="btn btn-sm btn-brand" onclick="personNew()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14"><path d="M12 5v14M5 12h14"/></svg> Kişi Ekle</button><?php endif; ?>
        </div>
        <?php if (!$people): ?>
        <div class="card orta text-muted small" style="padding:18px">Bu dosyada iletişim kurulacak kişileri ekleyin — rapor maili gönderirken buradan seçilirler.</div>
        <?php else: ?>
        <div class="grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px">
            <?php foreach ($people as $ki): ?>
            <div class="card" style="padding:13px 15px">
                <div class="row-flex between">
                    <div class="bold small"><?= e($ki['name']) ?></div>
                    <?php if (permission('client_manage')): ?>
                    <div class="row-flex" style="gap:2px">
                        <button class="icon-action" style="width:24px;height:24px" onclick='personEdit(<?= json_encode($ki, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 12) ?></button>
                        <button class="icon-action danger" style="width:24px;height:24px" data-action="client_contact_delete" data-id="<?= $ki['id'] ?>" data-confirm="Kişi silinsin mi?"><?= icon('cop', 12) ?></button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($ki['title']): ?><div class="cell-bottom mt-1"><?= e($ki['title']) ?></div><?php endif; ?>
                <?php if ($ki['email']): ?><div class="small mt-1"><a href="mailto:<?= e($ki['email']) ?>" style="color:var(--brand)"><?= e($ki['email']) ?></a></div><?php endif; ?>
                <?php if ($ki['phone']): ?><div class="small text-muted mt-1"><?= e($ki['phone']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="modal-overlay" id="modalPerson">
            <div class="modal"><div class="modal-top"><div class="modal-title" id="personTitle">İletişim Kişisi</div><button class="modal-close" data-modal-close>✕</button></div>
            <form data-ajax="client_contact_save">
                <input type="hidden" name="id" id="ki_id"><input type="hidden" name="client_id" value="<?= $id ?>">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Ad Soyad <span class="required">*</span></label><input name="name" id="ki_name" class="input" required></div>
                        <div class="form-group"><label class="form-label">Ünvan / Rol</label><input name="title" id="ki_title" class="input" placeholder="Örn. Pazarlama Müdürü"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">E-posta</label><input type="email" name="email" id="ki_email" class="input" autocomplete="off"></div>
                        <div class="form-group"><label class="form-label">Telefon</label><input name="phone" id="ki_phone" class="input"></div>
                    </div>
                </div>
                <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
            </form></div>
        </div>
        <script>
        function personNew() { ['ki_id','ki_name','ki_title','ki_email','ki_phone'].forEach(i => document.getElementById(i).value = ''); document.getElementById('personTitle').textContent = 'Yeni İletişim Kişisi'; modalOpen('modalPerson'); }
        function personEdit(k) { document.getElementById('ki_id').value = k.id; document.getElementById('ki_name').value = k.name; document.getElementById('ki_title').value = k.title || ''; document.getElementById('ki_email').value = k.email || ''; document.getElementById('ki_phone').value = k.phone || ''; document.getElementById('personTitle').textContent = 'Kişiyi Düzenle'; modalOpen('modalPerson'); }
        </script>
        <?php
            $infoNotes = rows("SELECT bn.*, us.name updater_name FROM client_notes bn LEFT JOIN users us ON us.id=bn.updated_by WHERE bn.client_id=? ORDER BY bn.pinned DESC, bn.sort_order", [$id]); ?>
        <!-- Knowledge base (team only) -->
        <div class="row-flex between mb-2 mt-3">
            <div class="card-title"><?= icon('document', 16) ?> Bilgi Bankası (<?= count($infoNotes) ?>)</div>
            <button class="btn btn-sm btn-brand" onclick="kbNoteNew()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14"><path d="M12 5v14M5 12h14"/></svg> Bölüm Ekle</button>
        </div>
        <?php if ($infoNotes): ?>
        <div class="filter-bar mb-2">
            <div class="search-box"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="input" placeholder="Bilgi bankasında ara..." data-search="#kbList .card"></div>
            <div class="pill-filter" data-pill-group="#kbList .card">
                <button class="pill active" data-value="">Tümü</button>
                <?php foreach (NOTE_CATEGORIES as $catKey => $catLabel): ?><button class="pill" data-value="<?= $catKey ?>"><?= $catLabel ?></button><?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div id="kbList">
        <?php if (!$infoNotes): ?>
        <div class="card orta text-muted small" style="padding:22px">Marka rehberi, hedef kitle, yazım dili gibi süreç notlarını buraya ekleyin — müşteri görmez, ekip her zaman ulaşır.</div>
        <?php else: foreach ($infoNotes as $kbNote): ?>
        <div class="card mb-2" style="padding:14px 16px" data-filter="<?= e($kbNote['category'] ?? 'general') ?>" data-search="<?= e($kbNote['title'] . ' ' . $kbNote['text']) ?>">
            <div class="row-flex between">
                <div class="row-flex" style="gap:8px"><?= !empty($kbNote['pinned']) ? '📌 ' : '' ?><span class="bold small"><?= e($kbNote['title']) ?></span>
                    <span class="badge badge-type" style="font-size:10.5px"><?= NOTE_CATEGORIES[$kbNote['category'] ?? 'general'] ?? 'Genel' ?></span></div>
                <div class="row-flex" style="gap:2px">
                    <button class="icon-action" style="width:26px;height:26px" onclick='noteEditKb(<?= json_encode($kbNote, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 13) ?></button>
                    <button class="icon-action danger" style="width:26px;height:26px" data-action="clientnote_delete" data-id="<?= $kbNote['id'] ?>" data-confirm="Bölüm silinsin mi?"><?= icon('cop', 13) ?></button>
                </div>
            </div>
            <div class="small text-2 mt-1" style="white-space:pre-wrap"><?= e($kbNote['text']) ?></div>
            <?php if ($kbNote['update']): ?><div class="cell-bottom mt-2"><?= e($kbNote['updater_name']) ?> güncelledi · <?= time_ago($kbNote['update']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
        </div>

        <div class="modal-overlay" id="modalInfoNot">
            <div class="modal"><div class="modal-top"><div class="modal-title" id="kbTitleTop">Bilgi Bölümü</div><button class="modal-close" data-modal-close>✕</button></div>
            <form data-ajax="clientnote_save">
                <input type="hidden" name="id" id="kb_id"><input type="hidden" name="client_id" value="<?= $id ?>">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Bölüm Başlığı <span class="required">*</span></label><input name="title" id="kb_title" class="input" required placeholder="Örn. Marka Sesi & Yazım Dili"></div>
                        <div class="form-group"><label class="form-label">Kategori</label><select name="category" id="kb_category" class="select">
                            <?php foreach (NOTE_CATEGORIES as $catKey => $catLabel): ?><option value="<?= $catKey ?>"><?= $catLabel ?></option><?php endforeach; ?>
                        </select></div>
                    </div>
                    <label class="row-flex small mb-2" style="gap:8px;cursor:pointer"><input type="checkbox" name="pinned" id="kb_pinned" value="1"> 📌 Üste sabitle</label>
                    <div class="form-group"><label class="form-label">İçerik</label><textarea name="text" id="kb_text" class="text-area" style="min-height:150px"></textarea></div>
                </div>
                <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
            </form></div>
        </div>
        <script>
        function kbNoteNew() { document.getElementById('kb_id').value = ''; document.getElementById('kb_title').value = ''; document.getElementById('kb_text').value = ''; document.getElementById('kb_category').value = 'general'; document.getElementById('kb_pinned').checked = false; document.getElementById('kbTitleTop').textContent = 'Yeni Bilgi Bölümü'; modalOpen('modalInfoNot'); }
        function noteEditKb(n) { document.getElementById('kb_id').value = n.id; document.getElementById('kb_title').value = n.title; document.getElementById('kb_text').value = n.text || ''; document.getElementById('kb_category').value = n.category || 'general'; document.getElementById('kb_pinned').checked = !!+n.pinned; document.getElementById('kbTitleTop').textContent = 'Bölümü Düzenle'; modalOpen('modalInfoNot'); }
        </script>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($customerView): ?>
        <!-- Restricted customer side panel: archive only -->
        <a href="archive.php?client=<?= $id ?>" class="card card-tick row-flex between">
            <div class="row-flex" style="gap:10px"><svg width="20" fill="none" stroke="var(--brand)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8"/></svg><span class="bold small">Paylaşılan Dosyalar</span></div>
            <span class="badge"><?= $archiveCount ?></span>
        </a>
        <?php else: ?>
        <!-- Contact -->
        <div class="card mb-2">
            <div class="card-title" style="font-size:14px" class="mb-2">İletişim</div>
            <div class="vertical mt-2" style="gap:12px">
                <?php if ($client['contact_name']): ?><div><div class="cell-bottom">Kişi</div><div class="cell-main"><?= e($client['contact_name']) ?></div></div><?php endif; ?>
                <?php if ($client['contact_email']): ?><div><div class="cell-bottom">E-posta</div><a href="mailto:<?= e($client['contact_email']) ?>" class="cell-main" style="color:var(--brand)"><?= e($client['contact_email']) ?></a></div><?php endif; ?>
                <?php if ($client['contact_phone']): ?><div><div class="cell-bottom">Telefon</div><div class="cell-main"><?= e($client['contact_phone']) ?></div></div><?php endif; ?>
                <?php if (!$client['contact_name'] && !$client['contact_email']): ?><div class="text-muted small">İletişim bilgisi eklenmemiş.</div><?php endif; ?>
                <?php if ($client['description']): ?><div><div class="cell-bottom">Açıklama</div><div class="small text-2"><?= nl2br(e($client['description'])) ?></div></div><?php endif; ?>
            </div>
        </div>
        <!-- Responsible team -->
        <div class="card mb-2">
            <div class="row-flex between mb-2"><div class="card-title" style="font-size:14px">Sorumlu Ekip</div><?= member_avatars($clientMembers) ?></div>
            <?php if (!$clientMembers): ?><div class="text-muted small">Henüz üye atanmamış.<?php if (permission('client_manage')): ?> Düzenle penceresinden ekleyin.<?php endif; ?></div>
            <?php else: foreach ($clientMembers as $du): ?>
            <div class="row-flex mt-2" style="gap:10px"><?= avatar($du, 30) ?><div><div class="cell-main small"><?= e($du['name']) ?></div><?php if ($du['job_title']): ?><div class="cell-bottom"><?= e($du['job_title']) ?></div><?php endif; ?></div></div>
            <?php endforeach; endif; ?>
        </div>
        <!-- Customer access -->
        <div class="card mb-2">
            <div class="row-flex between mb-2"><div class="card-title" style="font-size:14px">Müşteri Erişimi</div></div>
            <?php if (!$customers): ?>
            <div class="text-muted small mt-2">Bu dosya için müşteri hesabı yok.
                <?php if (is_admin()): ?><br><a href="users.php" style="color:var(--brand)">Kullanıcı ekle →</a><?php endif; ?>
            </div>
            <?php else: foreach ($customers as $m): ?>
            <div class="row-flex mt-2" style="gap:10px"><?= avatar($m, 32) ?><div><div class="cell-main small"><?= e($m['name']) ?></div><div class="cell-bottom"><?= e($m['email']) ?></div></div></div>
            <?php endforeach; endif; ?>
        </div>
        <!-- Contracts -->
        <div class="card mb-2">
            <div class="row-flex between mb-2">
                <div class="card-title" style="font-size:14px">Sözleşmeler</div>
                <?php if (permission('client_manage')): ?><button class="mini-btn" data-modal="modalContract">+ Ekle</button><?php endif; ?>
            </div>
            <?php if (!$contracts): ?><div class="text-muted small">Sözleşme kaydı yok. Bitiş tarihine 30 gün kala otomatik hatırlatılır.</div>
            <?php else: foreach ($contracts as $sz):
                $remainingDays = $sz['end'] ? floor((strtotime($sz['end']) - time()) / 86400) : null;
                $color = $remainingDays !== null && $remainingDays < 0 ? 'var(--danger)' : ($remainingDays !== null && $remainingDays <= 30 ? 'var(--warning)' : 'var(--text-2)'); ?>
            <div class="mt-2" style="padding:10px 12px;background:var(--surface-2);border-radius:10px">
                <div class="row-flex between">
                    <span class="small bold"><?= e($sz['title']) ?></span>
                    <?php if (permission('client_manage')): ?><button class="icon-action danger" style="width:24px;height:24px" data-action="contract_delete" data-id="<?= $sz['id'] ?>" data-confirm="Sözleşme silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button><?php endif; ?>
                </div>
                <div class="cell-bottom mt-1">
                    <?php if ($sz['amount'] > 0): ?><?= money($sz['amount']) ?> · <?php endif; ?>
                    <?= format_date($sz['start']) ?> → <span style="color:<?= $color ?>"><?= format_date($sz['end']) ?><?= $remainingDays !== null && $remainingDays >= 0 && $remainingDays <= 30 ? " ({$remainingDays} gün)" : ($remainingDays !== null && $remainingDays < 0 ? ' (süresi doldu)' : '') ?></span>
                    <?php if ($sz['file_path']): ?> · <a href="uploads/<?= e($sz['file_path']) ?>" target="_blank" style="color:var(--brand)"><?= icon('paperclip', 11) ?> Belge</a><?php endif; ?>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <a href="archive.php?client=<?= $id ?>" class="card card-tick row-flex between">
            <div class="row-flex" style="gap:10px"><svg width="20" fill="none" stroke="var(--brand)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8"/></svg><span class="bold small">Dosya Arşivi</span></div>
            <span class="badge"><?= $archiveCount ?></span>
        </a>

        <?php if (permission('client_manage')): ?>
        <!-- Add contract modal -->
        <div class="modal-overlay" id="modalContract">
            <div class="modal"><div class="modal-top"><div class="modal-title">Sözleşme Ekle</div><button class="modal-close" data-modal-close>✕</button></div>
            <form data-ajax="contract_save">
                <input type="hidden" name="client_id" value="<?= $id ?>">
                <div class="modal-body">
                    <div class="form-group"><label class="form-label">Başlık <span class="required">*</span></label><input name="title" class="input" required placeholder="Örn. 2026 Sosyal Medya Yönetim Sözleşmesi"></div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Başlangıç</label><input type="date" name="start" class="input"></div>
                        <div class="form-group"><label class="form-label">Bitiş</label><input type="date" name="end" class="input"><div class="form-hint">30 gün kala hatırlatılır.</div></div>
                    </div>
                    <div class="form-group"><label class="form-label">Tutar (₺)</label><input name="amount" class="input" placeholder="0,00"></div>
                    <div class="form-group"><label class="form-label">Sözleşme Belgesi</label><input type="file" name="client" class="input"></div>
                    <div class="form-group"><label class="form-label">Not</label><input name="description" class="input"></div>
                </div>
                <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
            </form></div>
        </div>
        <?php endif; ?>
        <?php endif; /* /restricted customer view */ ?>
    </div>
</div>

<?php
// Project modal
$clients = rows("SELECT id, name FROM clients WHERE status='active' ORDER BY name");
$pms = rows("SELECT id, name FROM users WHERE role IN ('admin','pm') AND is_active=1 ORDER BY name");
if (permission('client_manage')):
?>
<div class="modal-overlay" id="modalProject">
    <div class="modal"><div class="modal-top"><div class="modal-title">Yeni Proje — <?= e($client['name']) ?></div><button class="modal-close" data-modal-close>✕</button></div>
        <form data-ajax="project_save">
            <input type="hidden" name="client_id" value="<?= $id ?>">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Proje Adı <span class="required">*</span></label><input name="name" class="input" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Hizmet Türü</label><select name="type" class="select"><?php foreach (PROJECT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Proje Yöneticisi</label><select name="pm_id" class="select"><option value="">—</option><?php foreach ($pms as $pm): ?><option value="<?= $pm['id'] ?>"><?= e($pm['name']) ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Başlangıç</label><input type="date" name="start" class="input"></div>
                    <div class="form-group"><label class="form-label">Sözleşme Tutarı (₺)</label><input name="contract_amount" class="input" placeholder="0,00"></div>
                </div>
                <div class="form-group"><label class="form-label">Proje Şablonu (opsiyonel)</label><select name="ptemplate_id" class="select"><option value="">— Boş proje</option><?php foreach (rows("SELECT id, name FROM project_templates ORDER BY name") as $templateRow): ?><option value="<?= $templateRow['id'] ?>"><?= e($templateRow['name']) ?></option><?php endforeach; ?></select><div class="form-hint">Seçilirse şablondaki görevler akışlarıyla birlikte kurulur.</div></div>
                <?php member_picker(array_column($clientMembers, 'id')); ?>
                <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" class="text-area"></textarea></div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Oluştur</button></div>
        </form>
    </div>
</div>

<!-- Edit client file modal -->
<div class="modal-overlay" id="modalClientEdit">
    <div class="modal"><div class="modal-top"><div class="modal-title">Dosyayı Düzenle</div><button class="modal-close" data-modal-close>✕</button></div>
        <form data-ajax="client_save">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Dosya Adı</label><input name="name" class="input" value="<?= e($client['name']) ?>" required></div>
                    <div class="form-group"><label class="form-label">Tür</label><select name="type" class="select"><?php foreach (CLIENT_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $client['type'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-group"><label class="form-label">Renk</label><div class="row-flex wrap" id="colorSelect2"><?php foreach (['#b1fb01', '#182f5d', '#610714', '#f8f2cb', '#3b9df0', '#35c66b', '#f5a524', '#a58bf0'] as $r): ?><label style="cursor:pointer"><input type="radio" name="color" value="<?= $r ?>" <?= $r === $client['color'] ? 'checked' : '' ?> style="display:none" class="color-radio2"><span class="label-dot" style="width:28px;height:28px;background:<?= $r ?>;border:2px solid <?= $r === $client['color'] ? 'var(--text)' : 'transparent' ?>"></span></label><?php endforeach; ?></div></div>
                <div class="form-group"><label class="form-label">Logo <?= $client['logo'] ? '(mevcut logoyu değiştirir)' : '' ?></label><input type="file" name="logo" class="input" accept="image/*"></div>
                <?php member_picker(array_column($clientMembers, 'id'), 'Sorumlu Ekip Üyeleri'); ?>
                <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" class="text-area"><?= e($client['description']) ?></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">İletişim Kişisi</label><input name="contact_name" class="input" value="<?= e($client['contact_name']) ?>"></div>
                    <div class="form-group"><label class="form-label">Dosya Yöneticisi</label>
                        <select name="manager_id" class="select">
                            <option value="">— Atanmadı</option>
                            <?php foreach (rows("SELECT id, name FROM users WHERE role IN ('admin','pm','team') AND is_active=1 ORDER BY name") as $m2): ?>
                            <option value="<?= $m2['id'] ?>" <?= ($client['manager_id'] ?? null) == $m2['id'] ? 'selected' : '' ?>><?= e($m2['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint">Aylık rapordan sorumlu kişi; hatırlatmalar ona gider.</div>
                    </div>
                    <div class="form-group"><label class="form-label">Drive Klasörü <span class="text-muted" style="font-weight:400">(opsiyonel)</span></label>
                        <input name="drive_folder" class="input" value="<?= e($client['drive_folder_id'] ?? '') ?>" placeholder="Klasör linki veya ID">
                        <div class="form-hint">Bu dosyanın çekim aktarımları bu klasörden otomatik denetlenir (Ayarlar → Drive Entegrasyonu kuruluysa).</div>
                    </div>
                    <div class="form-group"><label class="form-label">Telefon</label><input name="contact_phone" class="input" value="<?= e($client['contact_phone']) ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">E-posta</label><input type="email" name="contact_email" class="input" value="<?= e($client['contact_email']) ?>"></div>
                    <div class="form-group"><label class="form-label">Durum</label><select name="status" class="select"><option value="active" <?= $client['status'] === 'active' ? 'selected' : '' ?>>Aktif</option><option value="inactive" <?= $client['status'] === 'inactive' ? 'selected' : '' ?>>Pasif</option></select></div>
                </div>
            </div>
            <div class="modal-alt">
                <?php if (is_admin()): ?><button type="button" class="btn btn-danger" data-action="client_delete" data-id="<?= $id ?>" data-confirm="Bu dosyayı silmek istediğinize emin misiniz?" style="margin-right:auto">Sil</button><?php endif; ?>
                <button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button>
            </div>
        </form>
    </div>
</div>
<script>
function clientEdit() { modalOpen('modalClientEdit'); }
document.getElementById('colorSelect2')?.addEventListener('change', () => {
    document.querySelectorAll('.color-radio2').forEach(r => r.nextElementSibling.style.borderColor = r.checked ? 'var(--text)' : 'transparent');
});
</script>
<?php endif; /* /client_manage modals */ ?>

<?php if (permission('content_manage')): ?>
<!-- Add social account -->
<div class="modal-overlay" id="modalSocialAccount">
    <div class="modal"><div class="modal-top"><div class="modal-title">Sosyal Medya Hesabı Ekle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="social_account_add">
        <input type="hidden" name="client_id" value="<?= $id ?>">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Platform</label><select name="platform" class="select"><?php foreach (PLATFORMS as $k => $v): if ($k === 'other') continue; ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Kullanıcı Adı <span class="required">*</span></label><input name="username" class="input" required placeholder="@markaadi"></div>
            </div>
            <div class="form-group"><label class="form-label">Profil Linki</label><input name="url" class="input" placeholder="instagram.com/markaadi"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Ekle</button></div>
    </form></div>
</div>
<?php endif; ?>

<?php if (is_staff()): ?>
<!-- Enter metrics -->
<div class="modal-overlay" id="modalMetric">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="metricTitle">Veri Gir</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="social_metric_add">
        <input type="hidden" name="account_id" id="mt_account">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tarih</label><input type="date" name="date" class="input" value="<?= date('Y-m-d') ?>"><div class="form-hint">Aynı güne ikinci giriş, öncekini günceller.</div></div>
                <div class="form-group"><label class="form-label">Takipçi Sayısı <span class="required">*</span></label><input name="followers" class="input" required placeholder="Örn. 12500"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Gönderi Sayısı</label><input name="post" class="input" placeholder="Opsiyonel"></div>
                <div class="form-group"><label class="form-label">Etkileşim</label><input name="engagement" class="input" placeholder="Beğeni+yorum vb."></div>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
function metricEnter(accountId, kadi) {
    document.getElementById('mt_account').value = accountId;
    document.getElementById('metricTitle').textContent = kadi + ' — Veri Gir';
    modalOpen('modalMetric');
}
</script>
<?php page_end(); ?>
