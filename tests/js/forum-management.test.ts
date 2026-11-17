import { beforeEach, describe, expect, it, vi } from "vitest";
import { initForumManagement } from "../../assets-src/pages/forum-management";

const fixture = `<div data-forum-management data-api-url="/api/v1/forums/manage/101">
<form data-forum-settings><input name="title" value="Renamed"><textarea name="description">Description</textarea>
<select name="parentId"><option value="">Category</option><option value="100" selected>Parent</option></select>
<select name="visibility"><option value="public">Public</option><option value="members" selected>Members</option></select>
<button type="submit">Save</button></form>
<form data-forum-member-search><input name="query"><button type="submit">Search</button></form>
<ul data-forum-user-results></ul><ul data-forum-member-list></ul>
<button data-forum-members-prev></button><button data-forum-members-next></button><span data-forum-members-page></span>
<p data-forum-manage-error></p><p data-forum-manage-status></p></div>`;
const flush = () => new Promise(resolve => setTimeout(resolve, 0));
const element = <T extends HTMLElement>(selector: string) => document.querySelector<T>(selector)!;
const submit = (selector: string) => element<HTMLFormElement>(selector).dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
const member = { id: 20, nick: '<img src=x onerror="bad()">', username: "member", roleLevel: 1 };
const page = { data: [member], total: 1, currentUserId: 40, globalModerator: false };
beforeEach(() => { document.body.innerHTML = fixture; });

describe("forum management page", () => {
    it("saves editable settings and reloads only after success", async () => {
        const api = vi.fn().mockResolvedValue(page);
        const reload = vi.fn();
        initForumManagement(element("[data-forum-management]"), api, reload);
        await flush(); submit("[data-forum-settings]"); await flush();
        expect(api).toHaveBeenCalledWith("/api/v1/forums/manage/101", { method: "PATCH", data: {
            title: "Renamed", description: "Description", parentId: 100, visibility: "members"
        } });
        expect(reload).toHaveBeenCalledOnce();
    });
    it("keeps unsaved settings and displays server errors", async () => {
        const api = vi.fn().mockResolvedValueOnce(page).mockRejectedValueOnce({ error: "Invalid parent" });
        const reload = vi.fn();
        initForumManagement(element("[data-forum-management]"), api, reload);
        await flush(); submit("[data-forum-settings]"); await flush();
        expect(reload).not.toHaveBeenCalled();
        expect(element("[data-forum-manage-error]").textContent).toBe("Invalid parent");
        expect(element<HTMLInputElement>('[name="title"]').value).toBe("Renamed");
        expect(element<HTMLButtonElement>('[data-forum-settings] button').disabled).toBe(false);
    });
    it("renders member names as text and protects local self-management and owners", async () => {
        const api = vi.fn().mockResolvedValue({ ...page, data: [member, { ...member, id: 40, roleLevel: 2 }, { ...member, id: 60, roleLevel: 3 }] });
        initForumManagement(element("[data-forum-management]"), api);
        await flush();
        expect(element("[data-forum-member-list]").textContent).toContain(member.nick);
        expect(document.querySelector("img")).toBeNull();
        const roles = document.querySelectorAll<HTMLSelectElement>("[data-forum-member-list] select");
        expect([...roles].map(role => role.disabled)).toEqual([false, true, true]);
    });
    it("retains members when removal fails and refreshes role state", async () => {
        const api = vi.fn().mockResolvedValue(page);
        initForumManagement(element("[data-forum-management]"), api);
        await flush(); api.mockRejectedValueOnce({ error: "Forbidden" });
        element<HTMLButtonElement>("[data-forum-member-list] button").click(); await flush();
        expect(api).toHaveBeenCalledWith("/api/v1/forums/manage/101/members/20", { method: "DELETE" });
        expect(element("[data-forum-member-list]").textContent).toContain(member.nick);
        expect(element("[data-forum-manage-error]").textContent).toBe("Forbidden");
    });
    it("adds a search result through the member endpoint", async () => {
        const api = vi.fn().mockResolvedValue(page);
        initForumManagement(element("[data-forum-management]"), api);
        await flush();
        api.mockResolvedValueOnce({ data: [{ id: 30, name: "New member", isMember: false }] });
        element<HTMLInputElement>('[name="query"]').value = "New";
        submit("[data-forum-member-search]"); await flush();
        element<HTMLButtonElement>("[data-forum-user-results] button").click(); await flush();
        expect(api).toHaveBeenCalledWith("/api/v1/forums/manage/101/users?q=New");
        expect(api).toHaveBeenCalledWith("/api/v1/forums/manage/101/members/30", { method: "PUT", data: { role: "member" } });
    });
    it("forces public visibility for a top-level category", async () => {
        const api = vi.fn().mockResolvedValue(page);
        initForumManagement(element("[data-forum-management]"), api);
        const parent = element<HTMLSelectElement>('[name="parentId"]'); parent.value = "";
        parent.dispatchEvent(new Event("change"));
        expect(element<HTMLSelectElement>('[name="visibility"]').disabled).toBe(true);
        expect(element<HTMLSelectElement>('[name="visibility"]').value).toBe("public");
    });
});
