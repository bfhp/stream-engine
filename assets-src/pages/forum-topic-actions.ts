import { getApiErrorMessage } from "../shared/api-errors";

type ConfirmOptions = {
    title: string;
    message: string;
    onConfirm: () => void;
};

export type ForumTopicActionsDependencies = {
    api: (url: string, options: { method: "DELETE" }) => Promise<unknown>;
    confirm: (options: ConfirmOptions) => void;
    toast: (options: { message: string; type: "danger" }) => void;
    trans: (key: string) => string;
    redirect: (url: string) => void;
};

export function initForumTopicDelete(
    button: HTMLElement | null,
    dependencies: ForumTopicActionsDependencies,
) {
    if (!button) return;

    button.addEventListener("click", () => {
        const topicId = button.dataset.topicId;
        const redirectUrl = button.dataset.redirectUrl;
        if (!topicId || !redirectUrl) return;

        dependencies.confirm({
            title: dependencies.trans("js.forums.topic_delete_title"),
            message: dependencies.trans("js.forums.topic_delete_confirm"),
            onConfirm: () => {
                dependencies.api(`/api/v1/forums/topics/${topicId}`, { method: "DELETE" })
                    .then(() => dependencies.redirect(redirectUrl))
                    .catch((error) => dependencies.toast({
                        message: getApiErrorMessage(
                            error,
                            dependencies.trans("js.forums.topic_delete_failed"),
                        ),
                        type: "danger",
                    }));
            },
        });
    });
}
