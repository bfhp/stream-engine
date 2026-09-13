import { beforeEach, describe, expect, it } from "vitest";

import {
    PollApiResponse,
    applyPollResponse,
    renderPollResults,
    syncPollOptionInputs,
    updatePollSelectionUi,
} from "../../assets-src/pages/forum-poll";

/**
 * The poll card after a vote.
 *
 * The rules here are a second implementation of
 * `ForumsController::buildPollViewModel()`: the server renders the card on
 * load, this re-derives it after a vote without a reload. So the failure this
 * pair produces is a poll that looks one way when the page loads and another
 * way the moment you vote - which nobody notices until they vote.
 */

let card: HTMLElement;
let form: HTMLElement;

/** The card markup `forums.topic-view.twig` renders, reduced to what these functions touch. */
function buildCard(optionIds: number[], { multiple = false, max = 1 } = {}): void {
    document.body.innerHTML = `
        <div data-poll-card>
            <form data-poll-form data-poll-multiple="${multiple ? "1" : "0"}" data-poll-max-choices="${max}">
                ${optionIds.map(id => `
                    <label data-poll-option data-option-id="${id}">
                        <input type="${multiple ? "checkbox" : "radio"}" data-poll-option-input value="${id}">
                        <i data-poll-option-icon class="bi"></i>
                    </label>
                `).join("")}
                <span data-poll-selection-hint></span>
                <button type="submit" data-poll-submit></button>
                <button type="button" data-poll-cancel-change class="d-none"></button>
            </form>

            <div data-poll-results class="d-none">
                ${optionIds.map(id => `
                    <div>
                        <i data-poll-option-result-icon data-option-id="${id}" class="bi"></i>
                        <span data-poll-option-stat data-option-id="${id}"></span>
                        <span data-poll-option-bar data-option-id="${id}" style="width:0%"></span>
                    </div>
                `).join("")}
            </div>

            <div data-poll-hidden-note class="d-none"></div>
            <div data-poll-voted-note class="d-none"></div>
            <a data-poll-change-vote class="d-none"></a>
            <div data-poll-total-votes class="d-none"><span class="font-monospace"></span></div>
            <div data-poll-voters class="d-none"><span class="font-monospace"></span></div>
        </div>`;

    card = document.querySelector("[data-poll-card]")!;
    form = document.querySelector("[data-poll-form]")!;
}

function poll(overrides: Partial<PollApiResponse> = {}): PollApiResponse {
    return {
        maxChoices: 1,
        allowRevote: false,
        isClosed: false,
        canSeeResults: true,
        votersCount: 0,
        userVotes: [],
        options: [
            { id: 1, text: "Да", votesCount: 0 },
            { id: 2, text: "Нет", votesCount: 0 },
        ],
        ...overrides,
    };
}

const hidden = (selector: string) =>
    card.querySelector(selector)!.classList.contains("d-none");

const inputs = () =>
    Array.from(form.querySelectorAll<HTMLInputElement>("[data-poll-option-input]"));

const stat = (id: number) =>
    card.querySelector(`[data-poll-option-stat][data-option-id="${id}"]`)!.textContent;

const barWidth = (id: number) =>
    card.querySelector<HTMLElement>(`[data-poll-option-bar][data-option-id="${id}"]`)!.style.width;

beforeEach(() => {
    buildCard([1, 2]);
});

/* ===============================
   applyPollResponse - the truth table
=============================== */

