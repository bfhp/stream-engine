import type { FileData } from "@samuelncui/chonky";

export type UploadBrowserEntry = {
    id: string;
    path: string;
    name: string;
    isDir: boolean;
    size?: number;
    modifiedAt?: number | null;
    childrenCount?: number;
    extension?: string;
};

export type UploadBrowserPayload = {
    path: string;
    files: UploadBrowserEntry[];
    folderChain: UploadBrowserEntry[];
};

export type UploadBrowserFile = FileData & {
    path: string;
    publicUrl?: string;
};

export function publicUploadUrl(path: string): string {
    return `/uploads/${path.split("/").map(encodeURIComponent).join("/")}`;
}

export function parentUploadPath(path: string): string {
    const segments = path.split("/").filter(Boolean);
    segments.pop();

    return segments.join("/");
}

export function toBrowserFile(entry: UploadBrowserEntry): UploadBrowserFile {
    const publicUrl = entry.isDir ? undefined : publicUploadUrl(entry.path);
    const image = !entry.isDir && /^(?:gif|jpe?g|png|webp)$/i.test(entry.extension ?? "");

    return {
        id: entry.id,
        path: entry.path,
        name: entry.name,
        isDir: entry.isDir,
        size: entry.size,
        modDate: entry.modifiedAt ? new Date(entry.modifiedAt * 1000) : undefined,
        childrenCount: entry.childrenCount,
        ext: entry.extension,
        openable: true,
        selectable: true,
        draggable: false,
        droppable: false,
        publicUrl,
        thumbnailUrl: image ? publicUrl : undefined,
    };
}
