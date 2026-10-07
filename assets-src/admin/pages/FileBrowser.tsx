import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ActionIcon, Alert, Box, Group, Loader, Stack, Text, Tooltip, useComputedColorScheme } from "@mantine/core";
import { IconRefresh } from "@tabler/icons-react";
import {
    ChonkyActions,
    FullFileBrowser,
    type FileActionHandler,
} from "@samuelncui/chonky";
import { ChonkyIconFA } from "@samuelncui/chonky-icon-fontawesome";
import { getApiResponseError } from "../../shared/api-errors";
import { csrfHeaders } from "../../shared/csrf";
import { getLocale, trans } from "../../shared/i18n";
import {
    parentUploadPath,
    toBrowserFile,
    type UploadBrowserFile,
    type UploadBrowserPayload,
} from "../lib/uploads-browser";

const FILE_ACTIONS = [ChonkyActions.UploadFiles];
const FILE_ACCEPT = ".jpg,.jpeg,.png,.webp,.gif,.mp3,.ogg,.pdf";

async function responseJson(response: Response): Promise<UploadBrowserPayload> {
    const body = await response.json().catch(() => null);
    if (!response.ok) {
        throw new Error(typeof body?.error === "string" ? body.error : trans("js.admin.file_browser.load_failed"));
    }

    return body as UploadBrowserPayload;
}

export default function FileBrowser() {
    const fileInput = useRef<HTMLInputElement>(null);
    const colorScheme = useComputedColorScheme("light");
    const [path, setPath] = useState("");
    const [payload, setPayload] = useState<UploadBrowserPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [uploading, setUploading] = useState(false);
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

    const uploadFiles = useCallback(async (selected: File[]) => {
        if (selected.length === 0) return;

        setUploading(true);
        setError(null);
        let uploaded = false;
        let firstError: Error | null = null;

        for (const file of selected) {
            const body = new FormData();
            body.append("path", path);
            body.append("file", file);

            try {
                const response = await fetch("/api/v1/admin/file-browser", {
                    method: "POST",
                    body,
                    credentials: "include",
                    headers: csrfHeaders(),
                });
                if (!response.ok) {
                    throw await getApiResponseError(response, trans("js.admin.file_browser.upload_failed"));
                }
                uploaded = true;
            } catch (error) {
                firstError ??= error instanceof Error
                    ? error
                    : new Error(trans("js.admin.file_browser.upload_failed"));
            }
        }

        if (uploaded) setRefreshKey(key => key + 1);
        if (firstError) setError(firstError.message);
        setUploading(false);
    }, [path]);

    const handleFileAction = useCallback<FileActionHandler>(data => {
        if (data.id === ChonkyActions.UploadFiles.id) {
            if (!uploading) fileInput.current?.click();
            return;
        }

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
    }, [uploading]);

    return (
        <Stack h="calc(100vh - 92px)" mih={480} gap="sm">
            <input
                ref={fileInput}
                type="file"
                accept={FILE_ACCEPT}
                multiple
                hidden
                onChange={event => {
                    const selected = Array.from(event.currentTarget.files ?? []);
                    event.currentTarget.value = "";
                    void uploadFiles(selected);
                }}
            />
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
                        {loading || uploading ? <Loader size={16} /> : <IconRefresh size={18} />}
                    </ActionIcon>
                </Tooltip>
            </Group>

            {error && <Alert color="red" title={trans("js.admin.error")}>{error}</Alert>}

            <Box flex={1} mih={0} style={{ opacity: loading && payload ? 0.65 : 1 }}>
                <FullFileBrowser
                    files={files}
                    folderChain={folderChain}
                    fileActions={FILE_ACTIONS}
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
