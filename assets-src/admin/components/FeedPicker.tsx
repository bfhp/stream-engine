import { useEffect, useMemo, useState } from "react";
import { Anchor, Group, Select, Stack, Text, type ComboboxItem } from "@mantine/core";
import { useDebouncedValue } from "@mantine/hooks";
import { IconExternalLink, IconPlus } from "@tabler/icons-react";
import { Link } from "react-router-dom";
import { getApiResponseError } from "../../shared/api-errors";
import { FEED_TYPE_LABELS } from "../../shared/feed-types";
import { trans } from "../../shared/i18n";
import {
    buildFeedSearchQueries,
    feedDisplayTitle,
    feedIdFromQuery,
    FULLTEXT_MIN_LENGTH,
    isFeedTypeAllowed,
    mergeFeedResults,
    type BoundPage,
    type PickerFeed
} from "../lib/feed-picker";
import FeedQuickCreateModal from "./FeedQuickCreateModal";

type Props = {
    value: number | "";
    onChange: (value: number | "") => void;
    /** Feed types accepted by the page action; empty/undefined means any. */
    feedTypes?: string[];
    /** Feeds already mounted on other pages. */
    boundPages: Map<number, BoundPage>;
    /** Title of the current feed known from the page payload, shown before the lookup finishes. */
    initialTitle?: string | null;
    defaultCreateType?: string;
    label: string;
    description?: string;
    error?: string;
    required?: boolean;
    disabled?: boolean;
};

type FeedOption = ComboboxItem & { feed: PickerFeed };

function optionLabel(feed: PickerFeed): string {
    return `${feedDisplayTitle(feed)} (#${feed.id})`;
}

function boundPageLabel(page: BoundPage): string {
    return trans("js.admin.feed_picker.bound_to", {
        id: page.id,
        path: page.pageName ? `${page.path} — ${page.pageName}` : page.path
    });
}

/**
 * Searchable feed selector for the page editor: finds feeds by title or ID
 * (restricted to the types the page action accepts), marks feeds already
 * mounted on another page as unavailable, and can create a feed in place.
 */
