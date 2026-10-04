import { useEffect, useState, ChangeEvent, useRef } from "react";
import { useParams, useNavigate } from "react-router-dom";

import Editor from "@monaco-editor/react";

import { TextInput, Button, Stack, Group, Grid, ActionIcon, Text, Select, NumberInput, useComputedColorScheme } from "@mantine/core";
import { notifications } from "@mantine/notifications";

import { IconFileUpload } from "@tabler/icons-react";
import { FEED_TYPES } from "../../shared/feed-types";
import { uploadFile } from "../../shared/uploads";
import { csrfHeaders } from "../../shared/csrf";
import { trans } from "../../shared/i18n";
import { isRtl } from "../lib/direction";

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
                visibility: "public",
                position: 0,
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
                    throw new Error(text || trans("js.admin.save_failed"));
                }
                return r.json();
            })
            .then(data => {
                setFeed(data);

                notifications.show({
                    color: "green",
                    message: trans("js.admin.feeds.saved"),
                    autoClose: 2000
                });

                if (id === "new") {
                    navigate(`/feeds/${data.id}`, { replace: true });
                }
            })
            .catch(err => {
                notifications.show({
                    color: "red",
                    title: trans("js.admin.error"),
                    message: err.message || trans("js.admin.feeds.save_failed")
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
        return trans("js.admin.loading");
    }

    return (
        <Stack>

            <Group>
                <Button
                    variant="light"
                    onClick={() => navigate("/feeds")}
                >
                    {isRtl() ? "→" : "←"} {trans("js.admin.back")}
                </Button>
            </Group>

            <Grid gutter="md">
                <Grid.Col span={{ base: 12, md: 8 }}>
                    <TextInput
                        label={trans("js.admin.title")}
                        value={feed.title || ""}
                        onChange={(e) =>
                            setFeed({ ...feed, title: e.currentTarget.value })
                        }
                    />
                </Grid.Col>

                <Grid.Col span={{ base: 12, sm: 6, md: 4 }}>
                    <Select
                        label={trans("js.admin.type")}
                        data={FEED_TYPES}
                        value={feed.type || ""}
                        onChange={(value) =>
                            setFeed({ ...feed, type: value || "" })
                        }
                        searchable
                        allowDeselect={false}
                    />
                </Grid.Col>

                <Grid.Col span={{ base: 12, sm: 6, md: 5 }}>
                    <TextInput
                        label={trans("js.admin.slug")}
                        value={feed.slug || ""}
                        onChange={(e) =>
                            setFeed({ ...feed, slug: e.currentTarget.value })
                        }
                    />
                </Grid.Col>

                <Grid.Col span={{ base: 12, sm: 6, md: 2 }}>
                    <NumberInput
                        label={trans("js.admin.parent_id")}
                        min={1}
                        allowDecimal={false}
                        allowNegative={false}
                        value={feed.parentId ?? ""}
                        onChange={(value) =>
                            setFeed({ ...feed, parentId: value })
                        }
                    />
                </Grid.Col>

                <Grid.Col span={{ base: 12, sm: 6, md: 3 }}>
                    <Select
                        label={trans("js.admin.visibility")}
                        data={["public", "members", "private"]}
                        value={feed.visibility || "public"}
                        onChange={(value) =>
                            setFeed({ ...feed, visibility: value || "public" })
                        }
                        allowDeselect={false}
                    />
                </Grid.Col>

                <Grid.Col span={{ base: 12, sm: 6, md: 2 }}>
                    <NumberInput
                        label={trans("js.admin.position")}
                        min={0}
                        max={4294967295}
                        allowDecimal={false}
                        allowNegative={false}
                        value={feed.position ?? 0}
                        onChange={(value) =>
                            setFeed({ ...feed, position: typeof value === "number" ? value : 0 })
                        }
                    />
                </Grid.Col>

                <Grid.Col span={{ base: 12, md: 6 }}>
                    <TextInput
                        label={trans("js.admin.description")}
                        value={feed.description || ""}
                        onChange={(e) =>
                            setFeed({ ...feed, description: e.currentTarget.value })
                        }
                    />
                </Grid.Col>

                <Grid.Col span={{ base: 12, md: 6 }}>
                    <TextInput
                        label={trans("js.admin.image_url")}
                        value={feed.imageUrl || ""}
                        onChange={(e) =>
                            setFeed({ ...feed, imageUrl: e.currentTarget.value })
                        }
                    />
                </Grid.Col>
            </Grid>

            <Group justify="space-between">
                <Text fw={500}>{trans("js.admin.content")}</Text>

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
                {trans("js.admin.save")}
            </Button>

        </Stack>
    );
}
