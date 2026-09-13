/* ==========================================================================
   Forum poll card state

   Everything that turns a poll API response into what the card shows. All of
   it takes `(card, form, poll)` explicitly and holds no closure state, which
   is what made it extractable at all - `forums.ts` itself imports Bootstrap
   and Trix at module scope, so testing four DOM functions in place would
   mean importing an editor.

   These rules are duplicated from `ForumsController::buildPollViewModel()`,
   which computes the same derived state server-side for the initial render.
   The duplication is deliberate (one is PHP, one runs after a vote without a
   reload) and it is the reason the truth table is worth asserting on both
   sides: a poll that renders one way on load and another way after voting is
   the failure this pair produces.
   ========================================================================== */

import { trans, transChoiceWithCount } from "../shared/i18n";

// applyPollResponse() is the one place that turns a fresh vote response (or
// the initial page's own poll data, never called with that today since SSR
// already rendered it, but shaped identically) into DOM updates - mirrors
// site/app.ts's updateRatingStars()/updateFavoriteButton() pattern of
// patching the DOM from an API response instead of reloading the page.
export type PollApiOption = { id: number; text: string; votesCount: number | null };
export type PollApiResponse = {
    maxChoices: number;
    allowRevote: boolean;
    isClosed: boolean;
    canSeeResults: boolean;
    votersCount: number | null;
    userVotes: number[];
    options: PollApiOption[];
};

export function pollOptionIconClass(isMultiple: boolean, checked: boolean): string {
    if (isMultiple) return checked ? "bi-check-square-fill text-primary" : "bi-square text-body-secondary";
    return checked ? "bi-record-circle text-primary" : "bi-circle text-body-secondary";
}

export function pollResultIconClass(chosen: boolean): string {
    return chosen ? "bi-check-circle-fill text-primary" : "bi-dot text-body-secondary";
}

export function pollBarColor(chosen: boolean, isLeading: boolean): string {
    if (chosen) return "var(--bs-primary)";
    return isLeading ? "rgba(255,255,255,.42)" : "rgba(255,255,255,.22)";
}

// Sets every option row's checked/selected/icon state from a given list of
// option ids - used both after a fresh vote (server's own userVotes) and by
// Cancel (reverting to data-poll-current-votes, the last confirmed
// selection, discarding whatever the user was mid-toggling).
export function syncPollOptionInputs(card: HTMLElement, form: HTMLElement, checkedIds: number[]) {
    const isMultiple = form.dataset.pollMultiple === "1";

    form.querySelectorAll<HTMLElement>("[data-poll-option]").forEach((row) => {
        const id = Number(row.dataset.optionId);
        const input = row.querySelector<HTMLInputElement>("[data-poll-option-input]");
        const icon = row.querySelector<HTMLElement>("[data-poll-option-icon]");
        const checked = checkedIds.indexOf(id) !== -1;

        if (input) {
            input.checked = checked;
            input.disabled = false;
        }
        row.classList.toggle("is-selected", checked);
        row.classList.remove("is-blocked");
        if (icon) icon.className = "bi " + pollOptionIconClass(isMultiple, checked);
    });
}

// Live selection state while the vote-form is open: re-derives every
// option's visual state from the inputs' own current .checked (rather than
// tracking selection separately), enforces the maxChoices cap on
// multiple-choice polls by disabling not-yet-checked options once the cap
// is hit, and keeps the submit button/selection hint in sync.
export function updatePollSelectionUi(form: HTMLElement) {
    const isMultiple = form.dataset.pollMultiple === "1";
    const max = Number(form.dataset.pollMaxChoices || "1");
    const inputs = Array.from(form.querySelectorAll<HTMLInputElement>("[data-poll-option-input]"));
    const checkedCount = inputs.filter((input) => input.checked).length;

    inputs.forEach((input) => {
        const row = input.closest<HTMLElement>("[data-poll-option]");
        const icon = row?.querySelector<HTMLElement>("[data-poll-option-icon]");
        const checked = input.checked;

        row?.classList.toggle("is-selected", checked);
        if (icon) icon.className = "bi " + pollOptionIconClass(isMultiple, checked);

        if (isMultiple) {
            const blocked = !checked && checkedCount >= max;
            input.disabled = blocked;
            row?.classList.toggle("is-blocked", blocked);
        }
    });

    const submit = form.querySelector<HTMLButtonElement>("[data-poll-submit]");
    if (submit) submit.disabled = checkedCount === 0;

    const hint = form.querySelector<HTMLElement>("[data-poll-selection-hint]");
    if (hint) {
        hint.textContent = isMultiple
            ? trans("js.poll.selected_count", { count: checkedCount, max })
            : trans(checkedCount ? "js.poll.option_selected" : "js.poll.select_option");
    }
}

