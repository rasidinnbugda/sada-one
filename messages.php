<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

// Channels the user is a member of (archived ones in a separate section)
$channels = rows("SELECT k.*, ku.archive,
    (SELECT m.message FROM messages m WHERE m.channel_id=k.id ORDER BY m.id DESC LIMIT 1) last_message,
    (SELECT m.created FROM messages m WHERE m.channel_id=k.id ORDER BY m.id DESC LIMIT 1) last_time,
    (SELECT COUNT(*) FROM messages m WHERE m.channel_id=k.id AND m.user_id!=? AND (ku.last_read IS NULL OR m.created>ku.last_read)) unread
    FROM channels k JOIN channel_members ku ON ku.channel_id=k.id AND ku.user_id=?
    ORDER BY ku.archive, last_time IS NULL, last_time DESC", [$u['id'], $u['id']]);

// In private (DM) channels the name = the other person's name
foreach ($channels as &$k) {
    if ($k['type'] === 'private') {
        $other = row("SELECT us.name, us.color, us.avatar FROM channel_members ku JOIN users us ON us.id=ku.user_id WHERE ku.channel_id=? AND ku.user_id!=? LIMIT 1", [$k['id'], $u['id']]);
        $k['name'] = $other ? $other['name'] : 'Özel Sohbet';
        $k['dm_person'] = $other;
    }
}
unset($k);

$activeChannelId = (int)($_GET['channel'] ?? ($channels[0]['id'] ?? 0));
$activeChannel = null;
foreach ($channels as $k) if ($k['id'] == $activeChannelId) $activeChannel = $k;
if (!$activeChannel && $channels) { $activeChannel = $channels[0]; $activeChannelId = $activeChannel['id']; }

// Last 300 messages only — a long-lived channel's full history is not rendered on every open
$messages = $activeChannel ? rows("SELECT * FROM (SELECT m.*, us.name, us.color FROM messages m JOIN users us ON us.id=m.user_id
    WHERE m.channel_id=? ORDER BY m.id DESC LIMIT 300) son ORDER BY id", [$activeChannelId]) : [];
if ($activeChannel) chat_mark_seen($activeChannelId, (int)$u['id']);
$lastMessageId = $messages ? end($messages)['id'] : 0;

$teamMembers = is_staff() ? rows("SELECT id, name FROM users WHERE id!=? AND role IN ('admin','pm','team','finance') AND is_active=1 ORDER BY name", [$u['id']]) : [];
// People a DM can be opened with: staff with everyone, clients only with staff
$dmPeople = is_staff()
    ? rows("SELECT id, name, color, avatar, role FROM users WHERE id!=? AND is_active=1 ORDER BY role='customer', name", [$u['id']])
    : rows("SELECT id, name, color, avatar, role FROM users WHERE id!=? AND is_active=1 AND role!='customer' ORDER BY name", [$u['id']]);
// Active channel members (for the management panel)
$channelMembers = $activeChannel ? rows("SELECT us.id, us.name, us.color, us.avatar, us.role FROM channel_members ku JOIN users us ON us.id=ku.user_id WHERE ku.channel_id=? ORDER BY us.name", [$activeChannelId]) : [];
$nonMembers = ($activeChannel && is_staff() && $activeChannel['type'] !== 'private')
    ? rows("SELECT id, name FROM users WHERE is_active=1 AND id NOT IN (SELECT user_id FROM channel_members WHERE channel_id=?) ORDER BY name", [$activeChannelId]) : [];

page_start('Mesajlar', 'messages');
?>
<div class="message-edit <?= $activeChannel ? 'chat-open' : '' ?>" id="messageEdit">
    <div class="channel-list">
        <div class="channel-search row-flex" style="gap:8px">
            <input class="input" placeholder="Kanal ara..." data-search=".channel-item" style="flex:1">
            <button class="icon-action" data-modal="modalDM" title="Birebir mesaj"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></button>
            <?php if (is_staff()): ?><button class="icon-action" data-modal="modalChannel" title="Yeni kanal"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button><?php endif; ?>
        </div>
        <?php if (!$channels): ?>
        <div class="empty-mini">Henüz bir kanalınız yok.</div>
        <?php else:
            $archiveStarted = false;
            foreach ($channels as $k):
                if ($k['archive'] && !$archiveStarted) { $archiveStarted = true; echo '<div class="nav-section" style="padding:12px 14px 6px">Arşivlenmiş</div>'; }
                $icon = $k['icon'] ?: (['general' => '#', 'project' => icon('folder', 17), 'customer' => icon('handshake', 17), 'private' => icon('chat', 17)][$k['type']] ?? '#'); ?>
        <a href="?channel=<?= $k['id'] ?>" class="channel-item <?= $k['id'] == $activeChannelId ? 'active' : '' ?>" data-search="<?= e($k['name']) ?>" style="<?= $k['archive'] ? 'opacity:.55' : '' ?>">
            <div class="file-avatar" style="width:38px;height:38px;font-size:15px;background:var(--surface-2)"><?= $k['icon'] ? e($k['icon']) : $icon ?></div>
            <div style="min-width:0;flex:1">
                <div class="channel-name"><?= e($k['name']) ?></div>
                <div class="channel-last"><?= $k['last_message'] ? e(mb_substr($k['last_message'], 0, 40)) : 'Henüz mesaj yok' ?></div>
            </div>
            <?php if ($k['unread'] && !$k['archive']): ?><span class="nav-counter channel-badge"><?= $k['unread'] ?></span><?php endif; ?>
        </a>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($activeChannel): ?>
    <div class="chat">
        <div class="chat-top">
            <button class="menu-btn" onclick="document.getElementById('messageEdit').classList.remove('chat-open')" style="display:none" id="backBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg></button>
            <div class="file-avatar" style="width:38px;height:38px;font-size:15px;background:var(--surface-2)"><?= $activeChannel['icon'] ? e($activeChannel['icon']) : (['general' => '#', 'project' => icon('folder', 17), 'customer' => icon('handshake', 17), 'private' => icon('chat', 17)][$activeChannel['type']] ?? '#') ?></div>
            <div><div class="channel-name"><?= e($activeChannel['name']) ?></div><div class="cell-bottom"><?= count($channelMembers) ?> üye<?= $activeChannel['archive'] ? ' · arşivde' : '' ?></div></div>
            <div class="row-flex" style="margin-left:auto;gap:6px">
                <button class="btn btn-sm btn-ghost" data-modal="modalMembers">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg>
                    Üyeler
                </button>
                <div class="dropdown" data-dropdown>
                    <button class="btn btn-sm" data-dropdown-btn title="Arşivle, simge/ad değiştir, sohbeti sil">
                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Sohbet Ayarları
                    </button>
                    <div class="dropdown-panel">
                        <div class="dropdown-title">Sohbet Ayarları</div>
                        <div style="padding:6px 12px">
                            <div class="cell-bottom mb-2">Simge seç:</div>
                            <div class="row-flex wrap" style="gap:2px">
                                <?php foreach (['💬', '📁', '🤝', '🎨', '🎬', '📸', '🌐', '⚡', '🔥', '🎯', '📊', '🚀'] as $em): ?>
                                <button class="reaction-select" data-action="channel_icon" data-channel_id="<?= $activeChannelId ?>" data-icon="<?= $em ?>"><?= $em ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($activeChannel['type'] !== 'private'): ?>
                        <button class="dropdown-item" style="width:100%;text-align:left" onclick="channelNameChange()"><?= icon('item', 13) ?> Adı değiştir</button>
                        <?php endif; ?>
                        <button class="dropdown-item" style="width:100%;text-align:left" data-action="channel_archive_toggle" data-channel_id="<?= $activeChannelId ?>"><?= icon('box', 13) ?> <?= $activeChannel['archive'] ? 'Arşivden çıkar' : 'Sohbeti arşivle' ?></button>
                        <?php if ($activeChannel['type'] === 'private' || is_pm()): ?>
                        <button class="dropdown-item danger" style="width:100%;text-align:left" data-action="channel_delete" data-channel_id="<?= $activeChannelId ?>" data-confirm="Bu sohbet ve tüm mesajları kalıcı olarak silinecek. Emin misiniz?"><?= icon('cop', 13) ?> Sohbeti sil</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- Messages are drawn by the script below: time stamps between them, ticks on your own -->
        <div class="chat-body" id="chatBody"></div>
        <form class="chat-write mention-wrap" id="messageForm">
            <input type="hidden" class="mention-ids" id="messageMention">
            <textarea class="text-area" id="messageInput" data-mention placeholder="Mesaj yazın... (@ ile etiketleyin, Enter ile gönderin)" required></textarea>
            <button type="submit" class="btn btn-brand"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="18"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg></button>
        </form>
        <?php mention_script(); ?>
    </div>
    <?php else: ?>
    <div class="chat" style="align-items:center;justify-content:center">
        <div class="empty-state"><div class="empty-title">Kanal seçin</div><div class="empty-text">Mesajlaşmaya başlamak için soldan bir kanal seçin<?= is_staff() ? ' veya yeni bir kanal oluşturun' : '' ?>.</div></div>
    </div>
    <?php endif; ?>
</div>

<!-- Direct message (DM) modal -->
<div class="modal-overlay" id="modalDM">
    <div class="modal"><div class="modal-top"><div class="modal-title">Birebir Mesaj</div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body">
        <div class="form-group"><input class="input" placeholder="Kişi ara..." data-search="#dmList .dm-person"></div>
        <div class="vertical" style="gap:4px;max-height:340px;overflow-y:auto" id="dmList">
            <?php foreach ($dmPeople as $person): ?>
            <button class="row-flex dm-person" style="gap:11px;padding:9px 11px;border-radius:11px;text-align:left;transition:background .2s" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''" data-action="dm_open" data-user_id="<?= $person['id'] ?>" data-refresh="no" data-search="<?= e($person['name']) ?>">
                <?= avatar($person, 34) ?>
                <div><div class="cell-main small"><?= e($person['name']) ?></div><div class="cell-bottom"><?= ROLES[$person['role']] ?></div></div>
            </button>
            <?php endforeach; ?>
            <?php if (!$dmPeople): ?><div class="empty-mini">Mesaj atılabilecek kişi yok.</div><?php endif; ?>
        </div>
    </div>
    </div>
</div>

<?php if ($activeChannel): ?>
<!-- Channel members modal -->
<div class="modal-overlay" id="modalMembers">
    <div class="modal"><div class="modal-top"><div class="modal-title"><?= e($activeChannel['name']) ?> — Üyeler</div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body">
        <?php if ($nonMembers && is_staff()): ?>
        <div class="row-flex mb-3" style="gap:8px">
            <select class="select" id="newMemberSelect" style="flex:1">
                <option value="">Üye ekle...</option>
                <?php foreach ($nonMembers as $uo): ?><option value="<?= $uo['id'] ?>"><?= e($uo['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-brand btn-sm" onclick="memberAdd()">Ekle</button>
        </div>
        <?php endif; ?>
        <div class="vertical" style="gap:4px;max-height:320px;overflow-y:auto">
            <?php foreach ($channelMembers as $ku): ?>
            <div class="row-flex between" style="padding:8px 10px;border-radius:10px">
                <div class="row-flex" style="gap:10px"><?= avatar($ku, 32) ?><div><div class="cell-main small"><?= e($ku['name']) ?></div><div class="cell-bottom"><?= ROLES[$ku['role']] ?></div></div></div>
                <?php if ($activeChannel['type'] !== 'private' && (is_pm() || $ku['id'] == $u['id'])): ?>
                <button class="icon-action danger" data-action="channel_member_remove" data-channel_id="<?= $activeChannelId ?>" data-user_id="<?= $ku['id'] ?>" data-confirm="<?= $ku['id'] == $u['id'] ? 'Kanaldan ayrılmak istiyor musunuz?' : e($ku['name']) . ' kanaldan çıkarılsın mı?' ?>" title="<?= $ku['id'] == $u['id'] ? 'Kanaldan ayrıl' : 'Çıkar' ?>">
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
    if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 550); }
}
</script>
<?php endif; ?>

