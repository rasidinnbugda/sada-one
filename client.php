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
    (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id AND g.status='tamamlandi') is_done_count
    FROM projects p LEFT JOIN users u ON u.id=p.pm_id WHERE p.client_id=? ORDER BY p.created DESC", [$id]);
$musteriler = rows("SELECT * FROM users WHERE client_id=? AND role='musteri'", [$id]);
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
<div class="satir-esnek mb-3" style="gap:10px">
    <a href="clients.php" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="metin-muted kucuk">Dosyalar / <?= e($client['name']) ?></span>
</div>

<div class="sayfa-ust">
    <div class="satir-esnek" style="gap:16px">
        <?= client_logo($client, 56, 22) ?>
        <div>
            <div class="sayfa-baslik" style="font-size:24px"><?= e($client['name']) ?></div>
            <div class="satir-esnek mt-1" style="gap:8px">
                <span class="rozet rozet-tur"><?= CLIENT_TYPES[$client['type']] ?></span>
                <?= badge($client['status'], ['is_active' => 'Aktif', 'pasif' => 'Pasif']) ?>
            </div>
        </div>
    </div>
    <?php if (permission('dosya_yonet')): ?>
    <div class="sayfa-ust-aksiyon">
        <button class="btn btn-marka" data-modal="modalProject"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Proje Ekle</button>
        <button class="btn" onclick="clientEdit()"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg> Düzenle</button>
    </div>
    <?php endif; ?>
</div>