// Repaints the results block (percentages, bars, per-option vote text, the
// footer's total/voters counts) from a poll API response - votesCount/
// votersCount are null when the viewer isn't allowed to see them yet
// (PollService::canSeeResults() said no), same "null means hidden, not
// zero" contract APIController::pollToArray() documents, so this treats a
// null as 0 for the arithmetic but the results block itself stays d-none
// in that case (applyPollResponse() below), so these numbers never actually
// render unseen.
export function renderPollResults(card: HTMLElement, poll: PollApiResponse) {
    const options = poll.options;
    const total = options.reduce((sum, option) => sum + (option.votesCount ?? 0), 0);
    const isMultiple = poll.maxChoices > 1;
    const denom = isMultiple ? (poll.votersCount ?? 0) : total;
    const leader = options.reduce((max, option) => Math.max(max, option.votesCount ?? 0), 0);

    options.forEach((option) => {
        const chosen = poll.userVotes.indexOf(option.id) !== -1;
        const votes = option.votesCount ?? 0;
        const pct = denom > 0 ? Math.round((votes / denom) * 100) : 0;

        const icon = card.querySelector<HTMLElement>(`[data-poll-option-result-icon][data-option-id="${option.id}"]`);
        const stat = card.querySelector<HTMLElement>(`[data-poll-option-stat][data-option-id="${option.id}"]`);
        const bar = card.querySelector<HTMLElement>(`[data-poll-option-bar][data-option-id="${option.id}"]`);

        if (icon) icon.className = "bi " + pollResultIconClass(chosen);
        if (stat) stat.textContent = `${pct}% · ${transChoiceWithCount("js.common.vote", votes)}`;
        if (bar) {
            bar.style.width = pct + "%";
            bar.style.backgroundColor = pollBarColor(chosen, leader > 0 && votes === leader);
        }
    });

    const totalEl = card.querySelector<HTMLElement>("[data-poll-total-votes] .font-monospace");
    if (totalEl) totalEl.textContent = transChoiceWithCount("js.common.vote", total);

    const votersEl = card.querySelector<HTMLElement>("[data-poll-voters] .font-monospace");
    if (votersEl && poll.votersCount !== null) {
        votersEl.textContent = transChoiceWithCount("js.common.participant", poll.votersCount);
    }
}

// Applies a fresh poll API response to the whole card: which block is
// visible (vote-form / results / "results are hidden" note), the
// change-vote link, the vote-recorded note, and the vote-form's own
// pre-checked state (so a later change-vote click reveals it
// already matching what was just voted) - the same derived-state rules
// ForumsController::buildPollViewModel() computes server-side for the
// initial render, just re-run here against the vote endpoint's JSON instead
// of a PHP Poll object.
export function applyPollResponse(card: HTMLElement, form: HTMLElement, poll: PollApiResponse) {
    const hasVoted = poll.userVotes.length > 0;
    const showVoteForm = !poll.isClosed && !hasVoted;
    // Mutually exclusive with the vote form, same as
    // ForumsController::buildPollViewModel()'s own showResults - never show
    // both stacked together, even if canSeeResults is already true.
    const showResults = poll.canSeeResults && !showVoteForm;
    const showHiddenNote = !showVoteForm && !showResults;
    const canChangeVote = hasVoted && !poll.isClosed && poll.allowRevote;

    const results = card.querySelector<HTMLElement>("[data-poll-results]");
    const hiddenNote = card.querySelector<HTMLElement>("[data-poll-hidden-note]");
    const changeButton = card.querySelector<HTMLElement>("[data-poll-change-vote]");
    const cancelButton = form.querySelector<HTMLElement>("[data-poll-cancel-change]");
    const votedNote = card.querySelector<HTMLElement>("[data-poll-voted-note]");
    const totalVotesEl = card.querySelector<HTMLElement>("[data-poll-total-votes]");
    const votersEl = card.querySelector<HTMLElement>("[data-poll-voters]");

    form.dataset.pollCurrentVotes = poll.userVotes.join(",");
    syncPollOptionInputs(card, form, poll.userVotes);
    form.classList.toggle("d-none", !showVoteForm);

    if (results) {
        renderPollResults(card, poll);
        results.classList.toggle("d-none", !showResults);
    }
    hiddenNote?.classList.toggle("d-none", !showHiddenNote);
    cancelButton?.classList.add("d-none");
    changeButton?.classList.toggle("d-none", !canChangeVote);
    votedNote?.classList.toggle("d-none", !hasVoted);
    totalVotesEl?.classList.toggle("d-none", !showResults);
    votersEl?.classList.toggle("d-none", !showResults);

    updatePollSelectionUi(form);
}
