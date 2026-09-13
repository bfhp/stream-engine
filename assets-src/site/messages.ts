/* ==========================================================================
   Messenger controller
   ========================================================================== */

import { uploadFile } from "../shared/uploads";
import { bytesToLabel } from "../shared/bytes";
import { trans, transChoiceWithCount } from "../shared/i18n";
import {
    avatarColor,
    computeReadStatus as deriveReadStatus,
    formatConvTime,
    formatDayLabel,
    formatTime,
    hardenMessageLinks,
    initialsFromName,
    stripTags,
} from "./messenger-format";

type ApiLastMessage = {
    id: number;
    text: string;
    user_id: number;
    user_name: string;
    created_at: number;
};

type ApiConversation = {
    id: number;
    is_group: boolean;
    title: string;
    other_user_id: number | null;
    other_user_avatar: string | null;
    // null for groups - presence is only shown for the other side of a
    // direct chat, derived server-side from user_sessions activity.
    other_user_online: boolean | null;
    unread_count: number;
    last_message: ApiLastMessage | null;
};

type ApiParticipant = {
    id: number;
    displayName: string;
    avatarUrl: string;
    online: boolean;
};

type ApiConversationDetail = {
    id: number;
    is_group: boolean;
    title: string;
    other_user_id: number | null;
    other_user_online: boolean | null;
    participants: ApiParticipant[];
    last_message_id: number;
    last_read_message_id: number;
    unread_count: number;
};

type ApiMessageReply = {
    id: number;
    user_id: number;
    text: string;
};

type ApiAttachment = {
    id: number;
    url: string;
    mime: string;
    size: number;
    original_name: string;
};

type ApiMessage = {
    id: number;
    user_id: number;
    text: string;
    created_at: number;
    edited: boolean;
    reply: ApiMessageReply | null;
    attachment: ApiAttachment | null;
};

type ReplyTarget = {
    id: number;
    userId: number;
    text: string;
};

type PendingAttachment = {
    uploadId: number;
    mime: string;
    name: string;
    size: number;
    previewUrl: string;
};

type ApiMessagesResponse = {
    messages: ApiMessage[];
    // Messages already seen (so `messages` above, filtered by id > afterId,
    // won't return them again) that were edited since - how an edit made by
    // one participant reaches everyone else's already-open view.
    edited: ApiMessage[];
    // Same idea for (soft-)deletes: ids to remove from the view.
    deleted_ids: number[];
    typing: number[];
    // user_id -> last_read_message_id for every participant. Derived from
    // conversation_participants, not a per-message read log.
    read_states: Record<number, number>;
    // ids of this conversation's participants currently online (user_sessions
    // activity within the last ~90s). Rides the existing message poll so the
    // chat header / drawer dots refresh without a dedicated presence poll.
    online_user_ids: number[];
    server_time_ms: number;
};

type ApiContact = {
    id: number;
    displayName: string;
    avatarUrl: string;
    createdAt: number;
};

type ApiContactsResponse = {
    data: ApiContact[];
};

type PanelState = 'chat' | 'empty' | 'new';
type NewTab = 'direct' | 'group';

// Mirrors MessageService::edit()'s 900-second window on the backend - purely
// cosmetic here (hides the edit button once it'd just get rejected), the
// server is the actual authority.
const EDIT_WINDOW_SECONDS = 900;

// Key under which the open conversation lives in the address bar's fragment
// (#c=Xk3f9) - see site/hash-route.ts. The value is the conversation id run
// through CMS.encodeId(), so the URL doesn't spell out row numbers.
const HASH_CONVERSATION_KEY = 'c';