<div class="izgara" style="grid-template-columns:1fr 320px" id="clientDuzen">
    <div>
        <!-- Projects -->
        <div class="satir-esnek arasi mb-2"><div class="kart-baslik">Projeler (<?= count($projects) ?>)</div></div>
        <?php if (!$projects): ?>
        <div class="kart orta metin-muted kucuk" style="padding:30px">Bu dosyada henüz proje yok.</div>
        <?php else: foreach (PROJECT_TYPES as $turK => $turV):
            $grup = array_filter($projects, fn($p) => $p['type'] === $turK);
            if (!$grup) continue; ?>
        <div class="nav-bolum" style="padding:14px 0 8px"><?= $turV ?> Hizmetler</div>
        <div class="izgara izgara-2">
            <?php foreach ($grup as $p):
                $rate = $p['task_count'] ? round($p['is_done_count'] / $p['task_count'] * 100) : 0; ?>
            <a href="project.php?id=<?= $p['id'] ?>" class="kart kart-tik" style="padding:16px">
                <div class="satir-esnek arasi mb-2">
                    <div class="kart-baslik" style="font-size:15px"><?= e($p['name']) ?></div>
                    <?= badge($p['status'], PROJECT_STATUSES) ?>
                </div>
                <?php if ($p['pm_name']): ?><div class="hucre-alt">PM: <?= e($p['pm_name']) ?></div><?php endif; ?>
                <div class="ilerleme mt-2"><div class="ilerleme-dolu" data-rate="<?= $rate ?>" style="width:0"></div></div>
                <div class="hucre-alt mt-1"><?= $p['is_done_count'] ?>/<?= $p['task_count'] ?> görev · %<?= $rate ?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; endif; ?>

        <!-- Social media tracking -->
        <div class="satir-esnek arasi mb-2 mt-3">
            <div class="kart-baslik"><?= icon('grafik', 16) ?> Sosyal Medya (<?= count($socialAccounts) ?>)</div>
            <?php if (permission('icerik_yonet')): ?><button class="btn btn-sm btn-marka" data-modal="modalSocialAccount"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Hesap Ekle</button><?php endif; ?>
        </div>
        <?php if (!$socialAccounts): ?>
        <div class="kart orta metin-muted kucuk" style="padding:24px">Bu dosya için sosyal medya hesabı eklenmemiş.<?= permission('icerik_yonet') ? ' Hesap ekleyip takipçi verilerini düzenli girerek büyümeyi izleyin.' : '' ?></div>
        <?php else: ?>
        <div class="izgara izgara-2">
            <?php foreach ($socialAccounts as $sh):
                $last = $sh['metrics'][0] ?? null;
                $onceki = $sh['metrics'][1] ?? null;
                $fark = ($last && $onceki) ? (int)$last['followers'] - (int)$onceki['followers'] : null;
                $maxFollowers = $sh['metrics'] ? max(array_column($sh['metrics'], 'followers')) : 1; ?>
            <div class="kart" style="padding:16px">
                <div class="satir-esnek arasi">
                    <div class="satir-esnek" style="gap:10px;min-width:0">
                        <span class="dosya-avatar" style="width:40px;height:40px;background:var(--parlak);color:var(--marka)"><?= icon(isset(ICONS[$sh['platform']]) ? $sh['platform'] : 'diger', 20) ?></span>
                        <div style="min-width:0">
                            <div class="kalin kucuk"><?php if ($sh['url']): ?><a href="<?= e($sh['url']) ?>" target="_blank" style="color:var(--marka)">@<?= e(ltrim($sh['username'], '@')) ?></a><?php else: ?>@<?= e(ltrim($sh['username'], '@')) ?><?php endif; ?></div>
                            <div class="hucre-alt"><?= PLATFORMS[$sh['platform']] ?? $sh['platform'] ?></div>
                        </div>
                    </div>
                    <?php if (permission('icerik_yonet')): ?>
                    <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-action="social_account_delete" data-id="<?= $sh['id'] ?>" data-approval="Hesap ve tüm metrik geçmişi silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                    <?php endif; ?>
                </div>
                <div class="satir-esnek mt-2" style="gap:14px;align-items:baseline">
                    <span class="stat-deger" style="font-size:26px"><?= $last ? number_format((int)$last['followers'], 0, ',', '.') : '—' ?></span>
                    <span class="hucre-alt">takipçi</span>
                    <?php if ($fark !== null): ?>
                    <span class="kucuk kalin" style="color:<?= $fark >= 0 ? 'var(--basari)' : 'var(--tehlike)' ?>"><?= $fark >= 0 ? '▲ +' : '▼ ' ?><?= number_format($fark, 0, ',', '.') ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($last && ($last['post'] !== null || $last['engagement'] !== null)): ?>
                <div class="hucre-alt mt-1">
                    <?= $last['post'] !== null ? $last['post'] . ' gönderi' : '' ?><?= $last['post'] !== null && $last['engagement'] !== null ? ' · ' : '' ?><?= $last['engagement'] !== null ? number_format((int)$last['engagement'], 0, ',', '.') . ' etkileşim' : '' ?>
                </div>
                <?php endif; ?>
                <?php if (count($sh['metrics']) > 1): ?>
                <!-- Mini history chart (old→new) -->
                <div style="display:flex;gap:3px;align-items:flex-end;height:36px;margin-top:10px" title="Son <?= count($sh['metrics']) ?> kayıt">
                    <?php foreach (array_reverse($sh['metrics']) as $m): ?>
                    <div style="flex:1;background:var(--marka);opacity:.75;border-radius:3px 3px 0 0;height:<?= max(8, round((int)$m['followers'] / max(1, $maxFollowers) * 100)) ?>%" title="<?= format_date($m['date']) ?>: <?= number_format((int)$m['followers'], 0, ',', '.') ?>"></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="satir-esnek arasi mt-2">
                    <span class="hucre-alt"><?= $last ? 'Son veri: ' . format_date($last['date']) : 'Henüz veri girilmedi' ?></span>
                    <?php if (is_staff()): ?><button class="mini-btn" onclick="metrikGir(<?= $sh['id'] ?>, '<?= e($sh['username']) ?>')">+ Veri Gir</button><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$customerView):
            $infoNotes = rows("SELECT bn.*, us.name updater_name FROM client_notes bn LEFT JOIN users us ON us.id=bn.updated_by WHERE bn.client_id=? ORDER BY bn.sort_order", [$id]); ?>
        <!-- Knowledge base (team only) -->
        <div class="satir-esnek arasi mb-2 mt-3">
            <div class="kart-baslik"><?= icon('document', 16) ?> Bilgi Bankası (<?= count($infoNotes) ?>)</div>
            <button class="btn btn-sm btn-marka" onclick="notNew()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14"><path d="M12 5v14M5 12h14"/></svg> Bölüm Ekle</button>
        </div>
        <?php if (!$infoNotes): ?>
        <div class="kart orta metin-muted kucuk" style="padding:22px">Marka rehberi, hedef kitle, yazım dili gibi süreç notlarını buraya ekleyin — müşteri görmez, ekip her zaman ulaşır.</div>
        <?php else: foreach ($infoNotes as $bn): ?>
        <div class="kart mb-2" style="padding:14px 16px">
            <div class="satir-esnek arasi">
                <div class="kalin kucuk"><?= e($bn['title']) ?></div>
                <div class="satir-esnek" style="gap:2px">
                    <button class="ikon-eylem" style="width:26px;height:26px" onclick='notDuzenleBB(<?= json_encode($bn, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 13) ?></button>
                    <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-action="clientnote_delete" data-id="<?= $bn['id'] ?>" data-approval="Bölüm silinsin mi?"><?= icon('cop', 13) ?></button>
                </div>
            </div>
            <div class="kucuk metin-2 mt-1" style="white-space:pre-wrap"><?= e($bn['text']) ?></div>
            <?php if ($bn['update']): ?><div class="hucre-alt mt-2"><?= e($bn['updater_name']) ?> güncelledi · <?= time_ago($bn['update']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; endif; ?>

        <div class="modal-katman" id="modalInfoNot">
            <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="bnTitleTop">Bilgi Bölümü</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
            <form data-ajax="clientnote_save">
                <input type="hidden" name="id" id="bn_id"><input type="hidden" name="client_id" value="<?= $id ?>">
                <div class="modal-govde">
                    <div class="form-grup"><label class="form-etiket">Bölüm Başlığı <span class="zorunlu">*</span></label><input name="title" id="bn_title" class="girdi" required placeholder="Örn. Marka Sesi & Yazım Dili"></div>
                    <div class="form-grup"><label class="form-etiket">İçerik</label><textarea name="text" id="bn_text" class="metin-alani" style="min-height:150px"></textarea></div>
                </div>
                <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
            </form></div>
        </div>
        <script>
        function notNew() { document.getElementById('bn_id').value = ''; document.getElementById('bn_title').value = ''; document.getElementById('bn_text').value = ''; document.getElementById('bnTitleTop').textContent = 'Yeni Bilgi Bölümü'; modalOpen('modalInfoNot'); }
        function notDuzenleBB(n) { document.getElementById('bn_id').value = n.id; document.getElementById('bn_title').value = n.title; document.getElementById('bn_text').value = n.text || ''; document.getElementById('bnTitleTop').textContent = 'Bölümü Düzenle'; modalOpen('modalInfoNot'); }
        </script>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($customerView): ?>
        <!-- Restricted customer side panel: archive only -->
        <a href="archive.php?client=<?= $id ?>" class="kart kart-tik satir-esnek arasi">
            <div class="satir-esnek" style="gap:10px"><svg width="20" fill="none" stroke="var(--marka)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8"/></svg><span class="kalin kucuk">Paylaşılan Dosyalar</span></div>
            <span class="rozet"><?= $archiveCount ?></span>
        </a>
        <?php else: ?>
        <!-- Contact -->
        <div class="kart mb-2">
            <div class="kart-baslik" style="font-size:14px" class="mb-2">İletişim</div>
            <div class="dikey mt-2" style="gap:12px">
                <?php if ($client['contact_name']): ?><div><div class="hucre-alt">Kişi</div><div class="hucre-ana"><?= e($client['contact_name']) ?></div></div><?php endif; ?>
                <?php if ($client['contact_email']): ?><div><div class="hucre-alt">E-posta</div><a href="mailto:<?= e($client['contact_email']) ?>" class="hucre-ana" style="color:var(--marka)"><?= e($client['contact_email']) ?></a></div><?php endif; ?>
                <?php if ($client['contact_phone']): ?><div><div class="hucre-alt">Telefon</div><div class="hucre-ana"><?= e($client['contact_phone']) ?></div></div><?php endif; ?>
                <?php if (!$client['contact_name'] && !$client['contact_email']): ?><div class="metin-muted kucuk">İletişim bilgisi eklenmemiş.</div><?php endif; ?>
                <?php if ($client['description']): ?><div><div class="hucre-alt">Açıklama</div><div class="kucuk metin-2"><?= nl2br(e($client['description'])) ?></div></div><?php endif; ?>
            </div>
        </div>
        <!-- Responsible team -->
        <div class="kart mb-2">
            <div class="satir-esnek arasi mb-2"><div class="kart-baslik" style="font-size:14px">Sorumlu Ekip</div><?= member_avatars($clientMembers) ?></div>
            <?php if (!$clientMembers): ?><div class="metin-muted kucuk">Henüz üye atanmamış.<?php if (permission('dosya_yonet')): ?> Düzenle penceresinden ekleyin.<?php endif; ?></div>
            <?php else: foreach ($clientMembers as $du): ?>
            <div class="satir-esnek mt-2" style="gap:10px"><?= avatar($du, 30) ?><div><div class="hucre-ana kucuk"><?= e($du['name']) ?></div><?php if ($du['job_title']): ?><div class="hucre-alt"><?= e($du['job_title']) ?></div><?php endif; ?></div></div>
            <?php endforeach; endif; ?>
        </div>
        <!-- Customer access -->
        <div class="kart mb-2">
            <div class="satir-esnek arasi mb-2"><div class="kart-baslik" style="font-size:14px">Müşteri Erişimi</div></div>
            <?php if (!$musteriler): ?>
            <div class="metin-muted kucuk mt-2">Bu dosya için müşteri hesabı yok.
                <?php if (is_admin()): ?><br><a href="users.php" style="color:var(--marka)">Kullanıcı ekle →</a><?php endif; ?>
            </div>
            <?php else: foreach ($musteriler as $m): ?>
            <div class="satir-esnek mt-2" style="gap:10px"><?= avatar($m, 32) ?><div><div class="hucre-ana kucuk"><?= e($m['name']) ?></div><div class="hucre-alt"><?= e($m['email']) ?></div></div></div>
            <?php endforeach; endif; ?>
        </div>
        <!-- Contracts -->
        <div class="kart mb-2">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik" style="font-size:14px">Sözleşmeler</div>
                <?php if (permission('dosya_yonet')): ?><button class="mini-btn" data-modal="modalContract">+ Ekle</button><?php endif; ?>
            </div>
            <?php if (!$contracts): ?><div class="metin-muted kucuk">Sözleşme kaydı yok. Bitiş tarihine 30 gün kala otomatik hatırlatılır.</div>
            <?php else: foreach ($contracts as $sz):
                $kalanDay = $sz['end'] ? floor((strtotime($sz['end']) - time()) / 86400) : null;
                $color = $kalanDay !== null && $kalanDay < 0 ? 'var(--tehlike)' : ($kalanDay !== null && $kalanDay <= 30 ? 'var(--warning)' : 'var(--text-2)'); ?>
            <div class="mt-2" style="padding:10px 12px;background:var(--surface-2);border-radius:10px">
                <div class="satir-esnek arasi">
                    <span class="kucuk kalin"><?= e($sz['title']) ?></span>
                    <?php if (permission('dosya_yonet')): ?><button class="ikon-eylem tehlike" style="width:24px;height:24px" data-action="contract_delete" data-id="<?= $sz['id'] ?>" data-approval="Sözleşme silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button><?php endif; ?>
                </div>
                <div class="hucre-alt mt-1">
                    <?php if ($sz['amount'] > 0): ?><?= money($sz['amount']) ?> · <?php endif; ?>
                    <?= format_date($sz['start']) ?> → <span style="color:<?= $color ?>"><?= format_date($sz['end']) ?><?= $kalanDay !== null && $kalanDay >= 0 && $kalanDay <= 30 ? " ({$kalanDay} gün)" : ($kalanDay !== null && $kalanDay < 0 ? ' (süresi doldu)' : '') ?></span>
                    <?php if ($sz['file_path']): ?> · <a href="uploads/<?= e($sz['file_path']) ?>" target="_blank" style="color:var(--marka)"><?= icon('atac', 11) ?> Belge</a><?php endif; ?>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <a href="archive.php?client=<?= $id ?>" class="kart kart-tik satir-esnek arasi">
            <div class="satir-esnek" style="gap:10px"><svg width="20" fill="none" stroke="var(--marka)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8"/></svg><span class="kalin kucuk">Dosya Arşivi</span></div>
            <span class="rozet"><?= $archiveCount ?></span>
        </a>

        <?php if (permission('dosya_yonet')): ?>
        <!-- Add contract modal -->
        <div class="modal-katman" id="modalContract">
            <div class="modal"><div class="modal-ust"><div class="modal-baslik">Sözleşme Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
            <form data-ajax="contract_save">
                <input type="hidden" name="client_id" value="<?= $id ?>">
                <div class="modal-govde">
                    <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="title" class="girdi" required placeholder="Örn. 2026 Sosyal Medya Yönetim Sözleşmesi"></div>
                    <div class="form-satir">
                        <div class="form-grup"><label class="form-etiket">Başlangıç</label><input type="date" name="start" class="girdi"></div>
                        <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="date" name="end" class="girdi"><div class="form-ipucu">30 gün kala hatırlatılır.</div></div>
                    </div>
                    <div class="form-grup"><label class="form-etiket">Tutar (₺)</label><input name="amount" class="girdi" placeholder="0,00"></div>
                    <div class="form-grup"><label class="form-etiket">Sözleşme Belgesi</label><input type="file" name="client" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Not</label><input name="description" class="girdi"></div>
                </div>
                <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
            </form></div>
        </div>
        <?php endif; ?>
        <?php endif; /* /restricted customer view */ ?>
    </div>
</div>

<?php
// Project modal
$clients = rows("SELECT id, name FROM clients WHERE status='aktif' ORDER BY name");
$pmler = rows("SELECT id, name FROM users WHERE role IN ('yonetici','pm') AND is_active=1 ORDER BY name");
if (permission('dosya_yonet')):
?>
<div class="modal-katman" id="modalProject">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Proje — <?= e($client['name']) ?></div><button class="modal-kapat" data-modal-kapat>✕</button></div>
        <form data-ajax="project_save">
            <input type="hidden" name="client_id" value="<?= $id ?>">
            <div class="modal-govde">
                <div class="form-grup"><label class="form-etiket">Proje Adı <span class="zorunlu">*</span></label><input name="name" class="girdi" required></div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Hizmet Türü</label><select name="type" class="secim"><?php foreach (PROJECT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                    <div class="form-grup"><label class="form-etiket">Proje Yöneticisi</label><select name="pm_id" class="secim"><option value="">—</option><?php foreach ($pmler as $pm): ?><option value="<?= $pm['id'] ?>"><?= e($pm['name']) ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Başlangıç</label><input type="date" name="start" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Sözleşme Tutarı (₺)</label><input name="contract_amount" class="girdi" placeholder="0,00"></div>
                </div>
                <div class="form-grup"><label class="form-etiket">Proje Şablonu (opsiyonel)</label><select name="ptemplate_id" class="secim"><option value="">— Boş proje</option><?php foreach (rows("SELECT id, name FROM project_templates ORDER BY name") as $psx): ?><option value="<?= $psx['id'] ?>"><?= e($psx['name']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilirse şablondaki görevler akışlarıyla birlikte kurulur.</div></div>
                <?php member_picker(array_column($clientMembers, 'id')); ?>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="description" class="metin-alani"></textarea></div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
        </form>
    </div>
</div>

<!-- Edit client file modal -->
<div class="modal-katman" id="modalClientDuzen">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Dosyayı Düzenle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
        <form data-ajax="client_save">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="modal-govde">
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Dosya Adı</label><input name="name" class="girdi" value="<?= e($client['name']) ?>" required></div>
                    <div class="form-grup"><label class="form-etiket">Tür</label><select name="type" class="secim"><?php foreach (CLIENT_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $client['type'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-grup"><label class="form-etiket">Renk</label><div class="satir-esnek sarma" id="renkSecim2"><?php foreach (['#b1fb01', '#182f5d', '#610714', '#f8f2cb', '#3b9df0', '#35c66b', '#f5a524', '#a58bf0'] as $r): ?><label style="cursor:pointer"><input type="radio" name="color" value="<?= $r ?>" <?= $r === $client['color'] ? 'checked' : '' ?> style="display:none" class="renk-radio2"><span class="etiket-nokta" style="width:28px;height:28px;background:<?= $r ?>;border:2px solid <?= $r === $client['color'] ? 'var(--text)' : 'transparent' ?>"></span></label><?php endforeach; ?></div></div>
                <div class="form-grup"><label class="form-etiket">Logo <?= $client['logo'] ? '(mevcut logoyu değiştirir)' : '' ?></label><input type="file" name="logo" class="girdi" accept="image/*"></div>
                <?php member_picker(array_column($clientMembers, 'id'), 'Sorumlu Ekip Üyeleri'); ?>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="description" class="metin-alani"><?= e($client['description']) ?></textarea></div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">İletişim Kişisi</label><input name="contact_name" class="girdi" value="<?= e($client['contact_name']) ?>"></div>
                    <div class="form-grup"><label class="form-etiket">Telefon</label><input name="contact_phone" class="girdi" value="<?= e($client['contact_phone']) ?>"></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">E-posta</label><input type="email" name="contact_email" class="girdi" value="<?= e($client['contact_email']) ?>"></div>
                    <div class="form-grup"><label class="form-etiket">Durum</label><select name="status" class="secim"><option value="aktif" <?= $client['status'] === 'aktif' ? 'selected' : '' ?>>Aktif</option><option value="pasif" <?= $client['status'] === 'pasif' ? 'selected' : '' ?>>Pasif</option></select></div>
                </div>
            </div>
            <div class="modal-alt">
                <?php if (is_admin()): ?><button type="button" class="btn btn-tehlike" data-action="client_delete" data-id="<?= $id ?>" data-approval="Bu dosyayı silmek istediğinize emin misiniz?" style="margin-right:auto">Sil</button><?php endif; ?>
                <button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button>
            </div>
        </form>
    </div>
</div>
<script>
function clientEdit() { modalOpen('modalClientDuzen'); }
document.getElementById('renkSecim2')?.addEventListener('change', () => {
    document.querySelectorAll('.color-radio2').forEach(r => r.nextElementSibling.style.borderColor = r.checked ? 'var(--text)' : 'transparent');
});
</script>
<?php endif; /* /dosya_yonet modals */ ?>

<?php if (permission('icerik_yonet')): ?>
<!-- Add social account -->
<div class="modal-katman" id="modalSocialAccount">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Sosyal Medya Hesabı Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="social_account_add">
        <input type="hidden" name="client_id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Platform</label><select name="platform" class="secim"><?php foreach (PLATFORMS as $k => $v): if ($k === 'diger') continue; ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Kullanıcı Adı <span class="zorunlu">*</span></label><input name="username" class="girdi" required placeholder="@markaadi"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Profil Linki</label><input name="url" class="girdi" placeholder="instagram.com/markaadi"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Ekle</button></div>
    </form></div>
</div>
<?php endif; ?>

<?php if (is_staff()): ?>
<!-- Enter metrics -->
<div class="modal-katman" id="modalMetric">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="metricTitle">Veri Gir</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="social_metric_add">
        <input type="hidden" name="account_id" id="mt_account">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tarih</label><input type="date" name="date" class="girdi" value="<?= date('Y-m-d') ?>"><div class="form-ipucu">Aynı güne ikinci giriş, öncekini günceller.</div></div>
                <div class="form-grup"><label class="form-etiket">Takipçi Sayısı <span class="zorunlu">*</span></label><input name="followers" class="girdi" required placeholder="Örn. 12500"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Gönderi Sayısı</label><input name="post" class="girdi" placeholder="Opsiyonel"></div>
                <div class="form-grup"><label class="form-etiket">Etkileşim</label><input name="engagement" class="girdi" placeholder="Beğeni+yorum vb."></div>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
function metricGir(accountId, kadi) {
    document.getElementById('mt_account').value = accountId;
    document.getElementById('metricTitle').textContent = kadi + ' — Veri Gir';
    modalOpen('modalMetric');
}
</script>
<?php page_end(); ?>
