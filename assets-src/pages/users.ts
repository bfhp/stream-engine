import {
    FeedPostCard,
    renderFeedPostCard,
} from "./user-cards";
import { renderPagination } from "./user-pagination";
import { getApiErrorMessage } from "../shared/api-errors";
import { initOffsetLoadMore } from "./offset-load-more";
import { initCoverWidget } from "../shared/cover-widget";
import { initAudioPlayers } from "./audio-player";
import { trans } from "../shared/i18n";
import { formatDateValue } from "../shared/date-time-format";
import { ui } from "../shared/ui";

// @ts-ignore
const cms = window.CMS;

/* ===============================
   Users list
=============================== */

interface PublicUserListItem {
    displayName: string;
    username: string | null;
    avatarUrl: string;
    createdAt: number;
    url: string | null;
}

interface PublicUsersPayload {
    data: PublicUserListItem[];
    pagination?: {
        currentPage?: number;
        totalPages?: number;
    };
}

function initUsersList() {
    const root = document.querySelector<HTMLElement>('[data-users-list]');
    if (!root) return;

    const apiUrl = root.dataset.apiUrl || '/api/v1/users';
    const pageUrl = root.dataset.pageUrl || window.location.pathname;

    function formatDate(timestamp: number): string {
        const date = new Date(Number(timestamp) * 1000);

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return formatDateValue(date);
    }

    // The grid, the pager slot and the message line are list.twig's markup;
    // each card is a copy of the theme's components/users/user-card.twig.
    const grid = root.querySelector<HTMLElement>('[data-users-grid]');
    const pager = root.querySelector<HTMLElement>('[data-users-pager]');
    const message = root.querySelector<HTMLElement>('[data-users-message]');

    function showMessage(text: string, tone: 'muted' | 'danger' = 'muted'): void {
        grid?.replaceChildren();
        pager?.replaceChildren();
        if (!message) return;
        message.textContent = text;
        message.dataset.tone = tone;
        message.hidden = false;
    }

    function userCard(item: PublicUserListItem): HTMLElement | null {
        const card = ui.clone('user-card');
        if (!card) return null;

        const date = formatDate(item.createdAt);
        const name = item.displayName ?? '';

        // A row without a username has no public profile to link to yet,
        // so it degrades to a plain, non-clickable card; one without an
        // avatar shows its initial instead.
        return ui.fill(card, {
            avatar: item.avatarUrl ?? '',
            initial: item.avatarUrl ? '' : name.trim().charAt(0).toUpperCase(),
            url: item.url ?? '',
            name: item.url ? name : '',
            nameText: item.url ? '' : name,
            username: item.username ?? '',
            since: trans('js.users.member_since', { date }),
            sinceIso: date !== '' ? new Date(Number(item.createdAt) * 1000).toISOString() : '',
        });
    }

    function render(data: PublicUsersPayload): void {
        const items = Array.isArray(data.data) ? data.data : [];

        if (!items.length) {
            showMessage(trans('js.users.empty'));
            return;
        }

        if (message) message.hidden = true;
        grid?.replaceChildren(...items.map(userCard).filter((card): card is HTMLElement => card !== null));

        const nav = renderPagination(data.pagination, pageUrl, window.location.search);
        pager?.replaceChildren(...(nav ? [nav] : []));
    }

    async function loadUsers(): Promise<void> {
        const url = new URL(apiUrl, window.location.origin);
        url.search = window.location.search;

        try {
            const response = await fetch(url, { credentials: 'include' });

            if (!response.ok) {
                throw new Error(`Users API error: ${response.status}`);
            }

            render(await response.json());
        } catch (error) {
            console.error(error);
            showMessage(trans('js.users.load_failed'), 'danger');
        }
    }

    void loadUsers();
}


/* ===============================
   Blog feed load-more (user.show profile page)
=============================== */

function initBlogFeedLoadMore() {
    initOffsetLoadMore<FeedPostCard>({
        containerId: 'blog-feed',
        readyFlag: 'blogFeedReady',
        apiUrlKey: 'postsApiUrl',
        buttonSelector: '.load-more-blog-posts',
        listId: 'blog-feed-posts',
        wrapperSelector: '[data-blog-feed-load-more-wrapper]',
        render: renderFeedPostCard,
        errorMessage: trans('js.users.posts_load_failed'),
    });
}

/* ===============================
   Community feed load-more (action=community.main)
=============================== */

