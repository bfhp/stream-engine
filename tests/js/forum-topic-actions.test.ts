import { beforeEach, describe, expect, it, vi } from "vitest";

import {
    ForumTopicActionsDependencies,
    initForumTopicDelete,
} from "../../assets-src/pages/forum-topic-actions";

const api = vi.fn<ForumTopicActionsDependencies["api"]>();
const confirm = vi.fn<ForumTopicActionsDependencies["confirm"]>();
const toast = vi.fn<ForumTopicActionsDependencies["toast"]>();
const redirect = vi.fn<ForumTopicActionsDependencies["redirect"]>();

beforeEach(() => {
    document.body.innerHTML = `
        <button data-forum-topic-delete data-topic-id="41" data-redirect-url="/forums/magiya/">
            <span>Удалить</span>
        </button>
    `;
    api.mockReset();
    confirm.mockReset();
    toast.mockReset();
    redirect.mockReset();

    initForumTopicDelete(document.querySelector("[data-forum-topic-delete]"), {
        api,
        confirm,
        toast,
        redirect,
        trans: (key) => key,
    });
});

describe("forum topic deletion", () => {
    it("deletes only after confirmation and returns to the forum", async () => {
        api.mockResolvedValue({});

        document.querySelector("span")?.click();
        expect(api).not.toHaveBeenCalled();
        expect(confirm).toHaveBeenCalledWith(expect.objectContaining({
            title: "js.forums.topic_delete_title",
            message: "js.forums.topic_delete_confirm",
        }));

        confirm.mock.calls[0][0].onConfirm();
        expect(api).toHaveBeenCalledWith("/api/v1/forums/topics/41", { method: "DELETE" });

        await Promise.resolve();
        await Promise.resolve();
        expect(redirect).toHaveBeenCalledWith("/forums/magiya/");
    });

    it("shows the API error and stays on the topic when deletion fails", async () => {
        api.mockRejectedValue({ error: { message: "У темы уже есть ответы" } });

        document.querySelector("button")?.click();
        confirm.mock.calls[0][0].onConfirm();
        await Promise.resolve();
        await Promise.resolve();

        expect(toast).toHaveBeenCalledWith({
            message: "У темы уже есть ответы",
            type: "danger",
        });
        expect(redirect).not.toHaveBeenCalled();
    });
});