describe("applyPollResponse()", () => {
    it("shows the vote form to someone who has not voted in an open poll", () => {
        applyPollResponse(card, form, poll());

        expect(form.classList.contains("d-none")).toBe(false);
    });

    /**
     * The one that matters most: `showResults = canSeeResults && !showVoteForm`.
     * With `canSeeResults: true` and no vote, a naive implementation shows
     * both stacked - the visitor sees the answer above the question.
     */
    it("never shows the vote form and the results together", () => {
        applyPollResponse(card, form, poll({ canSeeResults: true, userVotes: [] }));

        expect(form.classList.contains("d-none")).toBe(false);
        expect(hidden("[data-poll-results]")).toBe(true);
    });

    it("swaps the form for the results once you have voted", () => {
        applyPollResponse(card, form, poll({ userVotes: [1] }));

        expect(form.classList.contains("d-none")).toBe(true);
        expect(hidden("[data-poll-results]")).toBe(false);
    });

    it("shows the results of a closed poll even to someone who never voted", () => {
        applyPollResponse(card, form, poll({ isClosed: true, userVotes: [] }));

        expect(form.classList.contains("d-none")).toBe(true);
        expect(hidden("[data-poll-results]")).toBe(false);
    });

    it("shows the hidden-results note when neither the form nor the results apply", () => {
        // Voted, but not allowed to see the tally yet.
        applyPollResponse(card, form, poll({ userVotes: [1], canSeeResults: false }));

        expect(form.classList.contains("d-none")).toBe(true);
        expect(hidden("[data-poll-results]")).toBe(true);
        expect(hidden("[data-poll-hidden-note]")).toBe(false);
    });

    it("shows exactly one of the three blocks in every state", () => {
        const states: Array<Partial<PollApiResponse>> = [
            {},
            { userVotes: [1] },
            { userVotes: [1], canSeeResults: false },
            { isClosed: true },
            { isClosed: true, canSeeResults: false },
            { isClosed: true, userVotes: [1] },
            { canSeeResults: false },
        ];

        for (const state of states) {
            buildCard([1, 2]);
            applyPollResponse(card, form, poll(state));

            const visible = [
                !form.classList.contains("d-none"),
                !hidden("[data-poll-results]"),
                !hidden("[data-poll-hidden-note]"),
            ].filter(Boolean);

            expect(visible, JSON.stringify(state)).toHaveLength(1);
        }
    });

    /* ------- the change-vote link ------- */

    it("offers to change a vote only when all three conditions hold", () => {
        const cases: Array<[Partial<PollApiResponse>, boolean]> = [
            [{ userVotes: [1], allowRevote: true, isClosed: false }, true],
            // Not voted yet - the form is already open, there is nothing to change.
            [{ userVotes: [], allowRevote: true, isClosed: false }, false],
            [{ userVotes: [1], allowRevote: false, isClosed: false }, false],
            [{ userVotes: [1], allowRevote: true, isClosed: true }, false],
        ];

        for (const [state, expected] of cases) {
            buildCard([1, 2]);
            applyPollResponse(card, form, poll(state));

            expect(hidden("[data-poll-change-vote]"), JSON.stringify(state)).toBe(!expected);
        }
    });

    it("hides the cancel button, whatever it was", () => {
        // "Отмена" only belongs to an in-progress change; a fresh response
        // ends that.
        card.querySelector("[data-poll-cancel-change]")!.classList.remove("d-none");

        applyPollResponse(card, form, poll({ userVotes: [1] }));

        expect(form.querySelector("[data-poll-cancel-change]")!.classList.contains("d-none")).toBe(true);
    });

    it("shows the voted note whenever there is a vote, results or not", () => {
        applyPollResponse(card, form, poll({ userVotes: [1], canSeeResults: false }));
        expect(hidden("[data-poll-voted-note]")).toBe(false);

        buildCard([1, 2]);
        applyPollResponse(card, form, poll({ userVotes: [] }));
        expect(hidden("[data-poll-voted-note]")).toBe(true);
    });

    /* ------- the counters ------- */

    /**
     * `votesCount`/`votersCount` are null when the viewer may not see them -
     * "null means hidden, not zero". The arithmetic treats null as 0, so the
     * only thing keeping a fabricated "0 голосов" off the screen is that the
     * counters stay hidden unless the results are showing.
     */
    it("keeps the counters hidden unless the results are showing", () => {
        applyPollResponse(card, form, poll({ userVotes: [1], canSeeResults: false, votersCount: null }));

        expect(hidden("[data-poll-total-votes]")).toBe(true);
        expect(hidden("[data-poll-voters]")).toBe(true);
    });

    it("shows the counters alongside the results", () => {
        applyPollResponse(card, form, poll({ userVotes: [1], votersCount: 3 }));

        expect(hidden("[data-poll-total-votes]")).toBe(false);
        expect(hidden("[data-poll-voters]")).toBe(false);
    });

    /* ------- the vote-form's own state ------- */

    it("records the confirmed vote so Отмена can revert to it", () => {
        applyPollResponse(card, form, poll({ userVotes: [2] }));

        expect(form.dataset.pollCurrentVotes).toBe("2");
    });

    it("records an empty string when there is no vote", () => {
        // The edge behind "Отмена": `''.split(',')` is `['']`, and the caller
        // filters that out rather than turning it into `[NaN]`.
        applyPollResponse(card, form, poll({ userVotes: [] }));

        expect(form.dataset.pollCurrentVotes).toBe("");
        expect("".split(",").filter(id => id !== "")).toEqual([]);
    });

    it("pre-checks the vote form so Изменить голос opens it already matching", () => {
        applyPollResponse(card, form, poll({ userVotes: [2], allowRevote: true }));

        expect(inputs().map(i => i.checked)).toEqual([false, true]);
    });
});

