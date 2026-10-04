import { uploadFile } from "../shared/uploads";
import { getApiErrorMessage } from "../shared/api-errors";
import { trans, transChoice, transChoiceWithCount } from "../shared/i18n";
import { TAB_SHOWN, ui, type SlotData } from "../shared/ui";

const cms = window.CMS;

function bindProfileForm(form: HTMLFormElement) {
    const status = form.querySelector('[data-profile-status], [data-profile-personal-status]') as HTMLElement | null;
    const tabButtons = Array.from(document.querySelectorAll<HTMLButtonElement>('[data-bs-toggle="pill"]'));

    let snapshot = new FormData(form);
    let dirty = false;

    const setStatus = (message = "", type: "muted" | "success" | "danger" = "muted") => {
        if (!status) return;

        // The theme styles the tone (data-tone="muted|success|danger").
        status.dataset.tone = type;
        status.textContent = message;
    };

    /**
     * A value diff, not an event flag - typing something and then typing the
     * original value back leaves the form clean, which is what stops
     * `beforeunload` warning about nothing.
     *
     * Compares getAll(), not get(). `FormData.get()` returns only the *first*
     * value for a key, so a field contributing several entries under one name
     * - a checkbox group, a `<select multiple>`, any repeated `name` - could
     * be changed without registering as dirty as long as its first value
     * stayed put, and the edit would then die silently on navigation, since
     * `beforeunload` below only fires on `dirty`.
     *
     * Latent rather than live today: every field in these forms is
     * single-valued, and the avatar `<input type="file">` has no `name`, so it
     * contributes nothing here (which is just as well - two FormData reads of
     * an empty file input can yield two different File objects, and this
     * compares by identity). Fixed so it stays latent when someone adds the
     * first checkbox group.
     */
    const sameValues = (a: FormDataEntryValue[], b: FormDataEntryValue[]) =>
        a.length === b.length && a.every((value, index) => value === b[index]);

    const isDirty = () => {
        const current = new FormData(form);
        const keys = new Set<string>([
            ...Array.from(snapshot.keys()),
            ...Array.from(current.keys()),
        ]);

        for (const key of keys) {
            if (!sameValues(snapshot.getAll(key), current.getAll(key))) {
                return true;
            }
        }

        return false;
    };

    const refreshDirty = () => {
        dirty = isDirty();
        setStatus(dirty ? trans("js.profile.unsaved") : "");
    };

    form.addEventListener("input", refreshDirty);
    form.addEventListener("change", refreshDirty);

    window.addEventListener("beforeunload", (event) => {
        if (!dirty) return;

        event.preventDefault();
        event.returnValue = "";
    });

    tabButtons.forEach((button) => {
        button.addEventListener("click", (event) => {
            const target = event.currentTarget as HTMLButtonElement;
            const currentTarget = document.querySelector<HTMLButtonElement>('.nav-link.active[data-bs-toggle="pill"]');

            if (!dirty || currentTarget === target) {
                return;
            }

            if (!window.confirm(trans("js.profile.unsaved_switch_confirm"))) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    });

    form.addEventListener("submit", async (event) => {
        event.preventDefault();

        const submitButton = form.querySelector<HTMLButtonElement>('button[type="submit"]');
        const data = Object.fromEntries(new FormData(form).entries());

        submitButton?.setAttribute("disabled", "disabled");
        setStatus(trans("js.common.saving"));

        try {
            await cms.api(form.action, {
                method: "POST",
                data,
            });

            snapshot = new FormData(form);
            dirty = false;
            setStatus(trans("js.profile.saved"), "success");
        } catch (error: any) {
            setStatus(getApiErrorMessage(error, trans("js.profile.save_failed")), "danger");
        } finally {
            submitButton?.removeAttribute("disabled");
        }
    });

    return { setStatus, refreshDirty };
}

/* ===============================
   Shared list-tab machinery (friends and subscriptions)
=============================== */

/**
 * The two card-grid tabs on this page are the same machine with different
 * cards: fetch the whole list once, keep it in memory, filter it client-side,
 * re-render on every mutation from the status the API just returned. Only the
 * card markup, the ordering and the buttons differ, so those are the
 * parameters and everything else lives here.
 *
 * Every item needs an `id` (identifies the row for mutations) and a `status`
 * (drives ordering, and 'none' means the row is gone). Both APIs already
 * answer in exactly that shape, and every endpoint a button can call replies
 * with the resulting status - which is what lets a mutation be one round trip.
 */
interface ListTabItem {
    id: number;
    status: string;
}

interface ListTabContext {
    /**
     * Applies the status an endpoint just returned to the in-memory list and
     * re-renders. 'none' drops the row.
     */
    patch(id: number, status: string): void;
    /**
     * Sends `method` to the button's own `data-action-url`, disabling the
     * whole row while it's in flight, then patches from the response. Rolls
     * the buttons back and toasts on failure.
     */
    act(button: HTMLButtonElement, method: "POST" | "DELETE"): Promise<void>;
}

interface ListTabConfig<T extends ListTabItem> {
    /** id of the tab's root element, which also carries the API URL. */
    rootId: string;
    /** dataset key on the root holding that URL. */
    urlKey: string;
    /** prefix of the `data-*-list` / `-search` / `-empty` … hooks in the twig. */
    prefix: string;
    /** Lower sorts first. Reapplied after every mutation. */
    rank(item: T): number;
    /** The text the search box matches against. */
    searchText(item: T): string;
    /** A filled copy of the tab's card <template>, or null without one. */
    renderCard(item: T): HTMLElement | null;
    /** e.g. (5) => a localized count - the noun has to decline as rows disappear. */
    truncatedCount(count: number): string;
    loadErrorMessage: string;
    /** Attaches the tab's own click handlers, once. */
    bindActions(list: HTMLElement, ctx: ListTabContext): void;
    /** Called after every render, for anything that has to re-bind to new DOM. */
    afterRender?(): void;
}

interface ListTabPayload<T> {
    items: T[];
    meta: {
        truncated: boolean;
        limit: number;
    };
}

function initListTab<T extends ListTabItem>(config: ListTabConfig<T>): void {
    const root = document.getElementById(config.rootId);
    if (!root || root.dataset.listTabReady === "1") return;

    const url = root.dataset[config.urlKey];
    if (!url) return;

    const hook = (name: string) => root.querySelector<HTMLElement>(`[data-${config.prefix}-${name}]`);

    const list = hook("list");
    const search = root.querySelector<HTMLInputElement>(`[data-${config.prefix}-search]`);
    const loading = hook("loading");
    const errorBox = hook("error");
    const empty = hook("empty");
    const noMatches = hook("no-matches");
    const counter = hook("count");
    const truncated = hook("truncated");

    if (!list) return;

    let items: T[] = [];
    let query = "";
    let wasTruncated = false;

    const show = (element: HTMLElement | null, visible: boolean) => {
        if (element) element.hidden = !visible;
    };

    const visibleItems = (): T[] => {
        const matching = query === ""
            ? items.slice()
            : items.filter((item) => config.searchText(item).toLowerCase().includes(query));

        // Array.prototype.sort is stable, so the server's within-group
        // ordering (most recent first) survives the client-side regrouping.
        return matching.sort((a, b) => config.rank(a) - config.rank(b));
    };

    const render = () => {
        const visible = visibleItems();

        list.replaceChildren(...visible
            .map(config.renderCard)
            .filter((card): card is HTMLElement => card !== null));
        config.afterRender?.();

        if (counter) {
            counter.textContent = items.length > 0 ? `(${items.length})` : "";
            show(counter, items.length > 0);
        }

        show(search, items.length > 0);
        show(empty, items.length === 0);
        show(noMatches, items.length > 0 && visible.length === 0);

        // Re-checked on every render, not just after load: once rows start
        // disappearing the list may well be under the cap, and a stale
        // "showing the first N" line would be a lie.
        if (truncated) {
            truncated.textContent = trans("js.profile.truncated", { count: config.truncatedCount(items.length) });
        }
        show(truncated, wasTruncated && items.length > 0);
    };

    const patch = (id: number, status: string) => {
        items = status === "none"
            ? items.filter((item) => item.id !== id)
            : items.map((item) => (item.id === id ? { ...item, status } : item));

        render();
    };

    const act = async (button: HTMLButtonElement, method: "POST" | "DELETE") => {
        const actionUrl = button.dataset.actionUrl;
        const row = button.closest<HTMLElement>("[data-list-row]");
        const id = parseInt(row?.dataset.itemId ?? "0", 10);
        if (!actionUrl || !id) return;

        // Every button in the row, so it can't be double-acted on while one
        // request is in flight.
        const rowButtons = Array.from(row?.querySelectorAll<HTMLButtonElement>("button") ?? []);
        rowButtons.forEach((element) => (element.disabled = true));

        try {
            const response = await cms.api<{ status: string }>(actionUrl, { method });
            patch(id, response.status);
        } catch (error: any) {
            rowButtons.forEach((element) => (element.disabled = false));
            cms.toast({
                message: getApiErrorMessage(error, trans("js.common.action_failed")),
                type: "danger",
            });
        }
    };

    config.bindActions(list, { patch, act });

    search?.addEventListener("input", () => {
        query = (search.value ?? "").trim().toLowerCase();
        render();
    });

    const load = async () => {
        try {
            const response = await cms.api<ListTabPayload<T>>(url);

            items = response.items ?? [];
            wasTruncated = response.meta?.truncated === true;
            render();
        } catch (error: any) {
            // Also the graceful-degradation path each tab's twig comment
            // promises: if the endpoint is gone, the tab says so instead of
            // spinning forever.
            if (errorBox) {
                errorBox.textContent =
                    getApiErrorMessage(error, config.loadErrorMessage);
            }
            show(errorBox, true);
        } finally {
            show(loading, false);
        }
    };

    root.dataset.listTabReady = "1";

    // Every tab pane is rendered up front (see modules/profile/page.twig), so
    // this runs on a page whose active tab is usually personal details - no
    // reason to spend the query on a list nobody has looked at yet. Loads
    // immediately if this pane happens to be the active one, otherwise on the
    // first switch to it, once.
    const pane = root.closest<HTMLElement>(".tab-pane, .ui-tab-pane");

    if (!pane || ui.tabs.isActive(pane)) {
        void load();
        return;
    }

    // ui:tab-shown is the theme-neutral switch event (shared/ui.ts; the
    // Bootstrap adapter re-emits shown.bs.tab as it).
    const onShown = () => {
        if (!ui.tabs.isActive(pane)) return;

        document.removeEventListener(TAB_SHOWN, onShown);
        void load();
    };

    document.addEventListener(TAB_SHOWN, onShown);
}

/**
 * A card for one list row: a copy of the tab's <template> (rendered from
 * components/profile/*-card.twig, so the theme owns the markup) with the
 * row's values filled in. Optional pieces - a badge per status, an action per
 * button - are removed when their value is empty.
 */
function renderCard(templateName: string, data: SlotData): HTMLElement | null {
    const card = ui.clone(templateName);
    return card && ui.fill(card, data);
}

const flag = (on: boolean): string => (on ? "1" : "");

/* ===============================
   Friends tab (components/profile/tabs/friends.twig)
=============================== */

type ConnectionStatus = "friends" | "subscribed" | "incoming" | "none";

interface ConnectionCard {
    id: number;
    displayName: string;
    avatarUrl: string;
    url: string | null;
    username: string | null;
    status: ConnectionStatus;
    /** Users module's add/remove endpoint; null when there's no username. */
    actionUrl: string | null;
    /** This module's own reject endpoint, addressed by user id - always set. */
    rejectUrl: string;
}

/**
 * The friends tab: one list holding mutual friends and both kinds of pending
 * request, each card rendered from its own status. The fetch/filter/mutate
 * machinery is initListTab()'s; this is only the card and its buttons.
 *
 * Nothing here is rendered server-side, so unlike users.ts's
 * renderFriendRow() there's no twig block this markup has to be kept in sync
 * with by hand - this is the only renderer.
 */
function initProfileFriends(): void {
    /**
     * Which buttons a row gets is entirely a function of its status, and each
     * maps onto exactly one server call:
     *
     * - 'incoming': accepting is just the ordinary add-to-friends POST in the
     *   other direction (FriendService::sendRequest() completes the mutual
     *   pair, which is why there's no separate accept endpoint), and
     *   rejecting DELETEs their subscription to your own blog
     *   (FriendListService::rejectRequest()).
     * - 'friends'/'subscribed': one DELETE that removes *your own* half of
     *   the relationship (FriendService::removeFriend()); only the label
     *   differs, since cancelling a request you sent and unfriending someone
     *   are the same operation underneath.
     *
     * Note there's no remove action on an incoming row: removeFriend() would
     * find nothing of yours to delete there and quietly succeed, which is
     * exactly the sort of button that looks like it worked and didn't.
     *
     * Only reject and message survive a row with no username: the
     * first is addressed by user id, the second by conversation. Accept and
     * remove go to a username-addressed endpoint, so actionUrl is null there
     * and they're simply not rendered.
     *
     * The badge vocabulary is the sidebar button's (users.ts's applyStatus),
     * so a status means the same thing wherever the user meets it.
     */
    const cardData = (item: ConnectionCard): SlotData => {
        const incoming = item.status === "incoming";

        return {
            id: item.id,
            avatar: item.avatarUrl,
            url: item.url ?? "",
            name: item.url ? item.displayName : "",
            nameText: item.url ? "" : item.displayName,
            username: item.username ?? "",
            displayName: item.displayName,
            isFriends: flag(item.status === "friends"),
            isIncoming: flag(incoming),
            isSubscribed: flag(item.status === "subscribed"),
            acceptUrl: incoming ? (item.actionUrl ?? "") : "",
            rejectUrl: incoming ? item.rejectUrl : "",
            removeUrl: item.status === "friends" ? (item.actionUrl ?? "") : "",
            cancelUrl: item.status === "subscribed" ? (item.actionUrl ?? "") : "",
        };
    };

    initListTab<ConnectionCard>({
        rootId: "profile-friends",
        urlKey: "connectionsUrl",
        prefix: "friends",
        // Same actionable-first grouping MembershipRepository::findConnections()
        // sorts by, reapplied client-side so a card that changed status jumps
        // to where a reload would put it instead of sitting in its old group.
        rank: (item) => (item.status === "incoming" ? 0 : item.status === "friends" ? 1 : 2),
        searchText: (item) => item.displayName,
        truncatedCount: (count) => transChoiceWithCount("js.common.post_unit", count),
        loadErrorMessage: trans("js.profile.friends_list_failed"),

        // The avatar needs no letter-placeholder fallback (unlike users.ts):
        // avatarUrl arrives already resolved through
        // UserService::resolveAvatarUrl(), so it's never empty.
        renderCard: (item) => renderCard("profile-friend-card", cardData(item)),

        // Cards are added after site.js already ran its own pass, so the DM
        // buttons this just created still need binding; initDirectMessage()
        // guards itself with data-message-user-ready, so re-running it is safe
        // and only touches the new ones.
        afterRender: () => cms.initDirectMessage(),

        bindActions: (list, ctx) => {
            list.addEventListener("click", (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;

                const accept = target.closest<HTMLButtonElement>("[data-friend-accept]");
                if (accept) {
                    void ctx.act(accept, "POST");
                    return;
                }

                const reject = target.closest<HTMLButtonElement>("[data-friend-reject]");
                if (reject) {
                    cms.confirm({
                        title: trans("js.profile.reject_title"),
                        message: trans("js.profile.reject_confirm", { name: reject.dataset.displayName ?? "" }),
                        onConfirm: () => void ctx.act(reject, "DELETE"),
                    });
                    return;
                }

                const remove = target.closest<HTMLButtonElement>("[data-friend-remove]");
                if (remove) {
                    const name = remove.dataset.displayName ?? "";
                    const isRequest = remove.dataset.status === "subscribed";

                    cms.confirm({
                        title: trans(isRequest ? "js.profile.cancel_request" : "js.users.remove_friend"),
                        message: isRequest
                            // Both wordings are deliberately specific about
                            // what stays behind: DELETE /friend only ever
                            // removes your own half, so unfriending leaves the
                            // other person subscribed to you and the card
                            // reappears as an incoming request (which
                            // rejecting then clears) - see
                            // FriendService::removeFriend()'s own docblock.
                            ? trans("js.profile.cancel_friend_request_confirm", { name })
                            : trans("js.profile.remove_friend_confirm", { name }),
                        onConfirm: () => void ctx.act(remove, "DELETE"),
                    });
                }
            });
        },
    });
}

/* ===============================
   Subscriptions tab (components/profile/tabs/subscriptions.twig)
=============================== */

type CommunityStatus = "owner" | "moderator" | "member" | "pending" | "none";

interface CommunityCard {
    id: number;
    title: string;
    url: string | null;
    imageUrl: string;
    status: CommunityStatus;
    memberCount: number;
    /** Users module's join/leave endpoint; null for an owner, who can't leave. */
    leaveUrl: string | null;
    /** Community management page; only ever set for an owner. */
    manageUrl: string | null;
}

/**
 * The subscriptions tab: every community you belong to, in any capacity. Same
 * machinery and same card shell as the friends tab - see CommunityListService
 * for why listing lives in this module while leaving stays with Users.
 */
function initProfileSubscriptions(): void {
    /**
     * An owner gets a manage action instead of a leave button: CommunityService::
     * leave() refuses to let them out (there's no ownership transfer, so an
     * ownerless community can't be reached), and offering a button that can
     * only ever return a validation error is worse than not offering it.
     *
     * Everyone else gets one DELETE - the same call whether they're a member
     * or still waiting on approval, since a pending row is just a membership
     * with a lower role. Only the label changes.
     *
     * Badges use CommunityService::getRelationshipStatus()'s vocabulary, so a
     * status reads the same here as on the community page itself.
     */
    const cardData = (item: CommunityCard): SlotData => {
        const owner = item.status === "owner";
        const pending = item.status === "pending";

        return {
            id: item.id,
            image: item.imageUrl,
            url: item.url ?? "",
            name: item.url ? item.title : "",
            nameText: item.url ? "" : item.title,
            title: item.title,
            status: item.status,
            members: `${item.memberCount} ${transChoice("js.common.participant", item.memberCount)}`,
            isOwner: flag(owner),
            isModerator: flag(item.status === "moderator"),
            isMember: flag(item.status === "member"),
            isPending: flag(pending),
            manageUrl: owner ? (item.manageUrl ?? "") : "",
            leaveUrl: !owner && !pending ? (item.leaveUrl ?? "") : "",
            cancelUrl: pending ? (item.leaveUrl ?? "") : "",
        };
    };

    initListTab<CommunityCard>({
        rootId: "profile-subscriptions",
        urlKey: "subscriptionsUrl",
        prefix: "subscriptions",
        // By standing, mirroring the server's own ORDER BY role_level DESC, so
        // a card stays put unless its status actually changed.
        rank: (item) => ({ owner: 0, moderator: 1, member: 2 } as Record<string, number>)[item.status] ?? 3,
        searchText: (item) => item.title,
        truncatedCount: (count) => transChoiceWithCount("js.common.community_unit", count),
        loadErrorMessage: trans("js.profile.communities_list_failed"),

        // The card template uses a rounded square, not a circle: a
        // community's image is a banner-ish picture rather than a face.
        renderCard: (item) => renderCard("profile-community-card", cardData(item)),

        bindActions: (list, ctx) => {
            list.addEventListener("click", (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;

                const leave = target.closest<HTMLButtonElement>("[data-community-leave]");
                if (!leave) return;

                const title = leave.dataset.title ?? "";
                const isRequest = leave.dataset.status === "pending";

                cms.confirm({
                    title: trans(isRequest ? "js.profile.cancel_request" : "js.profile.leave_community_title"),
                    message: isRequest
                        ? trans("js.profile.cancel_join_confirm", { title })
                        : trans("js.profile.leave_community_confirm", { title }),
                    onConfirm: () => void ctx.act(leave, "DELETE"),
                });
            });
        },
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initProfileFriends();
    initProfileSubscriptions();

    const forms = Array.from(document.querySelectorAll<HTMLFormElement>('[data-profile-form], [data-profile-personal-form]'));
    const personalForm = document.querySelector('[data-profile-personal-form]') as HTMLFormElement | null;
    const personalBinding = forms.map(bindProfileForm).find((_, index) => forms[index] === personalForm);

    if (!personalForm || !personalBinding) {
        return;
    }

    const avatarInput = personalForm.querySelector('[data-profile-avatar-file]') as HTMLInputElement | null;
    const avatarUrlInput = personalForm.querySelector('[data-profile-avatar-url]') as HTMLInputElement | null;
    const avatarPreview = personalForm.querySelector('[data-profile-avatar-preview]') as HTMLImageElement | null;

    avatarInput?.addEventListener("change", async () => {
        const file = avatarInput.files?.[0];

        if (!file) {
            return;
        }

        personalBinding.setStatus(trans("js.profile.avatar_loading"));
        avatarInput.setAttribute("disabled", "disabled");

        try {
            const result = await uploadFile("/api/v1/uploads?variant=avatar", file);

            if (avatarUrlInput) {
                avatarUrlInput.value = result.url;
            }

            if (avatarPreview) {
                avatarPreview.src = result.url;
            }

            personalBinding.refreshDirty();
            personalBinding.setStatus(trans("js.profile.avatar_uploaded"), "success");
        } catch (error: any) {
            personalBinding.setStatus(getApiErrorMessage(error, trans("js.profile.avatar_failed")), "danger");
        } finally {
            avatarInput.removeAttribute("disabled");
            avatarInput.value = "";
        }
    });
});