export default function FeedPicker({
    value,
    onChange,
    feedTypes,
    boundPages,
    initialTitle,
    defaultCreateType,
    label,
    description,
    error,
    required,
    disabled
}: Props) {
    const [selectedFeed, setSelectedFeed] = useState<PickerFeed | null>(null);
    const [results, setResults] = useState<PickerFeed[]>([]);
    const [search, setSearch] = useState("");
    const [debouncedSearch] = useDebouncedValue(search, 250);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [dropdownOpened, setDropdownOpened] = useState(false);
    const [createOpened, setCreateOpened] = useState(false);
    const [createTitle, setCreateTitle] = useState("");
    const typesKey = (feedTypes ?? []).join(",");

    // Resolve the selected ID into a feed record (title, type, slug).
    useEffect(() => {
        if (value === "") {
            setSelectedFeed(null);
            setSearch("");
            return;
        }

        if (selectedFeed?.id === value) {
            return;
        }

        const placeholder: PickerFeed = { id: value, title: initialTitle ?? null };
        setSelectedFeed(placeholder);
        setSearch(optionLabel(placeholder));

        const controller = new AbortController();
        fetch(`/api/v1/feeds?id=${value}&limit=1`, { signal: controller.signal })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                const feed: PickerFeed | undefined = Array.isArray(data?.data) ? data.data[0] : undefined;
                if (feed && feed.id === value) {
                    setSelectedFeed(feed);
                    setSearch(current => current === optionLabel(placeholder) ? optionLabel(feed) : current);
                }
            })
            .catch(() => undefined);

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    const selectedLabel = selectedFeed ? optionLabel(selectedFeed) : "";
    // While the input just shows the selected option, list the newest feeds instead of searching for that label.
    const effectiveQuery = debouncedSearch === selectedLabel ? "" : debouncedSearch;

    useEffect(() => {
        if (!dropdownOpened || disabled) {
            return;
        }

        const queries = buildFeedSearchQueries(effectiveQuery, feedTypes);
        if (queries.length === 0) {
            setResults([]);
            setLoadError(null);
            return;
        }

        const controller = new AbortController();
        setLoading(true);
        setLoadError(null);

        Promise.all(queries.map(query => fetch(`/api/v1/feeds?${query}`, { signal: controller.signal })
            .then(async response => {
                if (!response.ok) {
                    throw await getApiResponseError(response, trans("js.admin.feeds.load_failed"));
                }

                const data = await response.json();

                return Array.isArray(data.data) ? data.data as PickerFeed[] : [];
            })))
            .then(groups => setResults(mergeFeedResults(groups)))
            .catch(err => {
                if (err.name !== "AbortError") {
                    setResults([]);
                    setLoadError(err.message || trans("js.admin.feeds.load_failed"));
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [effectiveQuery, typesKey, dropdownOpened, disabled]);

    const options = useMemo<FeedOption[]>(() => {
        const feeds = [...results];
        if (selectedFeed && !feeds.some(feed => feed.id === selectedFeed.id)) {
            feeds.unshift(selectedFeed);
        }

        return feeds.map(feed => {
            const isCurrent = feed.id === value;
            const unavailable = !isCurrent && (boundPages.has(feed.id) || !isFeedTypeAllowed(feed, feedTypes));

            return { value: String(feed.id), label: optionLabel(feed), disabled: unavailable, feed };
        });
    }, [boundPages, feedTypes, results, selectedFeed, value]);

    const optionsByValue = useMemo(() => new Map(options.map(option => [option.value, option])), [options]);

    const nothingFound = loadError
        ?? (buildFeedSearchQueries(effectiveQuery, feedTypes).length === 0
            ? trans("js.admin.feed_picker.type_more", { count: FULLTEXT_MIN_LENGTH })
            : loading ? trans("js.admin.loading") : trans("js.admin.feed_picker.nothing_found"));

    const currentBinding = value !== "" ? boundPages.get(value) : undefined;
    const canCreate = !disabled;

    function openCreate() {
        const typed = search === selectedLabel ? "" : search.trim();
        setCreateTitle(feedIdFromQuery(typed) === null ? typed : "");
        setDropdownOpened(false);
        setCreateOpened(true);
    }

    return (
        <Stack gap={4}>
            <Select
                label={label}
                description={description}
                error={error ?? (currentBinding ? boundPageLabel(currentBinding) : undefined)}
                required={required}
                disabled={disabled}
                placeholder={trans("js.admin.feed_picker.placeholder")}
                searchable
                clearable
                data={options}
                value={value === "" ? null : String(value)}
                searchValue={search}
                onSearchChange={setSearch}
                onChange={next => {
                    if (next === null) {
                        setSelectedFeed(null);
                        onChange("");
                        return;
                    }

                    const option = optionsByValue.get(next);
                    if (option) {
                        setSelectedFeed(option.feed);
                    }
                    onChange(Number(next));
                }}
                filter={({ options: items }) => items}
                onDropdownOpen={() => setDropdownOpened(true)}
                onDropdownClose={() => {
                    setDropdownOpened(false);
                    if (selectedFeed) {
                        setSearch(optionLabel(selectedFeed));
                    } else {
                        setSearch("");
                    }
                }}
                nothingFoundMessage={nothingFound}
                maxDropdownHeight={320}
                renderOption={({ option }) => {
                    const feed = optionsByValue.get(option.value)?.feed;
                    if (!feed) {
                        return option.label;
                    }

                    const bound = feed.id !== value ? boundPages.get(feed.id) : undefined;
                    const wrongType = !isFeedTypeAllowed(feed, feedTypes);
                    const details = [
                        `#${feed.id}`,
                        feed.type ? (FEED_TYPE_LABELS[feed.type] ?? feed.type) : null,
                        feed.slug || null,
                        feed.parentId ? trans("js.admin.feed_picker.parent", { id: feed.parentId }) : null
                    ].filter(Boolean).join(" · ");

                    return (
                        <Stack gap={0}>
                            <Text size="sm">{feedDisplayTitle(feed)}</Text>
                            <Text size="xs" c="dimmed">{details}</Text>
                            {bound && <Text size="xs" c="orange">{boundPageLabel(bound)}</Text>}
                            {wrongType && <Text size="xs" c="orange">{trans("js.admin.feed_picker.wrong_type")}</Text>}
                        </Stack>
                    );
                }}
            />
            <Group gap="md">
                {canCreate && (
                    <Anchor component="button" type="button" size="sm" onClick={openCreate}>
                        <Group gap={4} wrap="nowrap">
                            <IconPlus size={14} />
                            {trans("js.admin.feed_picker.create")}
                        </Group>
                    </Anchor>
                )}
                {value !== "" && (
                    <Anchor component={Link} to={`/feeds/${value}`} size="sm">
                        <Group gap={4} wrap="nowrap">
                            <IconExternalLink size={14} />
                            {trans("js.admin.feed_picker.open")}
                        </Group>
                    </Anchor>
                )}
            </Group>

            <FeedQuickCreateModal
                opened={createOpened}
                onClose={() => setCreateOpened(false)}
                feedTypes={feedTypes}
                initialTitle={createTitle}
                defaultType={defaultCreateType}
                onCreated={feed => {
                    setCreateOpened(false);
                    setSelectedFeed(feed);
                    setSearch(optionLabel(feed));
                    setResults(current => [feed, ...current.filter(item => item.id !== feed.id)]);
                    onChange(feed.id);
                }}
            />
        </Stack>
    );
}
