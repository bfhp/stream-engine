/**
 * Helpers for the feed metadata editor in admin FeedEdit.
 *
 * The API returns `metadata` as a name => content map (an empty PHP array
 * serializes as `[]`), and PATCH /api/v1/feeds/{id} replaces the whole set
 * (FeedMetadataRepository::replaceForFeed), so the editor always sends the
 * full map built from its rows - a removed row is a deleted key.
 */

export type MetadataRow = {
    key: string;   // stable React key, not the metadata name
    name: string;
    content: string;
};

// Mirrors FeedService::normalizeMetadataInput() / MAX_METADATA_NAME_LENGTH.
export const METADATA_NAME_PATTERN = /^[a-z0-9][a-z0-9_.-]*$/;
export const METADATA_NAME_MAX_LENGTH = 64;

let rowSeq = 0;

export function newMetadataRow(name = "", content = ""): MetadataRow {
    rowSeq += 1;
    return { key: `m${rowSeq}`, name, content };
}

export function metadataToRows(metadata: unknown): MetadataRow[] {
    if (!metadata || typeof metadata !== "object" || Array.isArray(metadata)) {
        return [];
    }

    return Object.entries(metadata as Record<string, unknown>)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([name, content]) => newMetadataRow(name, content == null ? "" : String(content)));
}

export function normalizeMetadataName(name: string): string {
    return name.trim().toLowerCase();
}

export type MetadataRowError = "invalid_name" | "duplicate_name" | null;

/** Per-row validation error, keyed by MetadataRow.key. Blank rows are ignored. */
export function validateMetadataRows(rows: MetadataRow[]): Record<string, MetadataRowError> {
    const errors: Record<string, MetadataRowError> = {};
    const seen = new Set<string>();

    for (const row of rows) {
        const name = normalizeMetadataName(row.name);
        errors[row.key] = null;

        if (name === "") {
            continue;
        }

        if (name.length > METADATA_NAME_MAX_LENGTH || !METADATA_NAME_PATTERN.test(name)) {
            errors[row.key] = "invalid_name";
        } else if (seen.has(name)) {
            errors[row.key] = "duplicate_name";
        }

        seen.add(name);
    }

    return errors;
}

export function hasMetadataErrors(errors: Record<string, MetadataRowError>): boolean {
    return Object.values(errors).some(error => error !== null);
}

/** Builds the PATCH payload; rows with an empty name are dropped. */
export function rowsToMetadata(rows: MetadataRow[]): Record<string, string> {
    const result: Record<string, string> = {};

    for (const row of rows) {
        const name = normalizeMetadataName(row.name);
        if (name !== "") {
            result[name] = row.content;
        }
    }

    return result;
}
