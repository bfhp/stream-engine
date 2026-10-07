import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
    ActionIcon, Alert, Box, Button, Group, Loader, Modal, Stack, Text, TextInput, Tooltip,
    useComputedColorScheme,
} from "@mantine/core";
import { IconRefresh } from "@tabler/icons-react";
import {
    ChonkyActions,
    ChonkyIconName,
    CustomVisibilityState,
    defineFileAction,
    FullFileBrowser,
    type FileAction,
    type FileActionData,
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

const FILE_ACCEPT = ".jpg,.jpeg,.png,.webp,.gif,.mp3,.ogg,.pdf";
const RENAME_ACTION_ID = "rename_file_browser_entry";

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
    const [folderDialogOpened, setFolderDialogOpened] = useState(false);
    const [folderName, setFolderName] = useState("");
    const [creatingFolder, setCreatingFolder] = useState(false);
    const [folderError, setFolderError] = useState<string | null>(null);
    const [renameFile, setRenameFile] = useState<UploadBrowserFile | null>(null);
    const [renameName, setRenameName] = useState("");
    const [renameError, setRenameError] = useState<string | null>(null);
    const [relocating, setRelocating] = useState(false);
    const [deleteFiles, setDeleteFiles] = useState<UploadBrowserFile[]>([]);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);

    const fileActions = useMemo(() => [
        ChonkyActions.CreateFolder,
        ChonkyActions.UploadFiles,
        ChonkyActions.DeleteFiles,
        defineFileAction({
            id: RENAME_ACTION_ID,
            requiresSelection: true,
            button: {
                name: trans("js.admin.file_browser.rename"),
                toolbar: true,
                contextMenu: true,
                group: "Actions",
                icon: ChonkyIconName.file,
            },
            customVisibility: state => state.selectedFilesForAction.length === 1
                ? CustomVisibilityState.Default
                : CustomVisibilityState.Disabled,
        }),
    ], []);

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

    const createDirectory = useCallback(async () => {
        const name = folderName.trim();
        if (name === "") return;

        setCreatingFolder(true);
        setFolderError(null);
        try {
            const response = await fetch("/api/v1/admin/file-browser", {
                method: "POST",
                credentials: "include",
                headers: { "Content-Type": "application/json", ...csrfHeaders() },
                body: JSON.stringify({ operation: "create-directory", path, name }),
            });
            if (!response.ok) {
                throw await getApiResponseError(response, trans("js.admin.file_browser.create_directory_failed"));
            }

            setPayload(await response.json() as UploadBrowserPayload);
            setFolderName("");
            setFolderDialogOpened(false);
        } catch (error) {
            setFolderError(error instanceof Error
                ? error.message
                : trans("js.admin.file_browser.create_directory_failed"));
        } finally {
            setCreatingFolder(false);
        }
    }, [folderName, path]);

    const relocateEntry = useCallback(async (
        source: UploadBrowserFile,
        destination: string,
        name: string,
        fallbackError: string,
    ) => {
        const response = await fetch("/api/v1/admin/file-browser", {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json", ...csrfHeaders() },
            body: JSON.stringify({ operation: "relocate", source: source.path, destination, name }),
        });
        if (!response.ok) {
            throw await getApiResponseError(response, fallbackError);
        }

        return await response.json() as UploadBrowserPayload;
    }, []);

    const renameEntry = useCallback(async () => {
        const name = renameName.trim();
        if (!renameFile || name === "") return;

        setRelocating(true);
        setRenameError(null);
        try {
            setPayload(await relocateEntry(
                renameFile,
                parentUploadPath(renameFile.path),
                name,
                trans("js.admin.file_browser.rename_failed"),
            ));
            setRenameFile(null);
            setRenameName("");
        } catch (error) {
            setRenameError(error instanceof Error
                ? error.message
                : trans("js.admin.file_browser.rename_failed"));
        } finally {
            setRelocating(false);
        }
    }, [relocateEntry, renameFile, renameName]);

    const deleteEntries = useCallback(async () => {
        if (deleteFiles.length === 0) return;

        setDeleting(true);
        setDeleteError(null);
        try {
            const response = await fetch("/api/v1/admin/file-browser", {
                method: "POST",
                credentials: "include",
                headers: { "Content-Type": "application/json", ...csrfHeaders() },
                body: JSON.stringify({ operation: "delete", paths: deleteFiles.map(file => file.path) }),
            });
            if (!response.ok) {
                throw await getApiResponseError(response, trans("js.admin.file_browser.delete_failed"));
            }

            setPayload(await response.json() as UploadBrowserPayload);
            setDeleteFiles([]);
        } catch (error) {
            setDeleteError(error instanceof Error
                ? error.message
                : trans("js.admin.file_browser.delete_failed"));
        } finally {
            setDeleting(false);
        }
    }, [deleteFiles]);

    const handleFileAction = useCallback((data: FileActionData<FileAction>) => {
        if (data.id === ChonkyActions.CreateFolder.id) {
            if (!creatingFolder && !uploading && !relocating && !deleting) {
                setFolderError(null);
                setFolderDialogOpened(true);
            }
            return;
        }

        if (data.id === ChonkyActions.UploadFiles.id) {
            if (!uploading && !relocating && !deleting) fileInput.current?.click();
            return;
        }

        if (data.id === ChonkyActions.DeleteFiles.id) {
            const selected = data.state.selectedFilesForAction as UploadBrowserFile[];
            if (selected.length > 0 && !uploading && !creatingFolder && !relocating && !deleting) {
                setDeleteError(null);
                setDeleteFiles(selected);
            }
            return;
        }

        if (data.id === RENAME_ACTION_ID) {
            const target = (data.state.contextMenuTriggerFile
                ?? data.state.selectedFilesForAction[0]) as UploadBrowserFile | undefined;
            if (target && data.state.selectedFilesForAction.length === 1
                && !uploading && !creatingFolder && !relocating && !deleting) {
                setRenameError(null);
                setRenameFile(target);
                setRenameName(target.name);
            }
            return;
        }

        if (data.id === ChonkyActions.MoveFiles.id) {
            if (uploading || creatingFolder || relocating || deleting) return;
            const move = data.payload as unknown as {
                files: UploadBrowserFile[];
                destination: UploadBrowserFile;
            };
            const destination = move.destination?.path;
            if (typeof destination !== "string") return;

            setRelocating(true);
            setError(null);
            void (async () => {
                try {
                    for (const source of move.files) {
                        await relocateEntry(
                            source,
                            destination,
                            source.name,
                            trans("js.admin.file_browser.move_failed"),
                        );
                    }
                    setRefreshKey(key => key + 1);
                } catch (error) {
                    setError(error instanceof Error
                        ? error.message
                        : trans("js.admin.file_browser.move_failed"));
                    setRefreshKey(key => key + 1);
                } finally {
                    setRelocating(false);
                }
            })();
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
    }, [creatingFolder, deleting, relocateEntry, relocating, uploading]);

    return (
        <Stack h="calc(100vh - 92px)" mih={480} gap="sm">
            <Modal
                opened={folderDialogOpened}
                onClose={() => !creatingFolder && setFolderDialogOpened(false)}
                title={trans("js.admin.file_browser.new_folder")}
                centered
            >
                <form onSubmit={event => { event.preventDefault(); void createDirectory(); }}>
                    <Stack>
                        {folderError && <Alert color="red">{folderError}</Alert>}
                        <TextInput
                            label={trans("js.admin.file_browser.folder_name")}
                            value={folderName}
                            onChange={event => setFolderName(event.currentTarget.value)}
                            maxLength={255}
                            autoFocus
                            required
                        />
                        <Group justify="flex-end">
                            <Button
                                variant="default"
                                disabled={creatingFolder}
                                onClick={() => setFolderDialogOpened(false)}
                            >
                                {trans("js.admin.cancel")}
                            </Button>
                            <Button type="submit" loading={creatingFolder} disabled={folderName.trim() === ""}>
                                {trans("js.admin.save")}
                            </Button>
                        </Group>
                    </Stack>
                </form>
            </Modal>
            <Modal
                opened={renameFile !== null}
                onClose={() => !relocating && setRenameFile(null)}
                title={trans("js.admin.file_browser.rename")}
                centered
            >
                <form onSubmit={event => { event.preventDefault(); void renameEntry(); }}>
                    <Stack>
                        {renameError && <Alert color="red">{renameError}</Alert>}
                        <TextInput
                            label={trans("js.admin.file_browser.name")}
                            value={renameName}
                            onChange={event => setRenameName(event.currentTarget.value)}
                            maxLength={255}
                            autoFocus
                            required
                        />
                        <Group justify="flex-end">
                            <Button
                                variant="default"
                                disabled={relocating}
                                onClick={() => setRenameFile(null)}
                            >
                                {trans("js.admin.cancel")}
                            </Button>
                            <Button type="submit" loading={relocating} disabled={renameName.trim() === ""}>
                                {trans("js.admin.save")}
                            </Button>
                        </Group>
                    </Stack>
                </form>
            </Modal>
            <Modal
                opened={deleteFiles.length > 0}
                onClose={() => !deleting && setDeleteFiles([])}
                title={trans("js.admin.file_browser.delete")}
                centered
            >
                <Stack>
                    {deleteError && <Alert color="red">{deleteError}</Alert>}
                    <Text>{trans("js.admin.file_browser.delete_confirmation")}</Text>
                    <Text size="sm" c="dimmed">
                        {deleteFiles.slice(0, 5).map(file => file.name).join(", ")}
                        {deleteFiles.length > 5 ? ` … (+${deleteFiles.length - 5})` : ""}
                    </Text>
                    <Group justify="flex-end">
                        <Button
                            variant="default"
                            disabled={deleting}
                            onClick={() => setDeleteFiles([])}
                        >
                            {trans("js.admin.cancel")}
                        </Button>
                        <Button color="red" loading={deleting} onClick={() => void deleteEntries()}>
                            {trans("js.admin.file_browser.delete")}
                        </Button>
                    </Group>
                </Stack>
            </Modal>
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
                        {loading || uploading || relocating || deleting
                            ? <Loader size={16} />
                            : <IconRefresh size={18} />}
                    </ActionIcon>
                </Tooltip>
            </Group>

            {error && <Alert color="red" title={trans("js.admin.error")}>{error}</Alert>}

            <Box flex={1} mih={0} style={{ opacity: loading && payload ? 0.65 : 1 }}>
                <FullFileBrowser
                    files={files}
                    folderChain={folderChain}
                    fileActions={fileActions}
                    onFileAction={handleFileAction}
                    iconComponent={ChonkyIconFA}
                    disableDragAndDrop={uploading || creatingFolder || relocating || deleting}
                    darkMode={colorScheme === "dark"}
                    i18n={{ locale: getLocale() }}
                />
            </Box>
        </Stack>
    );
}