export function initMessages() {
    const cms = window.CMS;
    if (!cms) return;

    const root = document.querySelector<HTMLElement>('[data-messages-app]');
    if (!root) return;

    const API = '/api/v1/conversations';
    const USERS_API = '/api/v1/users';

    const currentUserId = parseInt(root.dataset.currentUserId ?? '0', 10);

    /* ===============================
       Element refs
    =============================== */

    const searchInput = root.querySelector<HTMLInputElement>('[data-messages-search]');
    const conversationsEl = root.querySelector<HTMLElement>('[data-messages-conversations]')!;
    const newOpenBtn = root.querySelector<HTMLButtonElement>('[data-messages-new-open]');
    const emptyNewBtn = root.querySelector<HTMLButtonElement>('[data-messages-empty-new]');

    const chatPanel = root.querySelector<HTMLElement>('[data-messages-chat-panel]')!;
    const emptyPanel = root.querySelector<HTMLElement>('[data-messages-empty]')!;
    const newPanel = root.querySelector<HTMLElement>('[data-messages-new-panel]')!;

    const chatAvatarEl = root.querySelector<HTMLElement>('[data-chat-avatar]')!;
    const chatTitleEl = root.querySelector<HTMLElement>('[data-chat-title]')!;
    const chatSubtitleEl = root.querySelector<HTMLElement>('[data-chat-subtitle]')!;
    const chatInfoToggle = root.querySelector<HTMLElement>('[data-chat-info-toggle]')!;
    const chatPromoteBtn = root.querySelector<HTMLButtonElement>('[data-chat-promote]')!;
    const chatInfoBtn = root.querySelector<HTMLButtonElement>('[data-chat-info-btn]')!;
    const backBtn = root.querySelector<HTMLButtonElement>('[data-messages-back]');

    const messagesListEl = root.querySelector<HTMLElement>('[data-messages-list]')!;
    const typingEl = root.querySelector<HTMLElement>('[data-messages-typing]')!;
    const inputEl = root.querySelector<HTMLTextAreaElement>('[data-message-input]')!;
    const sendBtn = root.querySelector<HTMLButtonElement>('[data-messages-send]')!;

    const replyPreviewEl = root.querySelector<HTMLElement>('[data-reply-preview]')!;
    const replyPreviewNameEl = root.querySelector<HTMLElement>('[data-reply-preview-name]')!;
    const replyPreviewTextEl = root.querySelector<HTMLElement>('[data-reply-preview-text]')!;
    const replyCancelBtn = root.querySelector<HTMLButtonElement>('[data-reply-cancel]')!;

    const editPreviewEl = root.querySelector<HTMLElement>('[data-edit-preview]')!;
    const editCancelBtn = root.querySelector<HTMLButtonElement>('[data-edit-cancel]')!;

    const attachBtn = root.querySelector<HTMLButtonElement>('[data-messages-attach]')!;
    const attachInput = root.querySelector<HTMLInputElement>('[data-attach-input]')!;
    const attachPreviewEl = root.querySelector<HTMLElement>('[data-attach-preview]')!;
    const attachPreviewThumbEl = root.querySelector<HTMLElement>('[data-attach-preview-thumb]')!;
    const attachPreviewNameEl = root.querySelector<HTMLElement>('[data-attach-preview-name]')!;
    const attachPreviewSizeEl = root.querySelector<HTMLElement>('[data-attach-preview-size]')!;
    const attachCancelBtn = root.querySelector<HTMLButtonElement>('[data-attach-cancel]')!;

    const newCloseBtn = root.querySelector<HTMLButtonElement>('[data-messages-new-close]');
    const newTabButtons = root.querySelectorAll<HTMLButtonElement>('[data-new-tab]');
    const newGroupFields = root.querySelector<HTMLElement>('[data-new-group-fields]')!;
    const newGroupTitleInput = root.querySelector<HTMLInputElement>('[data-new-group-title]')!;
    const newGroupChipsEl = root.querySelector<HTMLElement>('[data-new-group-chips]')!;
    const newContactsSearchInput = root.querySelector<HTMLInputElement>('[data-new-contacts-search]')!;
    const newContactsListEl = root.querySelector<HTMLElement>('[data-new-contacts-list]')!;
    const newGroupFooter = root.querySelector<HTMLElement>('[data-new-group-footer]')!;
    const newGroupCreateBtn = root.querySelector<HTMLButtonElement>('[data-new-group-create]')!;

    const groupBackdrop = root.querySelector<HTMLElement>('[data-group-backdrop]')!;
    const groupDrawer = root.querySelector<HTMLElement>('[data-group-drawer]')!;
    const groupCloseBtn = root.querySelector<HTMLButtonElement>('[data-group-close]')!;
    const groupTitleEl = root.querySelector<HTMLElement>('[data-group-title]')!;
    const groupCountEl = root.querySelector<HTMLElement>('[data-group-count]')!;
    const groupMembersEl = root.querySelector<HTMLElement>('[data-group-members]')!;
    const groupAddToggleBtn = root.querySelector<HTMLButtonElement>('[data-group-add-toggle]')!;
    const groupAddPanel = root.querySelector<HTMLElement>('[data-group-add-panel]')!;
    const groupAddSearchInput = root.querySelector<HTMLInputElement>('[data-group-add-search]')!;
    const groupAddListEl = root.querySelector<HTMLElement>('[data-group-add-list]')!;
    const groupLeaveBtn = root.querySelector<HTMLButtonElement>('[data-group-leave]')!;

    /* ===============================
       State
    =============================== */

    let conversations: ApiConversation[] = [];
    let searchQuery = '';

    let activeId: number | null = null;
    let activeDetail: ApiConversationDetail | null = null;
    let activeParticipants: Record<number, ApiParticipant> = {};
    let lastMessageId = 0;
    // user_id -> last_read_message_id, refreshed on every load/poll of the
    // active conversation. Read ticks on own messages are computed from this
    // plus activeParticipants - no separate per-message read data needed.
    let readStates: Record<number, number> = {};

    let panel: PanelState = 'empty';

    let newTab: NewTab = 'direct';
    const selectedGroupIds = new Map<number, string>();
    let groupAddOpen = false;

    let messagesPollTimer: number | null = null;
    // Whether a GET .../messages is currently out. The interval skips its
    // tick while one is (a second identical request buys nothing and only
    // widens the window for two responses to overlap); sendMessage()'s own
    // poll is never skipped, since it has to run after its POST to pick up
    // what was just sent.
    let pollInFlight = false;
    let conversationsPollTimer: number | null = null;
    let lastTypingSentAt = 0;

    let replyTarget: ReplyTarget | null = null;
    let editingMessageId: number | null = null;

    let pendingAttachment: PendingAttachment | null = null;
    let attachUploading = false;

    // serverNow - clientNow (ms), refreshed on every load/poll from
    // server_time_ms - lets the edit-window check use the server's clock
    // instead of the visitor's, which may be skewed.
    let serverTimeOffsetMs = 0;

    function serverNowSeconds(): number {
        return (Date.now() + serverTimeOffsetMs) / 1000;
    }

    let lastRenderedUserId: number | null = null;
    let lastRenderedDay: string | null = null;
    const renderedMessages = new Map<number, ApiMessage>();

    const contactsSearchTimerRef = { current: null as number | null };
    const groupAddSearchTimerRef = { current: null as number | null };

    /* ===============================
       Small helpers
    =============================== */

    function renderAvatar(opts: { isGroup: boolean; id?: number; name?: string; size?: 'sm' | 'md' | 'lg'; online?: boolean | null }): string {
        const sizeClass = opts.size ? ` msgr-avatar-${opts.size}` : '';
        const inner = opts.isGroup
            ? `<span class="msgr-avatar msgr-avatar-group${sizeClass}"><i class="bi bi-people-fill"></i></span>`
            : `<span class="msgr-avatar${sizeClass}" style="background:${avatarColor(opts.id ?? 0)}">${cms.escapeHtml(initialsFromName(opts.name ?? ''))}</span>`;

        // online is only passed where presence is meaningful (direct chats,
        // group members) - omitting it keeps existing call sites (message
        // bubbles) unchanged, no dot.
        if (opts.online === undefined || opts.online === null) return inner;

        return `<span class="msgr-avatar-wrap">${inner}<span class="msgr-avatar-status${opts.online ? ' is-online' : ''}"></span></span>`;
    }

    function debounce(fn: () => void, wait: number, timerRef: { current: number | null }) {
        if (timerRef.current) window.clearTimeout(timerRef.current);
        timerRef.current = window.setTimeout(fn, wait);
    }

    /* ===============================
       Panel / view state
    =============================== */

    /**
     * The class on the root is the whole state - it used to also be mirrored
     * into a `mobileShowContent` variable that nothing ever read, which is what
     * noUnusedLocals turned up. If something needs to ask later,
     * `root.classList.contains('is-chat-active')` is the single source rather
     * than a copy that can drift.
     */
    function setMobileContentVisible(visible: boolean) {
        root!.classList.toggle('is-chat-active', visible);
    }

    function renderPanels() {
        chatPanel.hidden = panel !== 'chat';
        emptyPanel.hidden = panel !== 'empty';
        newPanel.hidden = panel !== 'new';
    }

    /* ===============================
       Conversations list
    =============================== */

    async function loadConversations() {
        try {
            conversations = await cms.api<ApiConversation[]>(API);
        } catch (e) {
            return;
        }
        renderConversations();
    }

    function renderConversations() {
        const q = searchQuery.trim().toLowerCase();
        const filtered = q ? conversations.filter(c => c.title.toLowerCase().includes(q)) : conversations;

        if (!filtered.length) {
            conversationsEl.innerHTML = `<div class="msgr-conv-empty">${trans(q ? 'js.messenger.no_results' : 'js.messenger.no_conversations')}</div>`;
            return;
        }

        conversationsEl.innerHTML = filtered.map(renderConvRow).join('');
    }

    function renderConvRow(c: ApiConversation): string {
        const active = c.id === activeId && panel === 'chat';
        const avatarHtml = renderAvatar({ isGroup: c.is_group, id: c.other_user_id ?? c.id, name: c.title, online: c.other_user_online });

        let prefixHtml = '';
        let text = '';
        let timeText = '';

        if (c.last_message) {
            text = c.last_message.text;
            timeText = formatConvTime(c.last_message.created_at);
            const prefix = c.is_group
                ? `${c.last_message.user_name}: `
                : (c.last_message.user_id === currentUserId ? trans('js.messenger.you_prefix') : '');
            if (prefix) prefixHtml = `<span class="msgr-conv-preview-prefix">${cms.escapeHtml(prefix)}</span>`;
        }

        return `
            <div class="msgr-conv-row${active ? ' is-active' : ''}" data-conv-id="${c.id}">
                <div class="msgr-conv-avatar-wrap">${avatarHtml}</div>
                <div class="msgr-conv-body">
                    <div class="msgr-conv-top">
                        <span class="msgr-conv-title">${cms.escapeHtml(c.title)}</span>
                        <span class="msgr-conv-time">${timeText}</span>
                    </div>
                    <div class="msgr-conv-bottom">
                        <span class="msgr-conv-preview">${prefixHtml}${cms.escapeHtml(stripTags(text))}</span>
                        ${c.unread_count > 0 ? `<span class="msgr-badge">${c.unread_count > 99 ? '99+' : c.unread_count}</span>` : ''}
                    </div>
                </div>
            </div>`;
    }

    conversationsEl.addEventListener('click', (event) => {
        const target = event.target as HTMLElement;
        const row = target.closest<HTMLElement>('[data-conv-id]');
        if (!row) return;
        const id = parseInt(row.dataset.convId ?? '0', 10);
        if (id) void openConversation(id);
    });

    searchInput?.addEventListener('input', () => {
        searchQuery = searchInput.value;
        renderConversations();
    });

    /* ===============================
       Address bar
    =============================== */

    /**
     * Points #c= at a conversation, adding a history entry so Back/Forward
     * step through the chats that were opened.
     */
    function writeConversationHash(id: number) {
        const code = cms.encodeId(id);
        if (!code) return;

        cms.hashRoute.set(HASH_CONVERSATION_KEY, code);
    }

    /**
     * Drops #c= without a history entry - used when the conversation is
     * gone (left a group), where a Back into it would only 404.
     */
    function clearConversationHash() {
        cms.hashRoute.set(HASH_CONVERSATION_KEY, null, { replace: true });
    }

    function conversationIdFromHash(params: Record<string, string>): number | null {
        return cms.decodeId(params[HASH_CONVERSATION_KEY] ?? null);
    }

    /**
     * Brings the messenger in line with whatever the URL says - the single
     * path for Back/Forward and for the initial deep link, so a chat opened
     * by navigation goes through exactly the same code as one opened by
     * click.
     *
     * openConversation() writing the hash back doesn't fight this: the value
     * it writes is the one already in the address bar, and hash-route drops
     * writes that change nothing rather than stacking a duplicate history
     * entry.
     */
    async function applyHash(params: Record<string, string>) {
        const id = conversationIdFromHash(params);

        if (id === null) {
            // No (or unreadable) code: the state being navigated back to is
            // "nothing open".
            if (activeId !== null || panel !== 'empty') closeConversation();
            return;
        }

        // Already showing it - re-opening would needlessly re-fetch the
        // whole thread and scroll it back to the unread line.
        if (id === activeId && panel === 'chat') return;

        await openConversation(id);
    }

    cms.hashRoute.onChange(({ source, params }) => {
        if (source !== 'navigation') return;
        void applyHash(params);
    });

    /* ===============================
       Open conversation / chat header
    =============================== */

    async function openConversation(id: number) {
        activeId = id;
        panel = 'chat';
        setMobileContentVisible(true);
        renderPanels();
        renderConversations();
        closeGroupInfo();

        messagesListEl.innerHTML = '';
        typingEl.hidden = true;
        lastMessageId = 0;
        lastRenderedUserId = null;
        lastRenderedDay = null;
        renderedMessages.clear();
        readStates = {};

        chatTitleEl.textContent = '';
        chatSubtitleEl.textContent = '';
        chatAvatarEl.innerHTML = '';
        cancelReply();
        cancelAttachment();
        cancelEdit();

        stopMessagesPoll();

        let detail: ApiConversationDetail;
        try {
            detail = await cms.api<ApiConversationDetail>(`${API}/${id}`);
        } catch (e) {
            // Reachable from the address bar now that navigation opens
            // chats: a stale or shared link, a hand-edited hash, a group
            // this account was since removed from. Fall back to the empty
            // panel and drop the code instead of leaving a blank chat that
            // will never fill in.
            if (activeId === id) {
                closeConversation();
                clearConversationHash();
                cms.toast({ message: trans('js.messenger.unavailable'), type: 'danger' });
            }
            return;
        }

        if (activeId !== id) return;

        // Only once the conversation is known to exist and be ours - a
        // failed fetch above leaves the URL on whatever was open before
        // rather than pointing at a chat that never opened.
        writeConversationHash(id);

        activeDetail = detail;
        activeParticipants = {};
        detail.participants.forEach(p => { activeParticipants[p.id] = p; });

        renderChatHeader(detail);

        const unreadFromId = detail.last_read_message_id;

        await loadMessages(unreadFromId);
        startMessagesPoll();

        if (lastMessageId > 0) {
            void cms.api(`${API}/${id}/read`, { method: 'POST', data: { message_id: lastMessageId } });
            const conv = conversations.find(c => c.id === id);
            if (conv) {
                conv.unread_count = 0;
                renderConversations();
            }
        }
    }

    /**
     * Tears the chat panel back down to "nothing open" - the inverse of
     * openConversation(), and what both a Back out of every chat and losing
     * access to the open one land on. Leaves the URL alone: the caller
     * knows whether the hash is already gone (navigation) or still needs
     * clearing (left a group, conversation unavailable).
     */
    function closeConversation() {
        stopMessagesPoll();
        closeGroupInfo();

        activeId = null;
        activeDetail = null;
        activeParticipants = {};

        messagesListEl.innerHTML = '';
        typingEl.hidden = true;
        lastMessageId = 0;
        lastRenderedUserId = null;
        lastRenderedDay = null;
        renderedMessages.clear();
        readStates = {};

        chatTitleEl.textContent = '';
        chatSubtitleEl.textContent = '';
        chatAvatarEl.innerHTML = '';
        cancelReply();
        cancelAttachment();
        cancelEdit();

        panel = 'empty';
        renderPanels();
        renderConversations();
        setMobileContentVisible(false);
    }

    /**
     * Prefer the live value from activeParticipants (kept fresh by the
     * messages poll's online_user_ids) over the snapshot taken when the
     * conversation was opened, so the header dot doesn't go stale for the
     * whole time a chat stays open.
     */
    function otherUserOnline(detail: ApiConversationDetail): boolean | null {
        if (detail.is_group || detail.other_user_id === null) return null;
        return activeParticipants[detail.other_user_id]?.online ?? detail.other_user_online;
    }

    function renderChatHeader(detail: ApiConversationDetail) {
        chatAvatarEl.innerHTML = renderAvatar({ isGroup: detail.is_group, id: detail.other_user_id ?? detail.id, name: detail.title, size: 'md', online: otherUserOnline(detail) });
        chatTitleEl.textContent = detail.title;

        if (detail.is_group) {
            chatSubtitleEl.textContent = transChoiceWithCount('js.common.participant', detail.participants.length);
            chatSubtitleEl.style.display = '';
            chatInfoToggle.style.cursor = 'pointer';
            chatPromoteBtn.hidden = true;
            chatInfoBtn.hidden = false;
        } else {
            chatSubtitleEl.textContent = '';
            chatSubtitleEl.style.display = 'none';
            chatInfoToggle.style.cursor = 'default';
            chatPromoteBtn.hidden = false;
            chatInfoBtn.hidden = true;
        }
    }

    chatInfoToggle.addEventListener('click', () => {
        if (activeDetail?.is_group) openGroupInfo();
    });
    chatInfoBtn.addEventListener('click', openGroupInfo);
    chatPromoteBtn.addEventListener('click', () => {
        if (!activeDetail || activeDetail.is_group) return;
        const otherId = activeDetail.other_user_id;
        const otherName = activeDetail.title;
        openNewPanel('group');
        if (otherId) {
            selectedGroupIds.set(otherId, otherName);
            renderGroupChips();
            renderNewContactsList();
        }
    });

    backBtn?.addEventListener('click', () => setMobileContentVisible(false));

    /* ===============================
       Messages
    =============================== */

    async function loadMessages(unreadFromId: number) {
        if (!activeId) return;

        let data: ApiMessagesResponse;
        try {
            data = await cms.api<ApiMessagesResponse>(`${API}/${activeId}/messages?after_id=0`);
        } catch (e) {
            return;
        }
        if (activeId === null) return;

        serverTimeOffsetMs = data.server_time_ms - Date.now();
        readStates = data.read_states;
        applyPresence(data.online_user_ids);
        messagesListEl.innerHTML = `<div class="msgr-messages-inner">${buildMessagesHtml(data.messages, unreadFromId)}</div>`;
        hardenMessageLinks(messagesListEl);
        data.messages.forEach(m => {
            if (m.id > lastMessageId) lastMessageId = m.id;
            renderedMessages.set(m.id, m);
        });

        renderTyping(data.typing);
        scrollMessagesToBottom();
    }

    async function pollMessages() {
        if (!activeId) return;
        const id = activeId;

        let data: ApiMessagesResponse;
        pollInFlight = true;
        try {
            data = await cms.api<ApiMessagesResponse>(`${API}/${id}/messages?after_id=${lastMessageId}`);
        } catch (e) {
            return;
        } finally {
            pollInFlight = false;
        }
        if (activeId !== id) return;

        serverTimeOffsetMs = data.server_time_ms - Date.now();
        readStates = data.read_states;
        applyPresence(data.online_user_ids);
        applyMessageChanges(data);

        // A response can legitimately carry something that's already on
        // screen: sendMessage()'s own poll and the interval one can be in
        // flight at the same moment, both asking for "everything after
        // <id>" from before the message existed, and both then come back
        // carrying it - which is how a sent message appeared twice.
        // renderedMessages is the record of what the DOM already holds, so
        // gate on that rather than assuming a response is all-new.
        const fresh = data.messages.filter(m => !renderedMessages.has(m.id));

        if (fresh.length) {
            appendMessages(fresh);
            fresh.forEach(m => {
                if (m.id > lastMessageId) lastMessageId = m.id;
                renderedMessages.set(m.id, m);
            });

            void cms.api(`${API}/${id}/read`, { method: 'POST', data: { message_id: lastMessageId } });
            const conv = conversations.find(c => c.id === id);
            if (conv) {
                const newest = fresh[fresh.length - 1];
                conv.unread_count = 0;
                conv.last_message = {
                    id: lastMessageId,
                    text: newest.text,
                    user_id: newest.user_id,
                    user_name: newest.user_id === currentUserId
                        ? trans('js.common.you')
                        : (activeParticipants[newest.user_id]?.displayName ?? ''),
                    created_at: newest.created_at,
                };
                renderConversations();
            }
        } else {
            // No new messages, but the other side may have just caught up
            // to what's already on screen - refresh ticks on our own
            // messages without touching the DOM otherwise.
            updateReadTicks();
        }

        renderTyping(data.typing);
    }

    function buildMessagesHtml(messages: ApiMessage[], unreadFromId: number): string {
        let html = '';
        let prevUserId: number | null = null;
        let prevDay: string | null = null;
        let unreadShown = false;

        messages.forEach((m) => {
            const dayLabel = formatDayLabel(m.created_at);
            const isOwn = m.user_id === currentUserId;
            const showDaySep = dayLabel !== prevDay;
            const showUnreadSep = !unreadShown && !isOwn && unreadFromId > 0 && m.id > unreadFromId;
            if (showUnreadSep) unreadShown = true;

            const startGroup = m.user_id !== prevUserId || showDaySep || showUnreadSep;
            prevUserId = m.user_id;
            prevDay = dayLabel;

            html += renderMessageBlock(m, isOwn, startGroup, showDaySep ? dayLabel : null, showUnreadSep);
        });

        lastRenderedUserId = prevUserId;
        lastRenderedDay = prevDay;

        return html;
    }

    /**
     * A message counts as read once every OTHER participant's
     * last_read_message_id has caught up to it - derived straight from
     * conversation_participants via readStates, no per-message read log.
     * Same rule for direct and group chats: "read" just means "read by all".
     */
    function computeReadStatus(m: ApiMessage): boolean | null {
        // The "is a conversation even open" guard stays here - it is state,
        // not a rule about read receipts. Everything below it is
        // messenger-format.ts's deriveReadStatus().
        if (!activeDetail) return null;

        return deriveReadStatus(
            m,
            currentUserId,
            Object.keys(activeParticipants).map(Number),
            readStates,
        );
    }

    function updateReadTicks() {
        renderedMessages.forEach((m, id) => {
            const read = computeReadStatus(m);
            if (read === null) return;

            const el = messagesListEl.querySelector<HTMLElement>(`[data-message-id="${id}"] [data-read-status]`);
            if (!el) return;

            el.className = `bi msgr-read-status ${read ? 'bi-check-all is-read' : 'bi-check'}`;
            el.title = trans(read ? 'js.messenger.read' : 'js.messenger.sent');
        });
    }

    /**
     * Keeps activeParticipants' online flags fresh from the messages poll's
     * online_user_ids and redraws whatever's currently showing them (chat
     * header, group drawer if open) - no dedicated presence poll needed.
     */
    function applyPresence(onlineIds: number[]) {
        const onlineSet = new Set(onlineIds);
        let changed = false;

        Object.values(activeParticipants).forEach(p => {
            const online = onlineSet.has(p.id);
            if (p.online !== online) changed = true;
            p.online = online;
        });

        if (!changed || !activeDetail) return;

        renderChatHeader(activeDetail);
        if (!groupDrawer.hidden) renderGroupDrawer();
    }

    /**
     * Applies an edit or delete made by another participant (or by this one,
     * from a different tab) to messages already rendered on screen - see
     * MessageRepository::getRecentlyChanged() for why a normal poll (by id >
     * afterId) can't pick these up on its own.
     */
    function applyMessageChanges(data: ApiMessagesResponse) {
        data.edited.forEach(m => {
            renderedMessages.set(m.id, m);
            patchMessageInPlace(m);
        });
        data.deleted_ids.forEach(id => removeMessageFromView(id));
    }

    function renderAttachment(a: ApiAttachment): string {
        if (a.mime.startsWith('image/')) {
            return `
                <a class="msgr-attachment-image-link" href="${a.url}" target="_blank" rel="noopener">
                    <img class="msgr-attachment-image" src="${a.url}" alt="${cms.escapeHtml(a.original_name)}" loading="lazy">
                </a>`;
        }
        if (a.mime.startsWith('audio/')) {
            return `<audio class="msgr-attachment-audio" controls preload="none" src="${a.url}"></audio>`;
        }
        return `
            <a class="msgr-attachment-file" href="${a.url}" download="${cms.escapeHtml(a.original_name)}" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-arrow-down-fill"></i>
                <span class="msgr-attachment-file-body">
                    <span class="msgr-attachment-file-name">${cms.escapeHtml(a.original_name)}</span>
                    <span class="msgr-attachment-file-size">${bytesToLabel(a.size)}</span>
                </span>
            </a>`;
    }

    /**
     * Hover toolbar on a bubble: reply always, edit only for your own
     * messages still inside the server's edit window, delete for any of
     * your own messages (the backend has no time limit on delete).
     */
    function renderMessageActions(m: ApiMessage, isOwn: boolean): string {
        const canEdit = isOwn && serverNowSeconds() - m.created_at < EDIT_WINDOW_SECONDS;

        return `
            <div class="msgr-msg-actions">
                <button type="button" class="msgr-msg-action-btn" data-reply-to="${m.id}" title="${trans('js.common.reply')}">
                    <i class="bi bi-reply-fill"></i>
                </button>
                ${canEdit ? `
                <button type="button" class="msgr-msg-action-btn" data-edit-message="${m.id}" title="${trans('js.common.edit')}">
                    <i class="bi bi-pencil-fill"></i>
                </button>` : ''}
                ${isOwn ? `
                <button type="button" class="msgr-msg-action-btn msgr-msg-action-btn-danger" data-delete-message="${m.id}" title="${trans('js.common.delete')}">
                    <i class="bi bi-trash-fill"></i>
                </button>` : ''}
            </div>`;
    }

    /**
     * Everything inside `.msgr-bubble` - factored out of renderMessageBlock()
     * so patchMessageInPlace() can redraw just this part in place (for a
     * live edit) without recomputing the surrounding day-separator/grouping
     * context, which only makes sense at the point the message is first
     * inserted.
     */
    function renderBubbleContent(m: ApiMessage, isOwn: boolean): string {
        let replyHtml = '';
        if (m.reply) {
            const replyName = m.reply.user_id === currentUserId
                ? trans('js.common.you')
                : (activeParticipants[m.reply.user_id]?.displayName ?? `#${m.reply.user_id}`);
            replyHtml = `
                <div class="msgr-bubble-reply">
                    <span class="msgr-bubble-reply-name">${cms.escapeHtml(replyName)}</span>
                    <span class="msgr-bubble-reply-text">${cms.escapeHtml(stripTags(m.reply.text))}</span>
                </div>`;
        }

        const readStatus = isOwn ? computeReadStatus(m) : null;
        const readStatusHtml = readStatus !== null
            ? `<i class="bi msgr-read-status ${readStatus ? 'bi-check-all is-read' : 'bi-check'}" data-read-status title="${trans(readStatus ? 'js.messenger.read' : 'js.messenger.sent')}"></i>`
            : '';

        const attachmentHtml = m.attachment ? renderAttachment(m.attachment) : '';

        return `
            ${renderMessageActions(m, isOwn)}
            ${replyHtml}
            ${attachmentHtml}
            ${m.text ? `<span class="msgr-bubble-text">${m.text}</span>` : ''}
            <span class="msgr-bubble-meta">${m.edited ? `<span class="msgr-edited">${trans('js.messenger.edited')}</span>` : ''}<span>${formatTime(m.created_at)}</span>${readStatusHtml}</span>`;
    }

    function renderMessageBlock(m: ApiMessage, isOwn: boolean, startGroup: boolean, dayLabel: string | null, showUnreadSep: boolean): string {
        let html = '';

        if (dayLabel) {
            html += `<div class="msgr-day-sep"><span>${cms.escapeHtml(dayLabel)}</span></div>`;
        }
        if (showUnreadSep) {
            html += `<div class="msgr-unread-sep"><span class="msgr-unread-line"></span><span class="msgr-unread-label">${trans('js.messenger.new_messages')}</span><span class="msgr-unread-line"></span></div>`;
        }

        const sender = activeParticipants[m.user_id];
        const senderName = isOwn ? trans('js.common.you') : (sender?.displayName ?? `#${m.user_id}`);
        const showAvatar = startGroup && !isOwn;
        const showName = startGroup && !!activeDetail?.is_group && !isOwn;

        const avatarHtml = showAvatar
            ? renderAvatar({ isGroup: false, id: m.user_id, name: senderName, size: 'sm' })
            : '';

        const isImageAttachment = m.attachment?.mime.startsWith('image/') ?? false;

        html += `
            <div class="msgr-msg-group ${isOwn ? 'is-own' : 'is-other'}${startGroup ? ' is-grouped' : ''}" data-message-id="${m.id}">
                <div class="msgr-msg-row">
                    <div class="msgr-msg-avatar-slot">${avatarHtml}</div>
                    <div class="msgr-msg-col">
                        ${showName ? `<div class="msgr-msg-name-row"><span class="msgr-msg-name" style="color:${avatarColor(m.user_id)}">${cms.escapeHtml(senderName)}</span></div>` : ''}
                        <div class="msgr-bubble${isImageAttachment ? ' has-attachment-image' : ''}">
                            ${renderBubbleContent(m, isOwn)}
                        </div>
                    </div>
                </div>
            </div>`;

        return html;
    }

    /**
     * Redraws an already-rendered message's bubble in place - used when an
     * edit or delete arrives via applyMessageChanges() (someone else's
     * change) or right after this user's own edit succeeds. Leaves the
     * day-separator/grouping/avatar around it untouched.
     */
    function patchMessageInPlace(m: ApiMessage) {
        const groupEl = messagesListEl.querySelector<HTMLElement>(`[data-message-id="${m.id}"]`);
        const bubbleEl = groupEl?.querySelector<HTMLElement>('.msgr-bubble');
        if (!groupEl || !bubbleEl) return;

        const isOwn = m.user_id === currentUserId;
        bubbleEl.classList.toggle('has-attachment-image', m.attachment?.mime.startsWith('image/') ?? false);
        bubbleEl.innerHTML = renderBubbleContent(m, isOwn);
        hardenMessageLinks(bubbleEl);
    }

    /**
     * Removes a deleted message from the view entirely (no "message
     * deleted" placeholder) and tidies up any composer state that was
     * pointing at it.
     */
    function removeMessageFromView(id: number) {
        messagesListEl.querySelector(`[data-message-id="${id}"]`)?.remove();
        renderedMessages.delete(id);
        if (editingMessageId === id) cancelEdit();
        if (replyTarget?.id === id) cancelReply();
    }

    function appendMessages(messages: ApiMessage[]) {
        let container = messagesListEl.querySelector('.msgr-messages-inner') as HTMLElement | null;
        if (!container) {
            container = document.createElement('div');
            container.className = 'msgr-messages-inner';
            messagesListEl.appendChild(container);
        }

        let prevUserId = lastRenderedUserId;
        let prevDay = lastRenderedDay;

        let html = '';
        messages.forEach((m) => {
            const dayLabel = formatDayLabel(m.created_at);
            const isOwn = m.user_id === currentUserId;
            const showDaySep = prevDay === null || dayLabel !== prevDay;
            const startGroup = m.user_id !== prevUserId || showDaySep;
            prevUserId = m.user_id;
            prevDay = dayLabel;

            html += renderMessageBlock(m, isOwn, startGroup, showDaySep ? dayLabel : null, false);
        });

        lastRenderedUserId = prevUserId;
        lastRenderedDay = prevDay;

        container.insertAdjacentHTML('beforeend', html);
        hardenMessageLinks(container);
        scrollMessagesToBottom();
    }

    function scrollMessagesToBottom() {
        requestAnimationFrame(() => {
            messagesListEl.scrollTop = messagesListEl.scrollHeight;
        });
    }

    messagesListEl.addEventListener('click', (event) => {
        const target = event.target as HTMLElement;

        const replyBtn = target.closest<HTMLButtonElement>('[data-reply-to]');
        if (replyBtn) {
            const id = parseInt(replyBtn.dataset.replyTo ?? '0', 10);
            const msg = renderedMessages.get(id);
            if (msg) startReply(msg.id, msg.user_id, msg.text);
            return;
        }

        const editBtn = target.closest<HTMLButtonElement>('[data-edit-message]');
        if (editBtn) {
            const id = parseInt(editBtn.dataset.editMessage ?? '0', 10);
            const msg = renderedMessages.get(id);
            if (msg) startEdit(msg.id, msg.text);
            return;
        }

        const deleteBtn = target.closest<HTMLButtonElement>('[data-delete-message]');
        if (deleteBtn) {
            const id = parseInt(deleteBtn.dataset.deleteMessage ?? '0', 10);
            if (id) confirmDeleteMessage(id);
        }
    });

    function confirmDeleteMessage(id: number) {
        cms.confirm({
            title: trans('js.messenger.delete_title'),
            message: trans('js.messenger.delete_confirm'),
            onConfirm: () => void deleteMessage(id),
        });
    }

    async function deleteMessage(id: number) {
        if (!activeId) return;

        try {
            await cms.api(`${API}/${activeId}/messages/${id}`, { method: 'DELETE' });
        } catch (e) {
            cms.toast({ message: trans('js.messenger.delete_failed'), type: 'danger' });
            return;
        }

        removeMessageFromView(id);
    }

    function renderTyping(userIds: number[]) {
        if (!userIds.length) {
            typingEl.hidden = true;
            typingEl.innerHTML = '';
            return;
        }

        const names = userIds.map(id => activeParticipants[id]?.displayName ?? `#${id}`);
        const label = trans(names.length === 1 ? 'js.messenger.typing_one' : 'js.messenger.typing_many', { names: names.join(', ') });

        typingEl.hidden = false;
        typingEl.innerHTML = `
            <div class="msgr-typing-dots"><span></span><span></span><span></span></div>
            <span>${cms.escapeHtml(label)}</span>`;
    }

    function startMessagesPoll() {
        stopMessagesPoll();
        messagesPollTimer = window.setInterval(() => {
            if (pollInFlight) return;
            void pollMessages();
        }, 3000);
    }

    function stopMessagesPoll() {
        if (messagesPollTimer !== null) {
            window.clearInterval(messagesPollTimer);
            messagesPollTimer = null;
        }
    }

    /* ===============================
       Composer
    =============================== */

    function updateSendButtonState() {
        const ready = editingMessageId !== null
            ? inputEl.value.trim().length > 0
            : (inputEl.value.trim().length > 0 || pendingAttachment !== null) && !attachUploading;
        sendBtn.classList.toggle('is-ready', ready);
        sendBtn.disabled = !ready;
    }

    function autoGrowInput() {
        inputEl.style.height = 'auto';
        inputEl.style.height = `${Math.min(inputEl.scrollHeight, 120)}px`;
    }

    function startReply(id: number, userId: number, text: string) {
        replyTarget = { id, userId, text };

        const name = userId === currentUserId ? trans('js.common.you') : (activeParticipants[userId]?.displayName ?? `#${userId}`);
        replyPreviewNameEl.textContent = name;
        replyPreviewTextEl.textContent = stripTags(text);
        replyPreviewEl.hidden = false;

        inputEl.focus();
    }

    function cancelReply() {
        replyTarget = null;
        replyPreviewEl.hidden = true;
        replyPreviewNameEl.textContent = '';
        replyPreviewTextEl.textContent = '';
    }

    replyCancelBtn.addEventListener('click', cancelReply);

    /**
     * Editing reuses the composer itself (like a reply, but replacing send
     * with save) rather than an inline textarea in the bubble - simpler, and
     * consistent with the reply-preview pattern already used above. Reply
     * and attachment state are mutually exclusive with it, so both are
     * cleared when an edit starts.
     */
    function startEdit(id: number, text: string) {
        cancelReply();
        cancelAttachment();

        editingMessageId = id;
        attachBtn.disabled = true;
        inputEl.value = text;
        autoGrowInput();
        editPreviewEl.hidden = false;
        updateSendButtonState();
        inputEl.focus();
    }

    function cancelEdit() {
        if (editingMessageId === null) return;

        editingMessageId = null;
        attachBtn.disabled = false;
        editPreviewEl.hidden = true;
        inputEl.value = '';
        autoGrowInput();
        updateSendButtonState();
    }

    editCancelBtn.addEventListener('click', cancelEdit);

    /**
     * Shows the pending attachment (image thumbnail via a local blob URL, or
     * a generic file icon) above the composer, same slot pattern as the
     * reply preview. Called both while the upload is in flight (uploading =
     * true, no uploadId yet) and once it completes.
     */
    function renderAttachPreview() {
        if (!pendingAttachment) {
            attachPreviewEl.hidden = true;
            attachPreviewThumbEl.innerHTML = '';
            attachPreviewNameEl.textContent = '';
            attachPreviewSizeEl.textContent = '';
            attachPreviewEl.classList.remove('is-uploading');
            return;
        }

        const isImage = pendingAttachment.mime.startsWith('image/');
        attachPreviewThumbEl.innerHTML = isImage
            ? `<img src="${pendingAttachment.previewUrl}" alt="">`
            : `<i class="bi bi-file-earmark-fill"></i>`;
        attachPreviewNameEl.textContent = pendingAttachment.name;
        attachPreviewSizeEl.textContent = attachUploading ? trans('js.common.loading') : bytesToLabel(pendingAttachment.size);
        attachPreviewEl.classList.toggle('is-uploading', attachUploading);
        attachPreviewEl.hidden = false;
    }

    function cancelAttachment() {
        if (pendingAttachment) URL.revokeObjectURL(pendingAttachment.previewUrl);
        pendingAttachment = null;
        attachUploading = false;
        attachmentRequestId++; // invalidate any upload still in flight
        renderAttachPreview();
        updateSendButtonState();
    }

    attachCancelBtn.addEventListener('click', cancelAttachment);
    attachBtn.addEventListener('click', () => attachInput.click());

    attachInput.addEventListener('change', () => void handleAttachmentPicked());

    // Bumped on every new pick/cancel so a slow upload response can't
    // clobber a newer pick (or a cancel) that happened while it was in
    // flight - the response is only applied if it's still the current one.
    let attachmentRequestId = 0;

    async function handleAttachmentPicked() {
        const file = attachInput.files?.[0];
        attachInput.value = '';
        if (!file) return;

        // Show the preview immediately from the local file (so the UI feels
        // instant), using a blob URL - real upload happens in the
        // background and only fills in pendingAttachment.uploadId once done.
        cancelAttachment();
        const requestId = ++attachmentRequestId;
        attachUploading = true;
        pendingAttachment = {
            uploadId: 0,
            mime: file.type,
            name: file.name,
            size: file.size,
            previewUrl: URL.createObjectURL(file),
        };
        renderAttachPreview();
        updateSendButtonState();

        let result: { id: number; url: string; mime: string } | null = null;
        try {
            result = await uploadFile('/api/v1/uploads', file);
        } catch (e: any) {
            cms.toast({ message: e?.error || trans('js.messenger.file_upload_failed'), type: 'danger' });
        }

        // Superseded by a newer pick or a cancel while this upload was in
        // flight - don't resurrect it.
        if (requestId !== attachmentRequestId) return;

        attachUploading = false;

        if (!result) {
            cancelAttachment();
            return;
        }

        pendingAttachment = pendingAttachment ? { ...pendingAttachment, uploadId: result.id, mime: result.mime } : null;
        renderAttachPreview();
        updateSendButtonState();
    }

    async function sendMessage() {
        if (!activeId) return;
        const text = inputEl.value.trim();

        if (editingMessageId !== null) {
            if (!text) return;
            const id = editingMessageId;

            inputEl.value = '';
            autoGrowInput();
            cancelEdit();
            inputEl.focus();

            try {
                await cms.api(`${API}/${activeId}/messages/${id}`, { method: 'PATCH', data: { text } });
            } catch (e) {
                if (editingMessageId === null && inputEl.value === '') {
                    startEdit(id, text);
                }

                cms.toast({ message: trans('js.messenger.save_failed'), type: 'danger' });
                return;
            }

            const existing = renderedMessages.get(id);
            if (existing) {
                const updated: ApiMessage = { ...existing, text, edited: true };
                renderedMessages.set(id, updated);
                patchMessageInPlace(updated);
            }
            return;
        }

        if (!text && !pendingAttachment) return;
        if (attachUploading) return;

        const replyToMessageId = replyTarget?.id ?? null;
        const attachmentUploadId = pendingAttachment?.uploadId || null;

        // Kept for the failure path below. `draft` is the raw value rather than
        // the trimmed `text`, so restoring it gives back exactly what was
        // typed.
        const draft = inputEl.value;
        const previousReply = replyTarget;

        inputEl.value = '';
        autoGrowInput();
        cancelReply();
        cancelAttachment();
        inputEl.focus();

        try {
            await cms.api(`${API}/${activeId}/messages`, {
                method: 'POST',
                data: { text, reply_to_message_id: replyToMessageId, attachment_upload_id: attachmentUploadId },
            });
        } catch (e) {
            // Same reasoning as the edit branch: clearing up front is
            // deliberate, losing the message when the send fails is not. Guard
            // on an untouched composer so a message typed while the request was
            // in flight isn't overwritten by the one that failed.
            //
            // The attachment can't come back - cancelAttachment() revoked its
            // preview blob URL - so a failed send with a file has to be
            // re-picked. The text and the reply target, which are the parts
            // that take real effort, survive.
            if (editingMessageId === null && inputEl.value === '') {
                inputEl.value = draft;
                autoGrowInput();

                if (previousReply) {
                    startReply(previousReply.id, previousReply.userId, previousReply.text);
                }

                inputEl.focus();
            }

            cms.toast({ message: trans('js.messenger.send_failed'), type: 'danger' });
            return;
        }

        await pollMessages();
    }

    sendBtn.addEventListener('click', () => void sendMessage());

    inputEl.addEventListener('input', () => {
        autoGrowInput();
        updateSendButtonState();

        const now = Date.now();
        if (activeId && now - lastTypingSentAt > 2000) {
            void cms.api(`${API}/${activeId}/typing`, { method: 'POST' });
            lastTypingSentAt = now;
        }
    });

    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            void sendMessage();
        } else if (e.key === 'Escape' && editingMessageId !== null) {
            cancelEdit();
        }
    });

    /* ===============================
       Empty state / new conversation panel
    =============================== */

    function openNewPanel(mode: NewTab = 'direct') {
        panel = 'new';
        setMobileContentVisible(true);
        renderPanels();
        renderConversations();

        newTab = mode;
        selectedGroupIds.clear();
        newGroupTitleInput.value = '';
        newContactsSearchInput.value = '';
        renderNewTabs();
        renderGroupChips();
        loadContacts('', newContactsListEl, 'newconv');
    }

    function closeNewPanel() {
        panel = activeId !== null ? 'chat' : 'empty';
        renderPanels();
        renderConversations();
        if (panel === 'empty') setMobileContentVisible(false);
    }

    newOpenBtn?.addEventListener('click', () => openNewPanel('direct'));
    emptyNewBtn?.addEventListener('click', () => openNewPanel('direct'));
    newCloseBtn?.addEventListener('click', closeNewPanel);

    newTabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const tab = (btn.dataset.newTab as NewTab) ?? 'direct';
            newTab = tab;
            renderNewTabs();
            renderNewContactsList();
        });
    });

    function renderNewTabs() {
        newTabButtons.forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.newTab === newTab);
        });
        newGroupFields.hidden = newTab !== 'group';
        newGroupFooter.hidden = newTab !== 'group';
    }

    function renderGroupChips() {
        if (!selectedGroupIds.size) {
            newGroupChipsEl.hidden = true;
            newGroupChipsEl.innerHTML = '';
        } else {
            newGroupChipsEl.hidden = false;
            newGroupChipsEl.innerHTML = Array.from(selectedGroupIds.entries()).map(([id, name]) => `
                <span class="msgr-chip" data-chip-id="${id}">${cms.escapeHtml(name)}<i class="bi bi-x-lg"></i></span>
            `).join('');
        }
        newGroupCreateBtn.disabled = selectedGroupIds.size === 0;
    }

    newGroupChipsEl.addEventListener('click', (event) => {
        const target = event.target as HTMLElement;
        const chip = target.closest<HTMLElement>('[data-chip-id]');
        if (!chip) return;
        selectedGroupIds.delete(parseInt(chip.dataset.chipId ?? '0', 10));
        renderGroupChips();
        renderNewContactsList();
    });

    let lastContacts: ApiContact[] = [];

    async function loadContacts(query: string, target: HTMLElement, kind: 'newconv' | 'groupadd') {
        target.innerHTML = `<div class="msgr-conv-placeholder">${trans('js.common.loading')}</div>`;

        let data: ApiContactsResponse;
        try {
            data = await cms.api<ApiContactsResponse>(`${USERS_API}?q=${encodeURIComponent(query)}&sort=name&direction=asc&page=1`);
        } catch (e) {
            target.innerHTML = `<div class="msgr-conv-empty">${trans('js.messenger.contacts_failed')}</div>`;
            return;
        }

        const contacts = data.data.filter(c => c.id !== currentUserId);

        if (kind === 'newconv') {
            lastContacts = contacts;
            renderNewContactsList();
        } else {
            const memberIds = new Set(activeDetail?.participants.map(p => p.id) ?? []);
            const available = contacts.filter(c => !memberIds.has(c.id));
            renderGroupAddList(available, target);
        }
    }

    function renderContactRow(c: ApiContact, selected: boolean): string {
        return `
            <div class="msgr-contact-row" data-contact-id="${c.id}" data-contact-name="${cms.escapeHtml(c.displayName)}">
                <div>${renderAvatar({ isGroup: false, id: c.id, name: c.displayName, size: 'md' })}</div>
                <div class="msgr-contact-body">
                    <div class="msgr-contact-name">${cms.escapeHtml(c.displayName)}</div>
                </div>
                ${selected ? '<i class="bi bi-check-circle-fill msgr-contact-check"></i>' : ''}
            </div>`;
    }

    function renderNewContactsList() {
        if (!lastContacts.length) {
            newContactsListEl.innerHTML = `<div class="msgr-conv-empty">${trans('js.messenger.nobody_found')}</div>`;
            return;
        }
        newContactsListEl.innerHTML = lastContacts
            .map(c => renderContactRow(c, newTab === 'group' && selectedGroupIds.has(c.id)))
            .join('');
    }

    newContactsListEl.addEventListener('click', (event) => {
        const target = event.target as HTMLElement;
        const row = target.closest<HTMLElement>('[data-contact-id]');
        if (!row) return;
        const id = parseInt(row.dataset.contactId ?? '0', 10);
        const contact = lastContacts.find(c => c.id === id);
        if (!contact) return;

        if (newTab === 'group') {
            if (selectedGroupIds.has(id)) selectedGroupIds.delete(id);
            else selectedGroupIds.set(id, contact.displayName);
            renderGroupChips();
            renderNewContactsList();
        } else {
            void createDirectConversation(id);
        }
    });

    newContactsSearchInput.addEventListener('input', () => {
        debounce(() => void loadContacts(newContactsSearchInput.value.trim(), newContactsListEl, 'newconv'), 300, contactsSearchTimerRef);
    });

    async function createDirectConversation(userId: number) {
        let res: { conversation_id: number };
        try {
            res = await cms.api<{ conversation_id: number }>(API, { method: 'POST', data: { user_id: userId } });
        } catch (e) {
            cms.toast({ message: trans('js.messenger.create_failed'), type: 'danger' });
            return;
        }
        await loadConversations();
        await openConversation(res.conversation_id);
    }

    newGroupCreateBtn.addEventListener('click', async () => {
        if (!selectedGroupIds.size) return;
        let res: { conversation_id: number };
        try {
            res = await cms.api<{ conversation_id: number }>(API, {
                method: 'POST',
                data: {
                    user_ids: Array.from(selectedGroupIds.keys()),
                    title: newGroupTitleInput.value.trim() || null,
                },
            });
        } catch (e) {
            cms.toast({ message: trans('js.messenger.group_create_failed'), type: 'danger' });
            return;
        }
        await loadConversations();
        await openConversation(res.conversation_id);
    });

    /* ===============================
       Group info drawer
    =============================== */

    function openGroupInfo() {
        if (!activeDetail?.is_group) return;
        groupBackdrop.hidden = false;
        groupDrawer.hidden = false;
        renderGroupDrawer();
    }

    function closeGroupInfo() {
        groupBackdrop.hidden = true;
        groupDrawer.hidden = true;
        groupAddOpen = false;
        groupAddPanel.hidden = true;
    }

    groupCloseBtn.addEventListener('click', closeGroupInfo);
    groupBackdrop.addEventListener('click', closeGroupInfo);

    function renderGroupDrawer() {
        if (!activeDetail) return;

        groupTitleEl.textContent = activeDetail.title;
        groupCountEl.textContent = transChoiceWithCount('js.common.participant', activeDetail.participants.length);

        groupMembersEl.innerHTML = activeDetail.participants.map((p) => {
            const isMe = p.id === currentUserId;
            return `
                <div class="msgr-member-row">
                    <div>${renderAvatar({ isGroup: false, id: p.id, name: p.displayName, size: 'md', online: p.online })}</div>
                    <div class="msgr-member-body">
                        <div class="msgr-member-name">${cms.escapeHtml(p.displayName)}${isMe ? trans('js.messenger.you_parenthetical') : ''}</div>
                    </div>
                    ${!isMe ? `<button type="button" class="msgr-member-remove" data-remove-member="${p.id}" title="${trans('js.common.remove')}"><i class="bi bi-x-lg"></i></button>` : ''}
                </div>`;
        }).join('');
    }

    groupMembersEl.addEventListener('click', (event) => {
        const target = event.target as HTMLElement;
        const btn = target.closest<HTMLButtonElement>('[data-remove-member]');
        if (!btn || !activeId) return;
        const userId = parseInt(btn.dataset.removeMember ?? '0', 10);
        const member = activeDetail?.participants.find(p => p.id === userId);

        cms.confirm({
            title: trans('js.messenger.remove_member_title'),
            message: trans('js.messenger.remove_member_confirm', { name: member?.displayName ?? trans('js.messenger.member_fallback') }),
            onConfirm: () => void removeMember(userId),
        });
    });

    async function removeMember(userId: number) {
        if (!activeId) return;
        try {
            await cms.api(`${API}/${activeId}/participants/${userId}`, { method: 'DELETE' });
        } catch (e) {
            cms.toast({ message: trans('js.messenger.remove_member_failed'), type: 'danger' });
            return;
        }
        await refreshActiveDetail();
        renderGroupDrawer();
    }

    async function refreshActiveDetail() {
        if (!activeId) return;
        try {
            activeDetail = await cms.api<ApiConversationDetail>(`${API}/${activeId}`);
        } catch (e) {
            return;
        }
        activeParticipants = {};
        activeDetail.participants.forEach(p => { activeParticipants[p.id] = p; });
        renderChatHeader(activeDetail);
    }

    groupAddToggleBtn.addEventListener('click', () => {
        groupAddOpen = !groupAddOpen;
        groupAddPanel.hidden = !groupAddOpen;
        groupAddSearchInput.value = '';
        if (groupAddOpen) void loadContacts('', groupAddListEl, 'groupadd');
    });

    groupAddSearchInput.addEventListener('input', () => {
        debounce(() => void loadContacts(groupAddSearchInput.value.trim(), groupAddListEl, 'groupadd'), 300, groupAddSearchTimerRef);
    });

    function renderGroupAddList(contacts: ApiContact[], target: HTMLElement) {
        if (!contacts.length) {
            target.innerHTML = `<div class="msgr-conv-empty">${trans('js.messenger.nobody_to_add')}</div>`;
            return;
        }
        target.innerHTML = contacts.map(c => renderContactRow(c, false)).join('');
    }

    groupAddListEl.addEventListener('click', (event) => {
        const target = event.target as HTMLElement;
        const row = target.closest<HTMLElement>('[data-contact-id]');
        if (!row || !activeId) return;
        const id = parseInt(row.dataset.contactId ?? '0', 10);
        void addMember(id);
    });

    async function addMember(userId: number) {
        if (!activeId) return;
        try {
            await cms.api(`${API}/${activeId}/participants`, { method: 'POST', data: { user_ids: [userId] } });
        } catch (e) {
            cms.toast({ message: trans('js.messenger.add_member_failed'), type: 'danger' });
            return;
        }
        groupAddOpen = false;
        groupAddPanel.hidden = true;
        await refreshActiveDetail();
        renderGroupDrawer();
    }

    groupLeaveBtn.addEventListener('click', () => {
        if (!activeId) return;
        cms.confirm({
            title: trans('js.messenger.leave_title'),
            message: trans('js.messenger.leave_confirm'),
            onConfirm: () => void leaveGroup(),
        });
    });

    async function leaveGroup() {
        if (!activeId) return;
        const id = activeId;

        try {
            await cms.api(`${API}/${id}/participants/${currentUserId}`, { method: 'DELETE' });
        } catch (e) {
            cms.toast({ message: trans('js.messenger.leave_failed'), type: 'danger' });
            return;
        }

        clearConversationHash();
        closeConversation();
        await loadConversations();
    }

    /* ===============================
       Conversations background refresh
    =============================== */

    function startConversationsPoll() {
        stopConversationsPoll();
        conversationsPollTimer = window.setInterval(() => void loadConversations(), 6000);
    }

    function stopConversationsPoll() {
        if (conversationsPollTimer !== null) {
            window.clearInterval(conversationsPollTimer);
            conversationsPollTimer = null;
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopConversationsPoll();
            stopMessagesPoll();
        } else {
            startConversationsPoll();
            if (activeId) startMessagesPoll();
        }
    });

    /* ===============================
       Init
    =============================== */

    renderPanels();
    updateSendButtonState();
    startConversationsPoll();

    void (async () => {
        // The list first, then the deep link: openConversation() zeroes the
        // opened chat's unread badge in `conversations`, which only sticks
        // if that array is already populated - otherwise the badge would
        // linger until the next 6s poll.
        await loadConversations();
        await applyHash(cms.hashRoute.getAll());
    })();
}