function initCommunityFeedLoadMore() {
    initOffsetLoadMore<FeedPostCard>({
        containerId: 'community-feed',
        readyFlag: 'communityFeedReady',
        apiUrlKey: 'postsApiUrl',
        buttonSelector: '.load-more-community-posts',
        listId: 'community-feed-posts',
        wrapperSelector: '[data-community-feed-load-more-wrapper]',
        // The community feed's cards carry a per-post author byline; the
        // profile's, above, do not - that flag is the only difference.
        render: renderFeedPostCard,
        errorMessage: trans('js.users.posts_load_failed'),
    });
}

/* ===============================
   Single community feed load-more (action=community.show)
=============================== */

function initCommunityShowFeedLoadMore() {
    initOffsetLoadMore<FeedPostCard>({
        containerId: 'community-show-feed',
        readyFlag: 'communityShowFeedReady',
        apiUrlKey: 'postsApiUrl',
        buttonSelector: '.load-more-community-show-posts',
        listId: 'community-show-feed-posts',
        wrapperSelector: '[data-community-show-feed-load-more-wrapper]',
        render: renderFeedPostCard,
        errorMessage: trans('js.users.posts_load_failed'),
    });
}

/* ===============================
   Friends list load-more (user.show profile page)
=============================== */

interface FriendCard {
    displayName: string;
    avatarUrl: string;
    url: string | null;
}

function renderPersonRow(item: { url: string | null; avatarUrl: string; displayName: string; roleLabel: string | null }): HTMLElement | null {
    const row = ui.clone('person-row');
    return row && ui.fill(row, {
        url: item.url || '#',
        avatar: item.avatarUrl,
        name: item.displayName,
        role: item.roleLabel ?? '',
    });
}

function initFriendsLoadMore() {
    // A copy of the theme's components/users/person-row.twig, the same
    // partial show.twig renders the first friends with.
    const renderFriendRow = (item: FriendCard): HTMLElement | null =>
        renderPersonRow({ url: item.url, avatarUrl: item.avatarUrl, displayName: item.displayName, roleLabel: null });

    initOffsetLoadMore<FriendCard>({
        containerId: 'friends-list',
        readyFlag: 'friendsReady',
        apiUrlKey: 'friendsApiUrl',
        buttonSelector: '.load-more-friends',
        listId: 'friends-list-items',
        wrapperSelector: '[data-friends-load-more-wrapper]',
        render: renderFriendRow,
        errorMessage: trans('js.users.friends_load_failed'),
    });
}

/* ===============================
   Friend request / remove button (profile-sidebar.twig, on user.show)
=============================== */

interface FriendActionPayload {
    status: 'friends' | 'subscribed' | 'incoming' | 'none';
}

/**
 * The friend button on a profile (profile-sidebar.twig). The theme renders
 * one button per state inside [data-friend-action] - `friends`, `subscribed`
 * and `none` (add; also what an incoming request shows) - with all but the
 * current one hidden; a click sends that button's action and shows the
 * button for the status the API answers with. Markup and wording stay in
 * the theme.
 */
function initFriendButton() {
    const container = document.querySelector<HTMLElement>('[data-friend-action]');
    const url = container?.dataset.friendUrl;
    if (!container || !url) return;

    const buttons = Array.from(container.querySelectorAll<HTMLButtonElement>('[data-friend-state]'));

    function applyStatus(status: string): void {
        const state = status === 'friends' || status === 'subscribed' ? status : 'none';
        buttons.forEach((button) => (button.hidden = button.dataset.friendState !== state));
    }

    container.addEventListener('click', async (event) => {
        const button = (event.target as Element | null)?.closest<HTMLButtonElement>('[data-friend-state]');
        if (!button || button.disabled) return;

        const method = button.dataset.action === 'remove' ? 'DELETE' : 'POST';
        button.disabled = true;

        try {
            const res = await cms.api<FriendActionPayload>(url, { method });
            applyStatus(res.status);
        } catch (error: any) {
            cms.toast({
                message: error?.error || trans('js.common.action_failed'),
                type: 'danger',
            });
        } finally {
            button.disabled = false;
        }
    });
}

/* ===============================
   Community members list load-more (community.show page)
=============================== */

interface CommunityMemberCard {
    displayName: string;
    avatarUrl: string;
    url: string | null;
    roleLabel: string | null;
}

