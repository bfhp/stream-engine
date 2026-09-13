/* ==========================================================================
   Feed post cards

   The client-side twin of `components/users/post-card.twig`, so a card
   appended by the load-more action looks identical to one from the first,
   server-rendered batch. Kept in sync with that partial by hand - which is
   the reason these are worth testing: drift between the two is invisible
   until someone scrolls.

   Extracted from `users.ts` because that module binds to the DOM at import
   time; these five functions are string builders and need only the escaper.
   ========================================================================== */

import { escapeHtml } from "../shared/escape";
import { trans } from "../shared/i18n";

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
    // Only present (and only rendered) when the card came from the community
    // feed - see renderFeedPostCard()'s showAuthor param, mirroring the
    // partial's own flag.
    authorName?: string;
    authorAvatarUrl?: string;
    authorUrl?: string | null;
}

export function renderFeedCardThumbnail(imageUrl: string | null): string {
    return imageUrl
        ? `<img src="${escapeHtml(imageUrl)}" alt="" class="w-100 h-100 object-fit-cover" style="min-height: 150px;">`
        : `<div class="d-flex align-items-center justify-content-center h-100 bg-body-secondary text-body-tertiary" style="min-height: 150px;">
                <i class="bi bi-image fs-2"></i>
            </div>`;
}

/**
 * `showAuthor` renders the community feed's per-post byline (avatar, name,
 * link) ahead of the date, since unlike the profile's single-owner feed every
 * card there can belong to a different author. Without it, the profile feed's
 * plain calendar-icon meta line - the partial's own
 * `{% if showAuthor %}/{% elseif %}`.
 */
export function renderFeedCardMeta(item: FeedPostCard, showAuthor: boolean): string {
    if (showAuthor) {
        const authorName = item.authorUrl
            ? `<a href="${escapeHtml(item.authorUrl)}" class="fw-semibold text-body text-decoration-none">${escapeHtml(item.authorName)}</a>`
            : `<span class="fw-semibold">${escapeHtml(item.authorName)}</span>`;

        const parts: string[] = [
            `<img src="${escapeHtml(item.authorAvatarUrl)}" alt="" class="rounded-circle bg-body-secondary object-fit-cover flex-shrink-0" width="24" height="24">`,
            authorName,
        ];
        if (item.dateLabel) parts.push(`<span>&middot;</span><span>${escapeHtml(item.dateLabel)}</span>`);
        if (item.readTimeLabel) parts.push(`<span>&middot;</span><span>${escapeHtml(item.readTimeLabel)}</span>`);

        return `<div class="d-flex align-items-center flex-wrap gap-2 text-body-secondary small mb-2">${parts.join('')}</div>`;
    }

    // Neither piece of metadata: no empty line with a stray separator in it.
    if (!item.dateLabel && !item.readTimeLabel) return '';

    const parts: string[] = [];
    if (item.dateLabel) {
        parts.push(`<i class="bi bi-calendar3"></i><span>${escapeHtml(item.dateLabel)}</span>`);
    }
    if (item.dateLabel && item.readTimeLabel) {
        parts.push('<span>&middot;</span>');
    }
    if (item.readTimeLabel) {
        parts.push(`<span>${escapeHtml(item.readTimeLabel)}</span>`);
    }

    return `<div class="d-flex align-items-center gap-2 text-body-secondary small mb-2">${parts.join('')}</div>`;
}

export function renderFeedCardTags(tags: string[]): string {
    if (!tags || !tags.length) return '';

    const badges = tags
        .map((tag) => `<span class="badge rounded-pill text-bg-secondary">#${escapeHtml(tag)}</span>`)
        .join('');

    return `<div class="d-flex flex-wrap gap-1 mb-3">${badges}</div>`;
}

/**
 * the empty-rating label rather than 0.0 when nobody has rated: an average of zero out
 * of zero votes is not a rating, and rendering it as one makes every new post
 * look badly reviewed.
 */
export function renderFeedCardRating(item: FeedPostCard): string {
    if (item.ratingCount > 0) {
        return `<i class="bi bi-star-fill me-1" style="color:#f5a623;"></i>${Number(item.ratingAverage).toFixed(1)}`;
    }

    return `<i class="bi bi-star me-1"></i>${trans('js.rating.empty_short')}`;
}

export function renderFeedPostCard(item: FeedPostCard, showAuthor: boolean): string {
    return `
        <article class="card">
            <div class="row g-0">
                <div class="col-sm-4">
                    ${renderFeedCardThumbnail(item.imageUrl)}
                </div>
                <div class="col-sm-8">
                    <div class="card-body">
                        ${renderFeedCardMeta(item, showAuthor)}
                        <h3 class="h5 card-title mb-2">
                            <a href="${escapeHtml(item.url || '#')}" class="text-body text-decoration-none stretched-link">${escapeHtml(item.title)}</a>
                        </h3>
                        ${item.excerpt ? `<p class="card-text text-body-secondary small mb-3">${escapeHtml(item.excerpt)}</p>` : ''}
                        ${renderFeedCardTags(item.tags)}
                        <div class="d-flex flex-wrap align-items-center gap-3 text-body-secondary small">
                            <span><i class="bi bi-chat-left-text me-1"></i>${Number(item.commentCount || 0)}</span>
                            <span>${renderFeedCardRating(item)}</span>
                            ${item.audioTrackId ? `<button type="button" class="btn btn-sm btn-outline-primary position-relative z-1" data-audio-play-track-id="${escapeHtml(item.audioTrackId)}"><i class="bi bi-play-fill me-1"></i>${trans('js.blog.listen')}</button>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        </article>
    `;
}
