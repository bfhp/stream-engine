import { getApiErrorMessage } from "../shared/api-errors";
import { trans } from "../shared/i18n";

type Api = (url: string, options?: { method?: string; data?: unknown }) => Promise<any>;
type Member = { id: number; nick: string; username: string | null; roleLevel: number };

export function initForumManagement(root: HTMLElement, api: Api, reload = () => window.location.reload()) {
    const base = root.dataset.apiUrl;
    if (!base) return;
    const settings = root.querySelector<HTMLFormElement>("[data-forum-settings]")!;
    const list = root.querySelector<HTMLElement>("[data-forum-member-list]")!;
    const results = root.querySelector<HTMLElement>("[data-forum-user-results]")!;
    const error = root.querySelector<HTMLElement>("[data-forum-manage-error]")!;
    const status = root.querySelector<HTMLElement>("[data-forum-manage-status]")!;
    const previous = root.querySelector<HTMLButtonElement>("[data-forum-members-prev]")!;
    const next = root.querySelector<HTMLButtonElement>("[data-forum-members-next]")!;
    const parent = settings.elements.namedItem("parentId") as HTMLSelectElement;
    const visibility = settings.elements.namedItem("visibility") as HTMLSelectElement;
    let offset = 0;
    let busy = false;
    let searchVersion = 0;
    let membersVersion = 0;
    const fail = (reason: unknown) => { error.textContent = getApiErrorMessage(reason, trans("js.forums.manage_invalid")); };
    const button = (text: string, action: () => void) => {
        const element = document.createElement("button");
        element.type = "button"; element.className = "ui-button ui-button--secondary ui-button--sm";
        element.textContent = text; element.addEventListener("click", action);
        return element;
    };
    function updateVisibility() {
        visibility.disabled = parent.value === "";
        if (visibility.disabled) visibility.value = "public";
    }
    parent.addEventListener("change", updateVisibility); updateVisibility();

    async function loadMembers() {
        const version = ++membersVersion;
        previous.disabled = true; next.disabled = true;
        const page = await api(`${base}/members?offset=${offset}`);
        if (version !== membersVersion) return;
        list.replaceChildren();
        for (const member of page.data as Member[]) {
            const row = document.createElement("li");
            const name = document.createElement("span");
            name.textContent = `${member.nick || member.username || "#" + member.id} (#${member.id})`;
            const role = document.createElement("select"); role.className = "ui-input";
            role.setAttribute("aria-label", `${trans("js.forums.role")}: ${name.textContent}`);
            for (const value of ["member", "moderator"]) {
                const option = document.createElement("option"); option.value = value;
                option.textContent = trans(`js.forums.${value}`); role.append(option);
            }
            role.value = member.roleLevel >= 2 ? "moderator" : "member";
            const protectedMember = member.roleLevel >= 3 || (!page.globalModerator && member.id === page.currentUserId);
            role.disabled = protectedMember;
            role.addEventListener("change", () => { void mutate(member.id, role.value); });
            const remove = button(trans("js.forums.remove_member"), () => { void mutate(member.id, null); });
            remove.disabled = protectedMember;
            row.append(name, role, remove); list.append(row);
        }
        if (page.data.length === 0) {
            const empty = document.createElement("li"); empty.textContent = trans("js.forums.no_members"); list.append(empty);
        }
        previous.disabled = offset === 0; next.disabled = offset + 50 >= page.total;
        root.querySelector<HTMLElement>("[data-forum-members-page]")!.textContent = `${offset ? offset + 1 : Math.min(1, page.total)}–${Math.min(offset + 50, page.total)} / ${page.total}`;
    }
    async function mutate(id: number, role: string | null) {
        if (busy) return;
        busy = true; error.textContent = ""; status.textContent = "";
        const controls = [...root.querySelectorAll<HTMLButtonElement | HTMLSelectElement>("[data-forum-member-list] button, [data-forum-member-list] select, [data-forum-user-results] button")];
        const disabled = controls.map(control => control.disabled);
        controls.forEach(control => { control.disabled = true; });
        try {
            await api(`${base}/members/${id}`, { method: role === null ? "DELETE" : "PUT", ...(role === null ? {} : { data: { role } }) });
            if (role === null && list.children.length === 1 && offset > 0) offset -= 50;
            await loadMembers(); results.replaceChildren();
            status.textContent = trans("js.forums.management_saved");
        } catch (reason) {
            fail(reason);
            // A rejected role change must not leave an unsaved selection on screen.
            try { await loadMembers(); } catch { /* Keep the original request error visible. */ }
        } finally {
            controls.forEach((control, index) => { if (control.isConnected) control.disabled = disabled[index]; });
            busy = false;
        }
    }
    void loadMembers().catch(fail);
    previous.addEventListener("click", () => { if (!busy && offset > 0) { offset -= 50; void loadMembers().catch(fail); } });
    next.addEventListener("click", () => { if (!busy && !next.disabled) { offset += 50; void loadMembers().catch(fail); } });
    settings.addEventListener("submit", async event => {
        event.preventDefault(); error.textContent = "";
        const submit = settings.querySelector<HTMLButtonElement>('button[type="submit"]')!; submit.disabled = true;
        try {
            await api(base, { method: "PATCH", data: {
                title: (settings.elements.namedItem("title") as HTMLInputElement).value,
                description: (settings.elements.namedItem("description") as HTMLTextAreaElement).value,
                parentId: parent.value ? Number(parent.value) : null, visibility: visibility.value,
            } });
            reload();
        } catch (reason) { fail(reason); }
        finally { submit.disabled = false; }
    });
    root.querySelector<HTMLFormElement>("[data-forum-member-search]")!.addEventListener("submit", async event => {
        event.preventDefault(); error.textContent = "";
        const version = ++searchVersion;
        const form = event.currentTarget as HTMLFormElement;
        const query = (form.elements.namedItem("query") as HTMLInputElement).value.trim();
        if (!query) { results.replaceChildren(); return; }
        try {
            const response = await api(`${base}/users?q=${encodeURIComponent(query)}`);
            if (version !== searchVersion) return;
            results.replaceChildren();
            for (const user of response.data as { id: number; name: string; isMember?: boolean }[]) {
                const row = document.createElement("li"); const name = document.createElement("span");
                name.textContent = `${user.name || "#" + user.id} (#${user.id})`;
                const add = button(trans("js.forums.add_member"), () => { void mutate(user.id, "member"); });
                add.disabled = user.isMember === true;
                row.append(name, add); results.append(row);
            }
            if (response.data.length === 0) {
                const empty = document.createElement("li"); empty.textContent = trans("js.forums.no_users"); results.append(empty);
            }
        } catch (reason) { if (version === searchVersion) fail(reason); }
    });
}
