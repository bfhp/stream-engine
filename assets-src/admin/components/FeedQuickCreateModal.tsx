import { useEffect, useState } from "react";
import { Button, Group, Modal, Stack, TextInput } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { csrfHeaders } from "../../shared/csrf";
import { getApiResponseError } from "../../shared/api-errors";
import { FEED_TYPE_LABELS, FEED_TYPES } from "../../shared/feed-types";
import { trans } from "../../shared/i18n";
import type { PickerFeed } from "../lib/feed-picker";
import AdminSelect from "./AdminSelect";

type Props = {
    opened: boolean;
    onClose: () => void;
    onCreated: (feed: PickerFeed) => void;
    /** Types the page action accepts; empty means any type. */
    feedTypes?: string[];
    initialTitle?: string;
    defaultType?: string;
};

/**
 * Minimal feed form (title, slug, type) for creating a feed without leaving
 * the page editor. Everything else is edited later on the feed page.
 */
export default function FeedQuickCreateModal({
    opened,
    onClose,
    onCreated,
    feedTypes,
    initialTitle = "",
    defaultType
}: Props) {
    const allowedTypes = feedTypes && feedTypes.length > 0 ? feedTypes : FEED_TYPES;
    const fallbackType = defaultType && allowedTypes.includes(defaultType) ? defaultType : allowedTypes[0] ?? "";

    const [title, setTitle] = useState("");
    const [slug, setSlug] = useState("");
    const [type, setType] = useState(fallbackType);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (opened) {
            setTitle(initialTitle);
            setSlug("");
            setType(fallbackType);
        }
    }, [opened, initialTitle, fallbackType]);

    const typeOptions = allowedTypes.map(value => ({
        value,
        label: FEED_TYPE_LABELS[value] ? `${FEED_TYPE_LABELS[value]} (${value})` : value
    }));

    function create() {
        if (title.trim() === "" || type === "") {
            return;
        }

        setSaving(true);
        fetch("/api/v1/feeds", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                ...csrfHeaders()
            },
            body: JSON.stringify({ title: title.trim(), slug: slug.trim() || null, type })
        })
            .then(async response => {
                if (!response.ok) {
                    throw await getApiResponseError(response, trans("js.admin.feed_picker.create_failed"));
                }

                return response.json();
            })
            .then((feed: PickerFeed) => {
                notifications.show({
                    color: "green",
                    message: trans("js.admin.feed_picker.created", { id: feed.id })
                });
                onCreated(feed);
            })
            .catch(err => {
                notifications.show({
                    autoClose: false,
                    color: "red",
                    title: trans("js.admin.error"),
                    message: err.message || trans("js.admin.feed_picker.create_failed")
                });
            })
            .finally(() => setSaving(false));
    }

    return (
        <Modal opened={opened} onClose={onClose} title={trans("js.admin.feeds.new")} centered>
            <form
                onSubmit={event => {
                    event.preventDefault();
                    create();
                }}
            >
                <Stack>
                    <TextInput
                        label={trans("js.admin.title")}
                        required
                        data-autofocus
                        value={title}
                        onChange={event => setTitle(event.currentTarget.value)}
                    />
                    <TextInput
                        label={trans("js.admin.slug")}
                        description={trans("js.admin.feed_picker.slug_help")}
                        value={slug}
                        onChange={event => setSlug(event.currentTarget.value)}
                    />
                    <AdminSelect
                        label={trans("js.admin.type")}
                        required
                        searchable
                        data={typeOptions}
                        value={type || null}
                        onChange={value => setType(value || "")}
                        disabled={allowedTypes.length === 1}
                        allowDeselect={false}
                    />
                    <Group justify="flex-end">
                        <Button variant="default" onClick={onClose}>
                            {trans("js.admin.cancel")}
                        </Button>
                        <Button type="submit" loading={saving} disabled={title.trim() === "" || type === ""}>
                            {trans("js.admin.feed_picker.create")}
                        </Button>
                    </Group>
                </Stack>
            </form>
        </Modal>
    );
}
