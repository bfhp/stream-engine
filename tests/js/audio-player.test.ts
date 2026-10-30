import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { initAudioPlayers } from "../../assets-src/pages/audio-player";

/*
 * jsdom does not play media: `paused`, `duration` and `currentTime` are faked
 * on the element, and play()/pause() flip `paused` and fire the events the
 * player listens to.
 */

function mountPlayer(): { audio: HTMLAudioElement; seek: HTMLInputElement; listen: HTMLButtonElement } {
    const playlist = [
        { id: "t1", title: "First", url: "/a.mp3", postTitle: "Post one", postUrl: "/p/1" },
        { id: "t2", title: "Second", url: "/b.mp3", postTitle: "Post two", postUrl: "/p/2" },
    ];
    document.body.innerHTML = `
        <div data-audio-player data-playlist='${JSON.stringify(playlist)}'>
            <button data-audio-toggle><i data-audio-toggle-icon></i></button>
            <div data-audio-title></div><a data-audio-post></a>
            <span data-audio-current></span>
            <input type="range" min="0" max="1000" value="0" data-audio-seek>
            <span data-audio-duration></span>
            <ul>
                <li><button data-audio-track-index="0">First</button></li>
                <li><button data-audio-track-index="1">Second</button></li>
            </ul>
            <audio data-audio-element></audio>
        </div>
        <button data-audio-play-track-id="t2" aria-label="Listen to the attached track">
            <i data-audio-play-icon class="bi bi-play-fill"></i> <span data-audio-play-label>Listen</span>
        </button>`;

    const audio = document.querySelector<HTMLAudioElement>("audio")!;
    let paused = true;
    let time = 0;
    Object.defineProperty(audio, "paused", { get: () => paused });
    Object.defineProperty(audio, "duration", { get: () => 200 });
    Object.defineProperty(audio, "readyState", { get: () => HTMLMediaElement.HAVE_METADATA });
    Object.defineProperty(audio, "currentTime", { get: () => time, set: (v: number) => { time = v; } });
    audio.play = vi.fn(async () => { paused = false; audio.dispatchEvent(new Event("play")); });
    audio.pause = vi.fn(() => { paused = true; audio.dispatchEvent(new Event("pause")); });
    audio.load = vi.fn();

    initAudioPlayers();

    return {
        audio,
        seek: document.querySelector<HTMLInputElement>("[data-audio-seek]")!,
        listen: document.querySelector<HTMLButtonElement>("[data-audio-play-track-id]")!,
    };
}

beforeEach(() => localStorage.clear());
afterEach(() => { document.body.innerHTML = ""; });

describe("audio player", () => {
    it("reads the position in words, not as a clock time", () => {
        const { audio, seek } = mountPlayer();
        audio.currentTime = 74;
        audio.dispatchEvent(new Event("timeupdate"));

        expect(seek.getAttribute("aria-valuetext")).toBe("1 min 14 sec of 3 min 20 sec");
        expect(seek.value).toBe("370");
    });

    it("holds the slider still while it has focus, until the user moves it", () => {
        const { audio, seek } = mountPlayer();
        audio.currentTime = 10;
        seek.focus();
        expect(seek.getAttribute("aria-valuetext")).toBe("10 sec of 3 min 20 sec");

        audio.currentTime = 20;
        audio.dispatchEvent(new Event("timeupdate"));
        expect(seek.getAttribute("aria-valuetext")).toBe("10 sec of 3 min 20 sec");

        seek.value = "500";
        seek.dispatchEvent(new Event("input"));
        expect(seek.getAttribute("aria-valuetext")).toBe("1 min 40 sec of 3 min 20 sec");
        seek.dispatchEvent(new Event("change"));
        expect(audio.currentTime).toBe(100);

        seek.blur();
        audio.currentTime = 150;
        audio.dispatchEvent(new Event("timeupdate"));
        expect(seek.getAttribute("aria-valuetext")).toBe("2 min 30 sec of 3 min 20 sec");
    });

    it("marks the current track in the playlist", async () => {
        mountPlayer();
        const [first, second] = document.querySelectorAll<HTMLButtonElement>("[data-audio-track-index]");
        expect(first.getAttribute("aria-current")).toBe("true");

        second.click();
        expect(second.getAttribute("aria-current")).toBe("true");
        expect(first.hasAttribute("aria-current")).toBe(false);
    });

    it("lets a post's Listen button pause its own track", async () => {
        const { audio, listen } = mountPlayer();

        listen.click();
        await Promise.resolve();
        expect(audio.paused).toBe(false);
        expect(listen.getAttribute("aria-label")).toBe("Pause the attached track");
        expect(listen.querySelector("[data-audio-play-label]")!.textContent).toBe("Pause");
        expect(listen.querySelector("[data-audio-play-icon]")!.classList.contains("bi-pause-fill")).toBe(true);

        listen.click();
        expect(audio.paused).toBe(true);
        expect(listen.getAttribute("aria-label")).toBe("Listen to the attached track");
        expect(listen.querySelector("[data-audio-play-label]")!.textContent).toBe("Listen");
    });
});