function initCommunityMembersLoadMore() {
    // A copy of the theme's components/users/person-row.twig, the same
    // partial community-sidebar.twig renders the first members with.
    const renderMemberRow = (item: CommunityMemberCard): HTMLElement | null => renderPersonRow(item);

    initOffsetLoadMore<CommunityMemberCard>({
        containerId: 'community-members',
        readyFlag: 'membersReady',
        apiUrlKey: 'membersApiUrl',
        buttonSelector: '.load-more-community-members',
        listId: 'community-members-items',
        wrapperSelector: '[data-community-members-load-more-wrapper]',
        render: renderMemberRow,
        errorMessage: trans('js.users.members_load_failed'),
    });
}

/* ===============================
   Join/leave button (community.show page)
=============================== */

interface CommunityMembershipPayload {
    status: 'owner' | 'moderator' | 'member' | 'pending' | 'none';
}

/**
 * The join/leave button on a community (community-sidebar.twig). Same idea as
 * initFriendButton(): one button per state inside [data-membership-action] -
 * `none` (join), `pending`, `member` (leave; also a moderator's) - and a
 * click shows the one for the status the API answers with. 'owner' never
 * reaches here: leave() rejects it server-side, and no button is rendered
 * for an owner.
 */
function initCommunityMembershipButton() {
    const container = document.querySelector<HTMLElement>('[data-membership-action]');
    const url = container?.dataset.membershipUrl;
    if (!container || !url) return;

    const buttons = Array.from(container.querySelectorAll<HTMLButtonElement>('[data-membership-state]'));

    function applyStatus(status: string): void {
        const state = status === 'pending' ? 'pending'
            : status === 'member' || status === 'moderator' ? 'member'
            : 'none';
        buttons.forEach((button) => (button.hidden = button.dataset.membershipState !== state));
        container!.dataset.status = status;
    }

    container.addEventListener('click', async (event) => {
        const button = (event.target as Element | null)?.closest<HTMLButtonElement>('[data-membership-state]');
        if (!button || button.disabled) return;

        const method = button.dataset.action === 'remove' ? 'DELETE' : 'POST';
        button.disabled = true;

        try {
            const res = await cms.api<CommunityMembershipPayload>(url, { method });
            applyStatus(res.status);
        } catch (error: any) {
            cms.toast({
                message: error?.error || trans('js.common.action_failed'),
                type: 'danger',
            });
        } finally {
            button.disabled = false;
        }
    });
}

/* ===============================
   Blog post delete (post-show page)
=============================== */

function initBlogPostDelete() {
    const deleteBtn = document.querySelector<HTMLButtonElement>('[data-blog-post-delete]');
    if (!deleteBtn) return;

    const feedId = deleteBtn.dataset.feedId;
    const redirectUrl = deleteBtn.dataset.redirectUrl || '/';

    if (!feedId) return;

    deleteBtn.addEventListener('click', () => {
        cms.confirm({
            title: trans('js.users.delete_post_title'),
            message: trans('js.users.delete_post_confirm'),
            onConfirm: async () => {
                try {
                    await cms.api(`/api/v1/users/blog-posts/${feedId}`, { method: 'DELETE' });
                } catch (error: any) {
                    cms.toast({
                        message: error?.error || trans('js.users.delete_post_failed'),
                        type: 'danger',
                    });
                    return;
                }

                cms.toast({
                    message: trans('js.users.post_deleted'),
                    type: 'success',
                });

                // Same 800ms delay as auth.ts's login/logout toasts before
                // navigating away, so the toast is actually visible.
                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, 800);
            },
        });
    });
}

/* ===============================
   Community create form (community.create page) - moved here from its
   own assets-src/pages/community-create-form.js entry so it shares this
   bundle's module scope instead of getting its own <script> tag; see
   UsersController::showCommunityCreatePage()'s own note on why that
   entry existed only to dodge the classic-script global-scope collision
   this file itself just hit (users.js vs site.js both minifying an
   unrelated top-level name to the same letter).
=============================== */