/* ===============================
   syncPollOptionInputs
=============================== */

describe("syncPollOptionInputs()", () => {
    it("checks exactly the given ids and clears the rest", () => {
        syncPollOptionInputs(card, form, [2]);

        expect(inputs().map(i => i.checked)).toEqual([false, true]);
    });

    it("re-enables everything it touches", () => {
        // It is the "Отмена" path too: whatever the cap disabled mid-toggle
        // has to come back.
        inputs().forEach(i => { i.disabled = true; });
        card.querySelector("[data-poll-option]")!.classList.add("is-blocked");

        syncPollOptionInputs(card, form, []);

        expect(inputs().every(i => !i.disabled)).toBe(true);
        expect(card.querySelector(".is-blocked")).toBeNull();
    });

    it("marks the chosen rows", () => {
        syncPollOptionInputs(card, form, [1]);

        const rows = Array.from(card.querySelectorAll("[data-poll-option]"));
        expect(rows.map(r => r.classList.contains("is-selected"))).toEqual([true, false]);
    });

    it("ignores an id the card does not have", () => {
        // A stale response for a poll whose options were edited.
        expect(() => syncPollOptionInputs(card, form, [99])).not.toThrow();
        expect(inputs().every(i => !i.checked)).toBe(true);
    });
});

/* ===============================
   updatePollSelectionUi - the cap
=============================== */

describe("updatePollSelectionUi()", () => {
    it("disables the unchosen options once a multiple-choice cap is reached", () => {
        buildCard([1, 2, 3], { multiple: true, max: 2 });
        inputs()[0].checked = true;
        inputs()[1].checked = true;

        updatePollSelectionUi(form);

        // The two chosen stay clickable so they can be unchosen; the third
        // is blocked rather than silently ignored on submit.
        expect(inputs().map(i => i.disabled)).toEqual([false, false, true]);
        expect(card.querySelectorAll(".is-blocked")).toHaveLength(1);
    });

    it("re-enables everything when the cap stops being reached", () => {
        buildCard([1, 2, 3], { multiple: true, max: 2 });
        inputs()[0].checked = true;
        inputs()[1].checked = true;
        updatePollSelectionUi(form);

        inputs()[1].checked = false;
        updatePollSelectionUi(form);

        expect(inputs().every(i => !i.disabled)).toBe(true);
        expect(card.querySelectorAll(".is-blocked")).toHaveLength(0);
    });

    it("never disables anything in a single-choice poll", () => {
        // A radio group replaces the selection by itself; disabling the other
        // options would make the vote unchangeable.
        inputs()[0].checked = true;

        updatePollSelectionUi(form);

        expect(inputs().every(i => !i.disabled)).toBe(true);
    });

    it("keeps submit disabled until something is chosen", () => {
        const submit = form.querySelector<HTMLButtonElement>("[data-poll-submit]")!;

        updatePollSelectionUi(form);
        expect(submit.disabled).toBe(true);

        inputs()[0].checked = true;
        updatePollSelectionUi(form);
        expect(submit.disabled).toBe(false);
    });

    it("writes a hint that names the cap on a multiple-choice poll", () => {
        buildCard([1, 2, 3], { multiple: true, max: 2 });
        inputs()[0].checked = true;

        updatePollSelectionUi(form);

        expect(form.querySelector("[data-poll-selection-hint]")!.textContent).toBe("Выбрано 1 из 2");
    });

    it("writes a plain hint on a single-choice poll", () => {
        updatePollSelectionUi(form);
        expect(form.querySelector("[data-poll-selection-hint]")!.textContent).toBe("Выберите вариант");

        inputs()[0].checked = true;
        updatePollSelectionUi(form);
        expect(form.querySelector("[data-poll-selection-hint]")!.textContent).toBe("Вариант выбран");
    });
});

