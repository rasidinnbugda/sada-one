<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

// Channels the user is a member of (archived ones in a separate section)
$channels = rows("SELECT k.*, ku.archive,
    (SELECT m.message FROM messages m WHERE m.channel_id=k.id ORDER BY m.id DESC LIMIT 1) last_message,
    (SELECT m.created FROM messages m WHERE m.channel_id=k.id ORDER BY m.id DESC LIMIT 1) last_time,
    (SELECT COUNT(*) FROM messages m WHERE m.channel_id=k.id AND m.user_id!=? AND (ku.last_read IS NULL OR m.created>ku.last_read)) okunmamis
    FROM channels k JOIN channel_members ku ON ku.channel_id=k.id AND ku.user_id=?
    ORDER BY ku.archive, last_time IS NULL, last_time DESC", [$u['id'], $u['id']]);

// In private (DM) channels the name = the other person's name
foreach ($channels as &$k) {
    if ($k['type'] === 'ozel') {
        $diger = row("SELECT us.name, us.color, us.avatar FROM channel_members ku JOIN users us ON us.id=ku.user_id WHERE ku.channel_id=? AND ku.user_id!=? LIMIT 1", [$k['id'], $u['id']]);
        $k['name'] = $diger ? $diger['name'] : 'Özel Sohbet';
        $k['dm_person'] = $diger;
    }
}
unset($k);

$activeChannelId = (int)($_GET['channel'] ?? ($channels[0]['id'] ?? 0));
$activeChannel = null;
foreach ($channels as $k) if ($k['id'] == $activeChannelId) $activeChannel = $k;
if (!$activeChannel && $channels) { $activeChannel = $channels[0]; $activeChannelId = $activeChannel['id']; }

$messages = $activeChannel ? rows("SELECT m.*, us.name, us.color FROM messages m JOIN users us ON us.id=m.user_id WHERE m.channel_id=? ORDER BY m.id", [$activeChannelId]) : [];
if ($activeChannel) update_row('channel_members', ['last_read' => date('Y-m-d H:i:s')], 'channel_id=? AND user_id=?', [$activeChannelId, $u['id']]);
$lastMessageId = $messages ? end($messages)['id'] : 0;

$teamMembers = is_staff() ? rows("SELECT id, name FROM users WHERE id!=? AND role IN ('yonetici','pm','ekip','finans') AND is_active=1 ORDER BY name", [$u['id']]) : [];
// People a DM can be opened with: staff with everyone, clients only with staff
$dmPeople = is_staff()
    ? rows("SELECT id, name, color, avatar, role FROM users WHERE id!=? AND is_active=1 ORDER BY role='musteri', name", [$u['id']])
    : rows("SELECT id, name, color, avatar, role FROM users WHERE id!=? AND is_active=1 AND role!='musteri' ORDER BY name", [$u['id']]);
// Active channel members (for the management panel)
$channelMembers = $activeChannel ? rows("SELECT us.id, us.name, us.color, us.avatar, us.role FROM channel_members ku JOIN users us ON us.id=ku.user_id WHERE ku.channel_id=? ORDER BY us.name", [$activeChannelId]) : [];
$memberOlmayanlar = ($activeChannel && is_staff() && $activeChannel['type'] !== 'ozel')
    ? rows("SELECT id, name FROM users WHERE is_active=1 AND id NOT IN (SELECT user_id FROM channel_members WHERE channel_id=?) ORDER BY name", [$activeChannelId]) : [];

page_start('Mesajlar', 'messages');
?>
<div class="mesaj-duzen <?= $activeChannel ? 'sohbet-acik' : '' ?>" id="messageDuzen">
    <div class="kanal-liste">
        <div class="kanal-arama satir-esnek" style="gap:8px">
            <input class="girdi" placeholder="Kanal ara..." data-search=".kanal-oge" style="flex:1">
            <button class="ikon-eylem" data-modal="modalDM" title="Birebir mesaj"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></button>
            <?php if (is_staff()): ?><button class="ikon-eylem" data-modal="modalChannel" title="Yeni kanal"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button><?php endif; ?>
        </div>
        <?php if (!$channels): ?>
        <div class="bos-mini">Henüz bir kanalınız yok.</div>
        <?php else:
            $archiveBasladiMi = false;
            foreach ($channels as $k):
                if ($k['archive'] && !$archiveBasladiMi) { $archiveBasladiMi = true; echo '<div class="nav-bolum" style="padding:12px 14px 6px">Arşivlenmiş</div>'; }
                $icon = $k['icon'] ?: (['genel' => '#', 'project' => icon('klasor', 17), 'musteri' => icon('el-sikisma', 17), 'ozel' => icon('sohbet', 17)][$k['type']] ?? '#'); ?>
        <a href="?channel=<?= $k['id'] ?>" class="kanal-oge <?= $k['id'] == $activeChannelId ? 'aktif' : '' ?>" data-search="<?= e($k['name']) ?>" style="<?= $k['archive'] ? 'opacity:.55' : '' ?>">
            <div class="dosya-avatar" style="width:38px;height:38px;font-size:15px;background:var(--surface-2)"><?= $k['icon'] ? e($k['icon']) : $icon ?></div>
            <div style="min-width:0;flex:1">
                <div class="kanal-ad"><?= e($k['name']) ?></div>
                <div class="kanal-son"><?= $k['last_message'] ? e(mb_substr($k['last_message'], 0, 40)) : 'Henüz mesaj yok' ?></div>
            </div>
            <?php if ($k['okunmamis'] && !$k['archive']): ?><span class="nav-sayac kanal-rozet"><?= $k['okunmamis'] ?></span><?php endif; ?>
        </a>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($activeChannel): ?>
    <div class="sohbet">
        <div class="sohbet-ust">
            <button class="menu-btn" onclick="document.getElementById('messageDuzen').classList.remove('sohbet-acik')" style="display:none" id="geriBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg></button>
            <div class="dosya-avatar" style="width:38px;height:38px;font-size:15px;background:var(--surface-2)"><?= $activeChannel['icon'] ? e($activeChannel['icon']) : (['genel' => '#', 'project' => icon('klasor', 17), 'musteri' => icon('el-sikisma', 17), 'ozel' => icon('sohbet', 17)][$activeChannel['type']] ?? '#') ?></div>
            <div><div class="kanal-ad"><?= e($activeChannel['name']) ?></div><div class="hucre-alt"><?= count($channelMembers) ?> üye<?= $activeChannel['archive'] ? ' · arşivde' : '' ?></div></div>
            <div class="satir-esnek" style="margin-left:auto;gap:6px">
                <button class="btn btn-sm btn-hayalet" data-modal="modalMembers">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg>
                    Üyeler
                </button>
                <div class="acilir" data-acilir>
                    <button class="btn btn-sm" data-acilir-btn title="Arşivle, simge/ad değiştir, sohbeti sil">
                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Sohbet Ayarları
                    </button>
                    <div class="acilir-panel">
                        <div class="acilir-baslik">Sohbet Ayarları</div>
                        <div style="padding:6px 12px">
                            <div class="hucre-alt mb-2">Simge seç:</div>
                            <div class="satir-esnek sarma" style="gap:2px">
                                <?php foreach (['💬', '📁', '🤝', '🎨', '🎬', '📸', '🌐', '⚡', '🔥', '🎯', '📊', '🚀'] as $em): ?>
                                <button class="tepki-sec" data-action="channel_icon" data-channel_id="<?= $activeChannelId ?>" data-icon="<?= $em ?>"><?= $em ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($activeChannel['type'] !== 'ozel'): ?>
                        <button class="acilir-oge" style="width:100%;text-align:left" onclick="channelNameChange()"><?= icon('item', 13) ?> Adı değiştir</button>
                        <?php endif; ?>
                        <button class="acilir-oge" style="width:100%;text-align:left" data-action="channel_archive_toggle" data-channel_id="<?= $activeChannelId ?>"><?= icon('box', 13) ?> <?= $activeChannel['archive'] ? 'Arşivden çıkar' : 'Sohbeti arşivle' ?></button>
                        <?php if ($activeChannel['type'] === 'ozel' || is_pm()): ?>
                        <button class="acilir-oge tehlike" style="width:100%;text-align:left" data-action="channel_delete" data-channel_id="<?= $activeChannelId ?>" data-approval="Bu sohbet ve tüm mesajları kalıcı olarak silinecek. Emin misiniz?"><?= icon('cop', 13) ?> Sohbeti sil</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="sohbet-govde" id="sohbetBody">
            <?php foreach ($messages as $m): ?>
            <div class="mesaj-balon <?= $m['user_id'] == $u['id'] ? 'benim' : '' ?>">
                <div class="mesaj-gonderen"><?= e($m['name']) ?></div>
                <div><?= highlight_mentions(nl2br(e($m['message']))) ?></div>
                <div class="mesaj-zaman"><?= date('H:i', strtotime($m['created'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <form class="sohbet-yaz mention-kap" id="messageForm">
            <input type="hidden" class="mention-idler" id="messageMention">
            <textarea class="metin-alani" id="messageGirdi" data-mention placeholder="Mesaj yazın... (@ ile etiketleyin, Enter ile gönderin)" required></textarea>
            <button type="submit" class="btn btn-marka"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="18"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg></button>
        </form>
        <?php mention_script(); ?>
    </div>
    <?php else: ?>
    <div class="sohbet" style="align-items:center;justify-content:center">
        <div class="bos-durum"><div class="bos-baslik">Kanal seçin</div><div class="bos-metin">Mesajlaşmaya başlamak için soldan bir kanal seçin<?= is_staff() ? ' veya yeni bir kanal oluşturun' : '' ?>.</div></div>
    </div>
    <?php endif; ?>
</div>

<!-- Direct message (DM) modal -->
<div class="modal-katman" id="modalDM">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Birebir Mesaj</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <div class="modal-govde">
        <div class="form-grup"><input class="girdi" placeholder="Kişi ara..." data-search="#dmList .dm-kisi"></div>
        <div class="dikey" style="gap:4px;max-height:340px;overflow-y:auto" id="dmList">
            <?php foreach ($dmPeople as $person): ?>
            <button class="satir-esnek dm-kisi" style="gap:11px;padding:9px 11px;border-radius:11px;text-align:left;transition:background .2s" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''" data-action="dm_open" data-user_id="<?= $person['id'] ?>" data-refresh="hayir" data-search="<?= e($person['name']) ?>">
                <?= avatar($person, 34) ?>
                <div><div class="hucre-ana kucuk"><?= e($person['name']) ?></div><div class="hucre-alt"><?= ROLES[$person['role']] ?></div></div>
            </button>
            <?php endforeach; ?>
            <?php if (!$dmPeople): ?><div class="bos-mini">Mesaj atılabilecek kişi yok.</div><?php endif; ?>
        </div>
    </div>
    </div>
</div>

<?php if ($activeChannel): ?>
<!-- Channel members modal -->
<div class="modal-katman" id="modalMembers">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik"><?= e($activeChannel['name']) ?> — Üyeler</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <div class="modal-govde">
        <?php if ($memberOlmayanlar && is_staff()): ?>
        <div class="satir-esnek mb-3" style="gap:8px">
            <select class="secim" id="newMemberSelect" style="flex:1">
                <option value="">Üye ekle...</option>
                <?php foreach ($memberOlmayanlar as $uo): ?><option value="<?= $uo['id'] ?>"><?= e($uo['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-marka btn-sm" onclick="memberAdd()">Ekle</button>
        </div>
        <?php endif; ?>
        <div class="dikey" style="gap:4px;max-height:320px;overflow-y:auto">
            <?php foreach ($channelMembers as $ku): ?>
            <div class="satir-esnek arasi" style="padding:8px 10px;border-radius:10px">
                <div class="satir-esnek" style="gap:10px"><?= avatar($ku, 32) ?><div><div class="hucre-ana kucuk"><?= e($ku['name']) ?></div><div class="hucre-alt"><?= ROLES[$ku['role']] ?></div></div></div>
                <?php if ($activeChannel['type'] !== 'ozel' && (is_pm() || $ku['id'] == $u['id'])): ?>
                <button class="ikon-eylem tehlike" data-action="channel_member_cikar" data-channel_id="<?= $activeChannelId ?>" data-user_id="<?= $ku['id'] ?>" data-approval="<?= $ku['id'] == $u['id'] ? 'Kanaldan ayrılmak istiyor musunuz?' : e($ku['name']) . ' kanaldan çıkarılsın mı?' ?>" title="<?= $ku['id'] == $u['id'] ? 'Kanaldan ayrıl' : 'Çıkar' ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a7 7 0 00-7 7h11m5-9l5 5m0-5l-5 5"/></svg>
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    </div>
</div>
<script>
async function memberAdd() {
    const uid = document.getElementById('newMemberSelect').value;
    if (!uid) return;
    const j = await api('channel_member_add', { channel_id: <?= $activeChannelId ?>, user_id: uid });
    if (j.ok) { toast(j.message, 'basari'); setTimeout(() => location.reload(), 550); }
}
</script>
<?php endif; ?>

<?php if (is_staff()): ?>
<div class="modal-katman" id="modalChannel">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Kanal</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="channel_create" id="channelForm">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Kanal Adı <span class="zorunlu">*</span></label><input name="name" class="girdi" required placeholder="Örn. Tasarım Ekibi"></div>
            <div class="form-grup">
                <label class="form-etiket">Üyeler</label>
                <div class="dikey" style="gap:6px;max-height:220px;overflow-y:auto;padding:4px">
                    <?php foreach ($teamMembers as $e): ?>
                    <label class="satir-esnek" style="gap:9px;padding:8px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer"><input type="checkbox" value="<?= $e['id'] ?>" class="kanalUye"> <?= e($e['name']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <input type="hidden" name="members" id="channelMembers">
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
const channelId = <?= $activeChannelId ?>;
let lastId = <?= $lastMessageId ?>;
const body = document.getElementById('sohbetBody');
const mineId = <?= $u['id'] ?>;

function balonAdd(m) {
    const d = document.createElement('div');
    d.className = 'mesaj-balon' + (m.mine ? ' benim' : '');
    d.innerHTML = `<div class="mesaj-gonderen">${esc(m.name)}</div><div>${m.message.replace(/</g,'&lt;').replace(/\n/g,'<br>')}</div><div class="mesaj-zaman">${m.time}</div>`;
    body.appendChild(d);
    body.scrollTop = body.scrollHeight;
}

const form = document.getElementById('messageForm');
const girdi = document.getElementById('messageGirdi');
if (form) {
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const message = girdi.value.trim(); if (!message) return;
        const mentionField = document.getElementById('messageMention');
        const mentions = mentionField.value || '[]';
        girdi.value = ''; mentionField.value = '';
        const j = await api('message_send', { channel_id: channelId, message, mention_ids: mentions });
        if (j.ok) { balonAdd({ name: 'Siz', message, time: new Date().toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'}), mine: true }); lastId = j.id; }
    });
    girdi.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey && !document.querySelector('.mention-acilir')) { e.preventDefault(); form.requestSubmit(); }
    });
}

// Fetch new messages (polling)
if (channelId) setInterval(async () => {
    const j = await api('message_fetch', { channel_id: channelId, last_id: lastId });
    if (j.ok && j.messages.length) {
        j.messages.forEach(m => { if (!m.mine) balonAdd(m); lastId = Math.max(lastId, m.id); });
    }
}, 4000);

// Channel member selection
const channelForm = document.getElementById('channelForm');
if (channelForm) channelForm.addEventListener('submit', () => {
    const selected = Array.from(document.querySelectorAll('.channelMember:checked')).map(c => c.value);
    document.getElementById('channelMembers').value = JSON.stringify(selected);
});

// Mobile back button
if (window.innerWidth <= 760) { const gb = document.getElementById('geriBtn'); if (gb) gb.style.display = 'flex'; }

// Rename the chat
async function channelNameChange() {
    const newName = prompt('Yeni sohbet adı:', <?= json_encode($activeChannel['name'] ?? '', JSON_UNESCAPED_UNICODE) ?>);
    if (!newName || !newName.trim()) return;
    const j = await api('channel_name', { channel_id: channelId, name: newName.trim() });
    if (j.ok) { toast(j.message, 'basari'); setTimeout(() => location.reload(), 550); }
}
</script>
<?php page_end(); ?>