function initCommunityCreateForm() {
    const form = document.querySelector<HTMLFormElement>('[data-community-create-form]');
    if (!form) return;

    const apiUrl = form.dataset.apiUrl;
    const uploadsApiUrl = form.dataset.uploadsApiUrl;

    if (!apiUrl || !uploadsApiUrl) return;

    const nameInput = document.getElementById('communityName') as HTMLInputElement | null;
    const descriptionInput = document.getElementById('communityDescription') as HTMLTextAreaElement | null;
    const errorEl = document.querySelector<HTMLElement>('[data-community-create-error]');
    const btnCreate = document.getElementById('btnCreateCommunity') as HTMLButtonElement | null;

    const coverSlot = document.querySelector<HTMLElement>('[data-cover-slot]');
    const coverUrlInput = document.getElementById('coverImageUrl') as HTMLInputElement | null;

    if (!nameInput || !descriptionInput || !btnCreate) return;

    const setError = (message = ''): void => {
        if (!errorEl) return;
        errorEl.textContent = message;
    };

    initCoverWidget({
        slot: coverSlot,
        urlInput: coverUrlInput,
        uploadsApiUrl: uploadsApiUrl!,
        prompt: trans('js.cover.community_prompt'),
        onError: setError,
    });

    /* ---------- submit ---------- */
    async function submit(): Promise<void> {
        const name = nameInput!.value.trim();

        if (!name) {
            setError(trans('js.users.community_name_required'));
            nameInput!.focus();
            return;
        }

        const membershipType = form.querySelector<HTMLInputElement>('input[name="membershipType"]:checked')?.value || 'open';

        setError();
        btnCreate!.disabled = true;
        const originalHtml = btnCreate!.innerHTML;
        btnCreate!.innerHTML = `<span class="ui-spinner" aria-hidden="true"></span> ${trans('js.users.creating')}`;

        try {
            const community = await cms.api<{ redirectUrl?: string }>(apiUrl, {
                method: 'POST',
                data: {
                    name,
                    description: descriptionInput!.value.trim(),
                    imageUrl: coverUrlInput?.value || null,
                    membershipType,
                },
            });

            cms.toast({
                message: trans('js.users.community_created'),
                type: 'success',
            });

            setTimeout(() => {
                if (community.redirectUrl) {
                    window.location.href = community.redirectUrl;
                } else {
                    window.location.reload();
                }
            }, 800);
        } catch (error: any) {
            setError(getApiErrorMessage(error, trans('js.users.community_create_failed')));
            btnCreate!.disabled = false;
            btnCreate!.innerHTML = originalHtml;
        }
    }

    btnCreate.addEventListener('click', submit);
}

/* ===============================
   Community manage - settings tab (community.manage page) - see
   UsersController::showCommunityManagePage()/
   handleCommunityManageSettingsRequest().
=============================== */

interface CommunityManageSettingsPayload {
    needsConfirmation: boolean;
    pendingCount?: number;
    promotedCount?: number | null;
    membershipType?: string | null;
}

function initCommunityManageSettingsForm() {
    const form = document.querySelector<HTMLFormElement>('[data-community-manage-settings-form]');
    if (!form) return;

    const apiUrl = form.dataset.apiUrl;
    const uploadsApiUrl = form.dataset.uploadsApiUrl;

    if (!apiUrl || !uploadsApiUrl) return;

    const nameInput = document.getElementById('communityManageName') as HTMLInputElement | null;
    const descriptionInput = document.getElementById('communityManageDescription') as HTMLTextAreaElement | null;
    const statusEl = document.querySelector<HTMLElement>('[data-community-manage-settings-status]');
    const btnSave = document.getElementById('btnSaveCommunitySettings') as HTMLButtonElement | null;

    const coverSlot = document.getElementById('communityManageCoverSlot') as HTMLElement | null;
    const coverUrlInput = document.getElementById('communityManageCoverImageUrl') as HTMLInputElement | null;

    if (!nameInput || !descriptionInput || !btnSave) return;

    const setStatus = (message = '', isError = false): void => {
        if (!statusEl) return;
        statusEl.textContent = message;
        statusEl.dataset.tone = isError ? 'danger' : 'muted';
    };

    initCoverWidget({
        slot: coverSlot,
        urlInput: coverUrlInput,
        uploadsApiUrl: uploadsApiUrl!,
        prompt: trans('js.cover.community_prompt'),
        onError: (message) => setStatus(message, true),
    });

    /* ---------- submit ---------- */
    async function save(confirmPromoteSubscribers: boolean): Promise<void> {
        const name = nameInput!.value.trim();

        if (!name) {
            setStatus(trans('js.users.community_name_required'), true);
            nameInput!.focus();
            return;
        }

        const membershipType = form.querySelector<HTMLInputElement>('input[name="membershipType"]:checked')?.value
            || form.dataset.membershipTypeOpen
            || 'open';

        setStatus();
        btnSave!.disabled = true;
        const originalHtml = btnSave!.innerHTML;
        btnSave!.innerHTML = `<span class="ui-spinner" aria-hidden="true"></span> ${trans('js.common.saving')}`;

        // Set when the server asks for confirmation, which suspends this call
        // rather than finishing it - the button has to stay disabled until the
        // dialog is answered, so the `finally` below must not touch it.
        let awaitingConfirmation = false;

        try {
            const result = await cms.api<CommunityManageSettingsPayload>(apiUrl, {
                method: 'PATCH',
                data: {
                    name,
                    description: descriptionInput!.value.trim(),
                    imageUrl: coverUrlInput?.value || null,
                    membershipType,
                    confirmPromoteSubscribers,
                },
            });

            if (result.needsConfirmation) {
                awaitingConfirmation = true;

                // Label restored, but the button stays **disabled** while the
                // dialog is open. It used to be re-enabled here, which let a
                // second save start behind the dialog - and since this first
                // request has already run server-side, the two answers could
                // disagree about whether subscribers were promoted.
                //
                // Restoring the label isn't cosmetic either: save(true) below
                // snapshots btnSave.innerHTML as its own `originalHtml`, so
                // leaving the spinner in place would make the confirmed save
                // "restore" a spinner as the button's resting label.
                btnSave!.innerHTML = originalHtml;

                const pendingCount = result.pendingCount ?? 0;

                cms.confirm({
                    title: trans('js.users.membership_change_title'),
                    message: trans('js.users.membership_change_confirm', { count: pendingCount }),
                    onConfirm: () => {
                        void save(true);
                    },
                    // Answered "no", or dismissed with Escape or the backdrop:
                    // nothing else will re-enable the button, so this has to.
                    onDismiss: () => {
                        btnSave!.disabled = false;
                    },
                });
                return;
            }

            if (typeof result.membershipType === 'string') {
                form.dataset.currentMembershipType = result.membershipType;
            }

            setStatus(trans('js.common.saved'));
            cms.toast({ message: trans('js.users.settings_saved'), type: 'success' });
        } catch (error: any) {
            setStatus(getApiErrorMessage(error, trans('js.users.settings_save_failed')), true);
        } finally {
            if (!awaitingConfirmation) {
                btnSave!.disabled = false;
                btnSave!.innerHTML = originalHtml;
            }
        }
    }

    btnSave.addEventListener('click', () => void save(false));
}

