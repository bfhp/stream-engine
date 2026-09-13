import {
    FeedPostCard,
    renderFeedPostCard,
} from "./user-cards";
import { pagination } from "./user-pagination";
import { getApiErrorMessage } from "../shared/api-errors";
import { escapeHtml } from "../shared/escape";
import { initOffsetLoadMore } from "./offset-load-more";
import { initCoverWidget } from "../shared/cover-widget";
import { initAudioPlayers } from "./audio-player";
import { trans } from "../shared/i18n";

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

        return new Intl.DateTimeFormat('ru-RU').format(date);
    }

    // Cards (not a plain list) so a browsing/search page full of users scans
    // well at a glance - same card idiom as the profile page's blog feed
    // and friends list, just laid out as a responsive grid here.
    function userItem(item: PublicUserListItem): string {
        const displayName = cms.escapeHtml(item.displayName);
        const initial = cms.escapeHtml(
            (item.displayName ?? '').trim().charAt(0).toUpperCase()
        );
        const avatar = item.avatarUrl
            ? `<img src="${cms.escapeHtml(item.avatarUrl)}" alt="" class="rounded-circle flex-shrink-0" width="56" height="56" loading="lazy">`
            : `<div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 56px; height: 56px; font-size: 1.25rem;" aria-hidden="true">${initial}</div>`;
        const date = formatDate(item.createdAt);
        const dateTime = date !== '' ? new Date(Number(item.createdAt) * 1000).toISOString() : '';
        // Whole card is clickable via stretched-link when a profile URL is
        // known; some rows may lack a username (no public profile to link
        // to yet), so those degrade to a plain, non-clickable card.
        const name = item.url
            ? `<a href="${cms.escapeHtml(item.url)}" class="text-body text-decoration-none stretched-link">${displayName}</a>`
            : displayName;
        const username = item.username
            ? `<div class="text-body-secondary small text-truncate">@${cms.escapeHtml(item.username)}</div>`
            : '';

        return `
            <div class="col">
                <div class="card h-100 shadow-sm">
                    <div class="card-body d-flex align-items-center gap-3">
                        ${avatar}
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate">${name}</div>
                            ${username}
                            <time class="text-body-secondary small d-block" datetime="${dateTime}">
                                ${trans('js.users.member_since', { date })}
                            </time>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function render(data: PublicUsersPayload): void {
        const items = Array.isArray(data.data) ? data.data : [];

        if (!items.length) {
            root.innerHTML = `<div class="text-muted">${trans('js.users.empty')}</div>`;
            return;
        }

        root.innerHTML = `
            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3">
                ${items.map(userItem).join('')}
            </div>
            ${pagination(data.pagination, pageUrl, window.location.search)}
        `;
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
            root.innerHTML = `<div class="text-danger">${trans('js.users.load_failed')}</div>`;
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
        render: (item) => renderFeedPostCard(item, false),
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
        render: (item) => renderFeedPostCard(item, true),
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
        render: (item) => renderFeedPostCard(item, true),
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

function initFriendsLoadMore() {
    // Mirrors the friends_list_item block in modules/users/show.twig - same
    // kept-in-sync-by-hand trade-off as the feed cards in user-cards.ts.
    const renderFriendRow = (item: FriendCard): string => `
            <a href="${escapeHtml(item.url || '#')}" class="d-flex align-items-center gap-2 text-body text-decoration-none">
                <img src="${escapeHtml(item.avatarUrl)}" alt="" class="rounded-circle bg-body-secondary object-fit-cover flex-shrink-0" width="36" height="36">
                <span class="small">${escapeHtml(item.displayName)}</span>
            </a>
        `;

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

function initFriendButton() {
    const button = document.querySelector<HTMLButtonElement>('.friend-action-btn');
    if (!button) return;

    function applyStatus(status: string): void {
        const isRelated = status === 'friends' || status === 'subscribed';

        button!.dataset.action = isRelated ? 'remove' : 'add';
        button!.className = isRelated
            ? 'btn btn-outline-secondary friend-action-btn'
            : 'btn btn-primary friend-action-btn';

        const icon = status === 'friends' ? 'bi-person-check' : status === 'subscribed' ? 'bi-person-dash' : 'bi-person-plus';
        const label = trans(status === 'friends' ? 'js.users.remove_friend' : status === 'subscribed' ? 'js.users.unsubscribe' : 'js.users.add_friend');

        button!.innerHTML = `<i class="bi ${icon}"></i> ${label}`;
    }

    button.addEventListener('click', async () => {
        const url = button.dataset.friendUrl;
        if (!url) return;

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
    // Mirrors the community_members_item block in
    // modules/users/community-show.twig.
    const renderMemberRow = (item: CommunityMemberCard): string => {
        const roleBadge = item.roleLabel
            ? `<span class="badge text-bg-secondary ms-auto">${escapeHtml(item.roleLabel)}</span>`
            : '';

        return `
            <a href="${escapeHtml(item.url || '#')}" class="d-flex align-items-center gap-2 text-body text-decoration-none">
                <img src="${escapeHtml(item.avatarUrl)}" alt="" class="rounded-circle bg-body-secondary object-fit-cover flex-shrink-0" width="36" height="36">
                <span class="small">${escapeHtml(item.displayName)}</span>
                ${roleBadge}
            </a>
        `;
    };

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

function initCommunityMembershipButton() {
    const button = document.querySelector<HTMLButtonElement>('.community-membership-btn');
    if (!button) return;

    function applyStatus(status: string): void {
        if (status === 'pending') {
            button!.dataset.action = 'remove';
            button!.dataset.status = 'pending';
            button!.className = 'btn btn-outline-secondary community-membership-btn w-100';
            button!.innerHTML = `<i class="bi bi-hourglass-split"></i> ${trans('js.users.membership_pending')}`;
            return;
        }

        if (status === 'member' || status === 'moderator') {
            button!.dataset.action = 'remove';
            button!.dataset.status = status;
            button!.className = 'btn btn-outline-secondary community-membership-btn w-100';
            button!.innerHTML = `<i class="bi bi-box-arrow-right"></i> ${trans('js.users.leave_community')}`;
            return;
        }

        // 'none' (or anything else, e.g. right after leaving) - the join
        // button. 'owner' never reaches here: leave() rejects it
        // server-side, and the button isn't rendered for an owner in the
        // first place (see community-show.twig).
        button!.dataset.action = 'add';
        button!.dataset.status = 'none';
        button!.className = 'btn btn-primary community-membership-btn w-100';
        button!.innerHTML = `<i class="bi bi-person-plus"></i> ${trans('js.users.join')}`;
    }

    button.addEventListener('click', async () => {
        const url = button.dataset.membershipUrl;
        if (!url) return;

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
        btnCreate!.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${trans('js.users.creating')}`;

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
        statusEl.classList.toggle('text-danger', isError);
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
        btnSave!.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${trans('js.common.saving')}`;

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
