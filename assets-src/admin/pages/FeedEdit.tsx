import { useEffect, useState, ChangeEvent, useRef } from "react";
import { useParams, useNavigate } from "react-router-dom";

import Editor from "@monaco-editor/react";

import { TextInput, Button, Stack, Group, ActionIcon, Text, Select, useComputedColorScheme } from "@mantine/core";
import { notifications } from "@mantine/notifications";

import { IconFileUpload } from "@tabler/icons-react";
import { FEED_TYPES } from "../../shared/feed-types";
import { uploadFile } from "../../shared/uploads";
import { csrfHeaders } from "../../shared/csrf";

export default function FeedEdit() {

    const { id } = useParams();
    const navigate = useNavigate();
    const colorScheme = useComputedColorScheme("light");

    const [feed, setFeed] = useState<any>(null);
    const [saving, setSaving] = useState(false);

    const fileInputRef = useRef<HTMLInputElement>(null);
    const editorRef = useRef<any>(null);

    useEffect(() => {

        if (!id) return;

        if (id === "new") {
            setFeed({
                parentId: "",
                title: "",
                slug: "",
                type: "article",
                description: "",
                imageUrl: "",
                content: ""
            });
            return;
        }

        fetch(`/api/v1/feeds/${id}`)
            .then(r => r.json())
            .then(data => setFeed(data));

    }, [id]);

    function save() {

        setSaving(true);

        const url =
            id === "new"
                ? "/api/v1/feeds"
                : `/api/v1/feeds/${id}`;

        const method =
            id === "new"
                ? "POST"
                : "PATCH";

        fetch(url, {
            method,
            headers: {
                "Content-Type": "application/json",
                // Both branches mutate and both now verify the token
                // server-side (APIController::handleFeedsRequest's POST and
                // handleFeedIdRequest's PATCH). They were admin-gated but
                // unprotected, and this is their only caller.
                ...csrfHeaders()
            },
            body: JSON.stringify(feed)
        })
            .then(async r => {
                if (!r.ok) {
                    const text = await r.text();
                    throw new Error(text || "Save failed");
                }
                return r.json();
            })
            .then(data => {
                setFeed(data);

                notifications.show({
                    color: "green",
                    message: "Feed saved",
                    autoClose: 2000
                });

                if (id === "new") {
                    navigate(`/feeds/${data.id}`, { replace: true });
                }
            })
            .catch(err => {
                notifications.show({
                    color: "red",
                    title: "Error",
                    message: err.message || "Failed to save feed"
                });
            })
            .finally(() => {
                setSaving(false);
            });
    }

    async function handleImageSelect(e: ChangeEvent<HTMLInputElement>) {

        const file = e.target.files?.[0];
        if (!file) return;

        const { url } = await uploadFile("/api/v1/uploads", file);

        const editor = editorRef.current;
        const selection = editor.getSelection();

        editor.executeEdits("", [
            {
                range: selection,
                text: `<img src="${url}" alt="">`
            }
        ]);
    }

    if (!feed) {
        return "Loading...";
    }

    return (
        <Stack>

            <Group>
                <Button
                    variant="light"
                    onClick={() => navigate("/feeds")}
                >
                    ← Back
                </Button>
            </Group>

            <TextInput
                label="Title"
                value={feed.title || ""}
                onChange={(e) =>
                    setFeed({ ...feed, title: e.currentTarget.value })
                }
            />

            <TextInput
                label="Slug"
                value={feed.slug || ""}
                onChange={(e) =>
                    setFeed({ ...feed, slug: e.currentTarget.value })
                }
            />

            <Select
                label="Type"
                data={FEED_TYPES}
                value={feed.type || ""}
                onChange={(value) =>
                    setFeed({ ...feed, type: value || "" })
                }
                searchable
                allowDeselect={false}
            />

            <TextInput
                label="parentId"
                value={feed.parentId || ""}
                onChange={(e) =>
                    setFeed({ ...feed, parentId: e.currentTarget.value })
                }
            />

            <TextInput
                label="Description"
                value={feed.description || ""}
                onChange={(e) =>
                    setFeed({ ...feed, description: e.currentTarget.value })
                }
            />

            <TextInput
                label="imageUrl"
                value={feed.imageUrl || ""}
                onChange={(e) =>
                    setFeed({ ...feed, imageUrl: e.currentTarget.value })
                }
            />

            <Group justify="space-between">
                <Text fw={500}>Content</Text>

                <Group>

                    <ActionIcon
                        variant="default"
                        onClick={() => fileInputRef.current?.click()}
                    >
                        <IconFileUpload />
                    </ActionIcon>

                </Group>
            </Group>

            <Editor
                height="400px"
                theme={colorScheme === "dark" ? "vs-dark" : "light"}
                defaultLanguage="html"
                value={feed.content}
                onMount={(editor, _monaco) => {

                    editorRef.current = editor;

                    editor.getAction("editor.action.formatDocument")?.run();
                }}
                onChange={(value) =>
                    setFeed({
                        ...feed,
                        content: value || ""
                    })
                }
                options={{
                    fontSize: 14,
                    minimap: { enabled: false },
                    wordWrap: "on",
                    automaticLayout: true
                }}
            />

            <input
                ref={fileInputRef}
                type="file"
                accept="image/*"
                style={{ display: "none" }}
                onChange={handleImageSelect}
            />

            <Button
                loading={saving}
                onClick={save}
            >
                Save
            </Button>

        </Stack>
    );
}
