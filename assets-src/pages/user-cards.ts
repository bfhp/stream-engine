/* ==========================================================================
   Feed post cards

   Cards appended by "show more" are copies of the theme's own
   `components/users/post-card.twig`, rendered by the page as
   `<template data-ui="post-card">` (and `post-card-tag` for one tag). This
   module only maps an API item onto that template's fill points, so the
   appended cards look exactly like the server-rendered ones in every theme
   and there is no second copy of the markup to keep in sync.
   ========================================================================== */

import { ui, type SlotData } from "../shared/ui";

export interface FeedPostCard {
    title: string | null;
    url: string | null;
    imageUrl: string | null;
    dateLabel: string | null;
    readTimeLabel: string | null;
    excerpt: string | null;
    tags: string[];
    commentCount: number;
    ratingAverage: number;
    ratingCount: number;
    audioTrackId?: string | null;
    // Only present (and only rendered) on community feeds - see
    // post-card.twig's showAuthor.
    authorName?: string;
    authorAvatarUrl?: string;
    authorUrl?: string | null;
}

const flag = (on: boolean): string => (on ? "1" : "");

/**
 * The values for post-card.twig's fill points. Empty values drop their piece
 * of the card: no image -> the placeholder, no rating -> the "no ratings"
 * label rather than 0.0 (an average of zero out of zero votes is not a
 * rating), no date and no read time -> no meta line with a stray separator.
 */
export function postCardData(item: FeedPostCard): SlotData {
    const date = item.dateLabel ?? "";
    const readTime = item.readTimeLabel ?? "";
    const rated = Number(item.ratingCount) > 0;

    return {
        image: item.imageUrl ?? "",
        noImage: flag(!item.imageUrl),
        authorAvatar: item.authorAvatarUrl ?? "",
        authorName: item.authorName ?? "",
        authorUrl: item.authorUrl ?? "",
        authorText: flag(!item.authorUrl),
        date,
        readTime,
        dateAndRead: flag(date !== "" && readTime !== ""),
        hasMeta: flag(date !== "" || readTime !== ""),
        title: item.title ?? "",
        url: item.url || "#",
        excerpt: item.excerpt ?? "",
        hasTags: flag(Array.isArray(item.tags) && item.tags.length > 0),
        comments: String(Number(item.commentCount || 0)),
        rating: rated ? Number(item.ratingAverage).toFixed(1) : "",
        noRating: flag(!rated),
        audioTrackId: item.audioTrackId ?? "",
    };
}

/** A filled copy of the page's post card template, or null without one. */
export function renderFeedPostCard(item: FeedPostCard): HTMLElement | null {
    const card = ui.clone("post-card");
    if (!card) return null;

    ui.fill(card, postCardData(item));

    const tagList = card.querySelector<HTMLElement>("[data-post-tags]");
    for (const tag of item.tags ?? []) {
        const tagEl = ui.clone("post-card-tag");
        if (tagList && tagEl) tagList.append(ui.fill(tagEl, { tag: `#${tag}` }));
    }

    return card;
}

