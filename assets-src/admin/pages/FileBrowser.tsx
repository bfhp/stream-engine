import { useCallback, useEffect, useMemo, useState } from "react";
import { ActionIcon, Alert, Box, Group, Loader, Stack, Text, Tooltip, useComputedColorScheme } from "@mantine/core";
import { IconRefresh } from "@tabler/icons-react";
import {
    ChonkyActions,
    FullFileBrowser,
    type FileActionHandler,
} from "@samuelncui/chonky";
import { ChonkyIconFA } from "@samuelncui/chonky-icon-fontawesome";
import { getLocale, trans } from "../../shared/i18n";
import {
    parentUploadPath,
    toBrowserFile,
    type UploadBrowserFile,
    type UploadBrowserPayload,
} from "../lib/uploads-browser";

async function responseJson(response: Response): Promise<UploadBrowserPayload> {
    const body = await response.json().catch(() => null);
    if (!response.ok) {
        throw new Error(typeof body?.error === "string" ? body.error : trans("js.admin.file_browser.load_failed"));
    }

    return body as UploadBrowserPayload;
}

export default function FileBrowser() {
    const colorScheme = useComputedColorScheme("light");
    const [path, setPath] = useState("");
    const [payload, setPayload] = useState<UploadBrowserPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        const query = path === "" ? "" : `?path=${encodeURIComponent(path)}`;
        setLoading(true);
        setError(null);

        fetch(`/api/v1/admin/file-browser${query}`, { signal: controller.signal })
            .then(responseJson)
            .then(setPayload)
            .catch(err => {
                if (err.name !== "AbortError") {
                    setError(err.message || trans("js.admin.file_browser.load_failed"));
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [path, refreshKey]);

    const files = useMemo(() => (payload?.files ?? []).map(toBrowserFile), [payload]);
    const folderChain = useMemo(() => (payload?.folderChain ?? []).map(toBrowserFile), [payload]);

    const handleFileAction = useCallback<FileActionHandler>(data => {
        if (data.id === ChonkyActions.OpenParentFolder.id) {
            setPath(current => parentUploadPath(current));
            return;
        }

        if (data.id !== ChonkyActions.OpenFiles.id) {
            return;
        }

        const target = (data.payload.targetFile ?? data.payload.files[0]) as UploadBrowserFile | undefined;
        if (!target) {
            return;
        }

        if (target.isDir) {
            setPath(target.path);
        } else if (target.publicUrl) {
            window.open(target.publicUrl, "_blank", "noopener,noreferrer");
        }
    }, []);

    return (
        <Stack h="calc(100vh - 92px)" mih={480} gap="sm">
            <Group justify="space-between" align="end">
                <div>
                    <Text fw={700} size="xl">{trans("js.admin.file_browser.title")}</Text>
                    <Text c="dimmed" size="sm">{trans("js.admin.file_browser.help")}</Text>
                </div>
                <Tooltip label={trans("js.admin.refresh")}>
                    <ActionIcon
                        variant="default"
                        aria-label={trans("js.admin.refresh")}
                        onClick={() => setRefreshKey(key => key + 1)}
                    >
                        {loading ? <Loader size={16} /> : <IconRefresh size={18} />}
                    </ActionIcon>
                </Tooltip>
            </Group>

            {error && <Alert color="red" title={trans("js.admin.error")}>{error}</Alert>}

            <Box flex={1} mih={0} style={{ opacity: loading && payload ? 0.65 : 1 }}>
                <FullFileBrowser
                    files={files}
                    folderChain={folderChain}
                    onFileAction={handleFileAction}
                    iconComponent={ChonkyIconFA}
                    disableDragAndDrop
                    darkMode={colorScheme === "dark"}
                    i18n={{ locale: getLocale() }}
                />
            </Box>
        </Stack>
    );
}