<?php if (is_staff()): ?>
<div class="modal-overlay" id="modalChannel">
    <div class="modal"><div class="modal-top"><div class="modal-title">Yeni Kanal</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="channel_create" id="channelForm">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Kanal Adı <span class="required">*</span></label><input name="name" class="input" required placeholder="Örn. Tasarım Ekibi"></div>
            <div class="form-group">
                <label class="form-label">Üyeler</label>
                <div class="vertical" style="gap:6px;max-height:220px;overflow-y:auto;padding:4px">
                    <?php foreach ($teamMembers as $e): ?>
                    <label class="row-flex" style="gap:9px;padding:8px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer"><input type="checkbox" value="<?= $e['id'] ?>" class="channelMember"> <?= e($e['name']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <input type="hidden" name="members" id="channelMembers">
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Oluştur</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
const channelId = <?= $activeChannelId ?>;
let lastId = <?= $lastMessageId ?>;
const body = document.getElementById('chatBody');
const mineId = <?= $u['id'] ?>;
// The other members and the last message each has seen
let READS = <?= json_encode($activeChannel ? chat_reads($activeChannelId, (int)$u['id']) : [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

/* ---- When: "Bugün 14:32", "Dün 09:10", "Pazartesi 18:20", "12 Eki 14:32", "3 Mar 2025 11:05" ---- */
const chatMonths = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
const chatDays = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
const chatDate = s => { const [d, t] = s.split(' '); const [y, mo, da] = d.split('-').map(Number); const [h, mi, se] = t.split(':').map(Number); return new Date(y, mo - 1, da, h, mi, se || 0); };
const hm = d => String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
const nowStamp = () => { const d = new Date(), p = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`; };
function stampLabel(d) {
    const day = x => new Date(x.getFullYear(), x.getMonth(), x.getDate());
    const ago = Math.round((day(new Date()) - day(d)) / 864e5);
    if (ago === 0) return 'Bugün ' + hm(d);
    if (ago === 1) return 'Dün ' + hm(d);
    if (ago > 1 && ago < 7) return chatDays[d.getDay()] + ' ' + hm(d);
    return d.getDate() + ' ' + chatMonths[d.getMonth()].slice(0, 3) + (d.getFullYear() !== new Date().getFullYear() ? ' ' + d.getFullYear() : '') + ' ' + hm(d);
}
const fullDate = d => `${d.getDate()} ${chatMonths[d.getMonth()]} ${d.getFullYear()} ${chatDays[d.getDay()]}, ${hm(d)}`;

/* ---- Ticks: sending (clock) → sent (✓) → seen by some (✓✓) → seen by everyone (coloured ✓✓) ---- */
const TICK = {
    sending: '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="8" cy="8" r="6"/><path d="M8 4.8V8l2.2 1.5"/></svg>',
    sent: '<svg viewBox="0 0 16 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6.5l3 3 7-7"/></svg>',
    seen: '<svg viewBox="0 0 20 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 6.5l3 3 7-7M8.5 9.5l7-7"/></svg>',
};
TICK['seen-all'] = TICK.seen;
const TICK_TITLE = { sending: 'Gönderiliyor…', sent: 'Gönderildi' };
function setTick(el, state, title) {
    const t = el.querySelector('.msg-ticks');
    if (!t || t.dataset.state === state && t.title === title) return;
    t.dataset.state = state;
    t.title = title || TICK_TITLE[state] || '';
    t.innerHTML = TICK[state] || '';
}
// Who has seen each of my messages — redrawn after every poll
function ticksUpdate() {
    body.querySelectorAll('.message-bubble.mine[data-id]').forEach(el => {
        const id = +el.dataset.id;
        const seenBy = READS.filter(r => r.seen >= id);
        if (!seenBy.length) setTick(el, 'sent');
        else setTick(el, seenBy.length === READS.length ? 'seen-all' : 'seen', 'Görenler: ' + seenBy.map(r => r.name).join(', '));
    });
}

let lastAt = null;
function bubbleAdd(m, state) {
    const at = chatDate(m.at);
    // a time stamp when the conversation picks up again after an hour or more, and on every new day
    if (!lastAt || Math.abs(at - lastAt) >= 3600e3 || at.toDateString() !== lastAt.toDateString()) {
        const s = document.createElement('div');
        s.className = 'chat-stamp';
        s.textContent = stampLabel(at);
        body.appendChild(s);
    }
    lastAt = at;
    const d = document.createElement('div');
    d.className = 'message-bubble' + (m.mine ? ' mine' : '');
    if (m.id) d.dataset.id = m.id;
    d.innerHTML = `<div class="message-sender">${esc(m.name)}</div><div class="message-text">${m.html}</div>`
        + `<div class="message-time" title="${fullDate(at)}">${hm(at)}${m.mine ? '<span class="msg-ticks"></span>' : ''}</div>`;
    body.appendChild(d);
    if (m.mine) setTick(d, state || 'sent');
    body.scrollTop = body.scrollHeight;
    return d;
}

// Sending: the message is on screen at once (clock), gets its tick when the server has it; a failed one can be sent again
async function messageSend(message, mentions, el) {
    if (!el) el = bubbleAdd({ mine: true, name: 'Siz', at: nowStamp(), html: esc(message).replace(/\n/g, '<br>') }, 'sending');
    el.classList.add('sending'); el.classList.remove('failed');
    el.querySelector('.msg-retry')?.remove();
    setTick(el, 'sending');
    const j = await api('message_send', { channel_id: channelId, message, mention_ids: mentions });
    el.classList.remove('sending');
    if (!j.ok) {
        el.classList.add('failed');
        setTick(el, 'failed', 'Gönderilemedi');
        const r = document.createElement('button');
        r.type = 'button'; r.className = 'msg-retry'; r.textContent = 'Gönderilemedi · Tekrar dene';
        r.onclick = () => messageSend(message, mentions, el);
        el.appendChild(r);
        return;
    }
    el.dataset.id = j.message.id;
    el.querySelector('.message-text').innerHTML = j.message.html;
    lastId = Math.max(lastId, j.message.id);
    ticksUpdate();
}

const form = document.getElementById('messageForm');
const input = document.getElementById('messageInput');
if (form) {
    // drawn once app.js (esc, toast) has loaded at the end of the page
    addEventListener('DOMContentLoaded', () => {
        <?= json_encode(array_map(fn($m) => chat_message_out($m, (int)$u['id']), $messages), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>.forEach(m => bubbleAdd(m));
        ticksUpdate();
    });
    form.addEventListener('submit', e => {
        e.preventDefault();
        const message = input.value.trim(); if (!message) return;
        const mentionField = document.getElementById('messageMention');
        const mentions = mentionField.value || '[]';
        input.value = ''; mentionField.value = '';
        messageSend(message, mentions);
    });
    // Tapping the ticks says who has seen the message (phones have no hover)
    body.addEventListener('click', e => { const t = e.target.closest('.msg-ticks'); if (t && t.title) toast(t.title, 'info', 2200); });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey && !document.querySelector('.mention-dropdown')) { e.preventDefault(); form.requestSubmit(); }
    });
}

// New messages and who has seen what (polling — next round only after the previous answer, see sadaPoll).
// app.js defines sadaPoll and loads at the end of the body, so wait for it.
if (channelId) addEventListener('DOMContentLoaded', () => sadaPoll(4000, async () => {
    const j = await api('message_fetch', { channel_id: channelId, last_id: lastId });
    if (!j.ok) throw new Error('poll');
    j.messages.forEach(m => {
        lastId = Math.max(lastId, m.id);
        if (body.querySelector(`.message-bubble[data-id="${m.id}"]`)) return;
        // my own message still on its way: the send answer finishes that bubble
        if (m.mine && body.querySelector('.message-bubble.sending')) return;
        bubbleAdd(m);
    });
    READS = j.reads;
    ticksUpdate();
}));

// Channel member selection
const channelForm = document.getElementById('channelForm');
if (channelForm) channelForm.addEventListener('submit', () => {
    const selected = Array.from(document.querySelectorAll('.channelMember:checked')).map(c => c.value);
    document.getElementById('channelMembers').value = JSON.stringify(selected);
});

// Mobile back button
if (window.innerWidth <= 760) { const gb = document.getElementById('backBtn'); if (gb) gb.style.display = 'flex'; }

// Rename the chat
async function channelNameChange() {
    const newName = prompt('Yeni sohbet adı:', <?= json_encode($activeChannel['name'] ?? '', JSON_UNESCAPED_UNICODE) ?>);
    if (!newName || !newName.trim()) return;
    const j = await api('channel_name', { channel_id: channelId, name: newName.trim() });
    if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 550); }
}
</script>
<?php page_end(); ?>