/* ===============================
   renderPollResults - the arithmetic
=============================== */

describe("renderPollResults()", () => {
    it("scales a single-choice poll by the total votes", () => {
        renderPollResults(card, poll({
            options: [
                { id: 1, text: "Да", votesCount: 3 },
                { id: 2, text: "Нет", votesCount: 1 },
            ],
            votersCount: 4,
        }));

        expect(stat(1)).toBe("75% · 3 голоса");
        expect(stat(2)).toBe("25% · 1 голос");
        expect(barWidth(1)).toBe("75%");
    });

    /**
     * A multiple-choice poll has more votes than voters, so dividing by the
     * total would make every option's share smaller than it is - and the
     * shares would sum to 100% across options nobody picked together.
     */
    it("scales a multiple-choice poll by the number of voters, not the votes", () => {
        renderPollResults(card, poll({
            maxChoices: 2,
            options: [
                { id: 1, text: "Да", votesCount: 3 },
                { id: 2, text: "Нет", votesCount: 3 },
            ],
            votersCount: 3,
        }));

        // Everyone picked both: 100% each, not 50%.
        expect(stat(1)).toBe("100% · 3 голоса");
        expect(stat(2)).toBe("100% · 3 голоса");
    });

    it("never renders more than 100% when votes exceed voters", () => {
        renderPollResults(card, poll({
            maxChoices: 3,
            options: [
                { id: 1, text: "Да", votesCount: 2 },
                { id: 2, text: "Нет", votesCount: 2 },
            ],
            votersCount: 2,
        }));

        expect(barWidth(1)).toBe("100%");
        expect(barWidth(2)).toBe("100%");
    });

    it("renders zero rather than NaN when nobody has voted", () => {
        // The denominator is 0 here; an unguarded division puts "NaN%" in the
        // bar's inline width, which the browser drops silently.
        renderPollResults(card, poll({ votersCount: 0 }));

        expect(stat(1)).toBe("0% · 0 голосов");
        expect(barWidth(1)).toBe("0%");
    });

    it("does not paint every bar as the leader in an all-zero poll", () => {
        // `leader > 0 && votes === leader` - without the first half, 0 === 0
        // makes every option the winner.
        renderPollResults(card, poll({ votersCount: 0 }));

        const colours = [1, 2].map(id =>
            card.querySelector<HTMLElement>(`[data-poll-option-bar][data-option-id="${id}"]`)!.style.backgroundColor);

        expect(new Set(colours).size).toBe(1);
        expect(colours[0]).not.toBe("var(--bs-primary)");
    });

    it("treats a null vote count as zero for the arithmetic", () => {
        // The counters are hidden in this state anyway, but the maths must
        // not produce NaN on the way there.
        renderPollResults(card, poll({
            options: [
                { id: 1, text: "Да", votesCount: null },
                { id: 2, text: "Нет", votesCount: null },
            ],
            votersCount: null,
        }));

        expect(stat(1)).toBe("0% · 0 голосов");
    });

    it("writes the footer totals with the right plural", () => {
        renderPollResults(card, poll({
            options: [
                { id: 1, text: "Да", votesCount: 21 },
                { id: 2, text: "Нет", votesCount: 1 },
            ],
            votersCount: 22,
        }));

        expect(card.querySelector("[data-poll-total-votes] .font-monospace")!.textContent)
            .toBe("22 голоса");
        expect(card.querySelector("[data-poll-voters] .font-monospace")!.textContent)
            .toBe("22 участника");
    });

    it("leaves the voters line alone when the count is withheld", () => {
        renderPollResults(card, poll({ votersCount: null }));

        expect(card.querySelector("[data-poll-voters] .font-monospace")!.textContent).toBe("");
    });
});
