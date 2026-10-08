import { beforeEach, describe, expect, it, vi } from "vitest";

import {
    ForumPostActionsDependencies,
    initForumPostActions,
} from "../../assets-src/pages/forum-post-actions";

const api = vi.fn<ForumPostActionsDependencies["api"]>();
const confirm = vi.fn<ForumPostActionsDependencies["confirm"]>();
const toast = vi.fn<ForumPostActionsDependencies["toast"]>();
const onEditSaved = vi.fn<ForumPostActionsDependencies["onEditSaved"]>();
const onReplyDeleted = vi.fn<ForumPostActionsDependencies["onReplyDeleted"]>();
const onQuote = vi.fn<ForumPostActionsDependencies["onQuote"]>();

const FIXTURE = `
    <ul id="topic-posts">
        <li id="post-2">
            <div data-comment-content-wrapper="90">Старый текст</div>
            <div hidden data-comment-edit-wrapper="90">
                <input data-comment-edit-input value=" &lt;div&gt;Исправлено&lt;/div&gt; ">
                <trix-editor data-comment-edit-editor></trix-editor>
                <div hidden data-comment-edit-error></div>
                <button type="button" data-comment-edit-save data-comment-id="90" data-parent-id="41">
                    <span>Сохранить</span>
                </button>
                <button type="button" data-comment-edit-cancel data-comment-id="90">Отмена</button>
            </div>
            <button type="button" data-comment-edit-toggle data-comment-id="90">
                <span>Изменить</span>
            </button>
            <button type="button" data-comment-delete data-comment-id="90" data-parent-id="41">
                <span>Удалить</span>
            </button>
            <button type="button" data-quote-toggle>Цитировать</button>
        </li>
    </ul>
`;

function element<T extends HTMLElement>(selector: string): T {
    const match = document.querySelector(selector);
    if (!match) throw new Error(`fixture: ${selector} is missing`);
    return match as T;
}

function click(selector: string) {
    element(selector).dispatchEvent(new MouseEvent("click", { bubbles: true }));
}

async function settle() {
    await Promise.resolve();
    await Promise.resolve();
}

beforeEach(() => {
    document.body.innerHTML = FIXTURE;
    api.mockReset();
    confirm.mockReset();
    toast.mockReset();
    onEditSaved.mockReset();
    onReplyDeleted.mockReset();
    onQuote.mockReset();

    const editor = element<HTMLElement & { editor?: unknown }>("[data-comment-edit-editor]");
    editor.editor = {
        getDocument: () => ({ toString: () => "Исправлено\n" }),
    };

    initForumPostActions(element("#topic-posts"), {
        api,
        confirm,
        toast,
        onEditSaved,
        onReplyDeleted,
        onQuote,
        trans: (key) => key,
    });
});

describe("forum post actions", () => {
    it("opens and cancels the inline editor from nested button content", () => {
        const content = element<HTMLElement>('[data-comment-content-wrapper="90"]');
        const editor = element<HTMLElement>('[data-comment-edit-wrapper="90"]');
        const error = element<HTMLElement>("[data-comment-edit-error]");

        click("[data-comment-edit-toggle] span");

        expect(content.hidden).toBe(true);
        expect(editor.hidden).toBe(false);

        error.hidden = false;
        click("[data-comment-edit-cancel]");

        expect(content.hidden).toBe(false);
        expect(editor.hidden).toBe(true);
        expect(error.hidden).toBe(true);
    });

    it("saves trimmed rich text through the reply endpoint", async () => {
        api.mockResolvedValue({});

        click("[data-comment-edit-save] span");

        expect(api).toHaveBeenCalledWith("/api/v1/comments/41/90", {
            method: "PATCH",
            data: { content: "<div>Исправлено</div>", format: "html" },
        });
        expect(element<HTMLButtonElement>("[data-comment-edit-save]").disabled).toBe(true);

        await settle();

        expect(onEditSaved).toHaveBeenCalledWith(element("#post-2"));
    });

    it("keeps an empty edit local and shows the validation message", () => {
        element<HTMLInputElement>("[data-comment-edit-input]").value = "";
        const editor = element<HTMLElement & { editor?: unknown }>("[data-comment-edit-editor]");
        editor.editor = { getDocument: () => ({ toString: () => "  " }) };

        click("[data-comment-edit-save]");

        expect(api).not.toHaveBeenCalled();
        expect(element<HTMLElement>("[data-comment-edit-error]").hidden).toBe(false);
        expect(element("[data-comment-edit-error]").textContent).toBe("js.forums.message_required");
    });

    it("re-enables the save button and displays the API error after failure", async () => {
        api.mockRejectedValue({ error: "Редактирование уже недоступно" });

        click("[data-comment-edit-save]");
        await settle();

        expect(element<HTMLButtonElement>("[data-comment-edit-save]").disabled).toBe(false);
        expect(element("[data-comment-edit-error]").textContent).toBe("Редактирование уже недоступно");
    });

    it("deletes only after confirmation", async () => {
        api.mockResolvedValue({});

        click("[data-comment-delete] span");

        expect(api).not.toHaveBeenCalled();
        expect(confirm).toHaveBeenCalledWith(expect.objectContaining({
            title: "js.forums.message_delete_title",
            message: "js.forums.message_delete_confirm",
        }));

        confirm.mock.calls[0][0].onConfirm();

        expect(api).toHaveBeenCalledWith("/api/v1/comments/41/90", { method: "DELETE" });
        await settle();
        expect(onReplyDeleted).toHaveBeenCalledOnce();
    });

    it("reports a failed delete and leaves the page in place", async () => {
        api.mockRejectedValue({ error: { message: "Удаление запрещено" } });

        click("[data-comment-delete]");
        confirm.mock.calls[0][0].onConfirm();
        await settle();

        expect(toast).toHaveBeenCalledWith({ message: "Удаление запрещено", type: "danger" });
        expect(onReplyDeleted).not.toHaveBeenCalled();
    });

    it("keeps quote delegation in the same post-action listener", () => {
        const button = element<HTMLElement>("[data-quote-toggle]");

        click("[data-quote-toggle]");

        expect(onQuote).toHaveBeenCalledWith(button);
    });
});