/* ===============================
   Community manage - members tab (community.manage page) - accept/remove
   row buttons (see UsersController::
   handleCommunityManageMemberRequest()).
=============================== */

function initCommunityManageMembers() {
    const container = document.querySelector<HTMLElement>('[data-community-manage-members]');
    if (!container) return;

    const apiBase = container.dataset.memberActionUrlBase;
    if (!apiBase) return;

    async function handleAction(
        row: HTMLElement,
        method: 'PATCH' | 'DELETE',
        button: HTMLButtonElement,
        successMessage: string,
    ): Promise<void> {
        const userId = row.dataset.userId;
        if (!userId) return;

        button.disabled = true;

        try {
            await cms.api(`${apiBase}${userId}`, { method });
        } catch (error: any) {
            cms.toast({
                message: error?.error || trans('js.common.action_failed'),
                type: 'danger',
            });
            button.disabled = false;
            return;
        }

        cms.toast({ message: successMessage, type: 'success' });

        // Reload rather than hand-move the row between the two lists
        // (subscribers -> members, or member -> back to subscribers) -
        // simplest way to keep both lists' counts/order exactly in sync
        // with the server, same 600ms-then-navigate delay as
        // initBlogPostDelete()'s own toast-then-redirect.
        setTimeout(() => {
            window.location.reload();
        }, 600);
    }

    container.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;

        const row = target.closest<HTMLElement>('[data-user-row]');
        if (!row) return;

        const approveBtn = target.closest<HTMLButtonElement>('[data-manage-approve]');
        if (approveBtn) {
            void handleAction(row, 'PATCH', approveBtn, trans('js.users.member_approved'));
            return;
        }

        const removeBtn = target.closest<HTMLButtonElement>('[data-manage-remove]');
        if (removeBtn) {
            cms.confirm({
                title: trans('js.users.member_remove_title'),
                message: trans('js.users.member_remove_confirm'),
                onConfirm: () => {
                    void handleAction(row, 'DELETE', removeBtn, trans('js.users.member_removed'));
                },
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initUsersList();
    initBlogFeedLoadMore();
    initCommunityFeedLoadMore();
    initCommunityShowFeedLoadMore();
    initFriendsLoadMore();
    initFriendButton();
    initCommunityMembersLoadMore();
    initCommunityMembershipButton();
    initBlogPostDelete();
    initCommunityCreateForm();
    initCommunityManageSettingsForm();
    initCommunityManageMembers();
    initAudioPlayers();
});
