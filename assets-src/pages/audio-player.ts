import { trans } from "../shared/i18n";

interface AudioPlaylistItem {
    id: string;
    postTitle: string | null;
    postUrl: string | null;
    title: string;
    url: string;
}

interface AudioPlayerState {
    trackId: string | null;
    position: number;
    isPlaying: boolean;
    updatedAt: number;
}

export function initAudioPlayers() {
    document.querySelectorAll<HTMLElement>('[data-audio-player]:not([data-audio-player-ready])').forEach((root) => {
        root.dataset.audioPlayerReady = '1';

        let playlist: AudioPlaylistItem[] = [];
        try {
            playlist = JSON.parse(root.dataset.playlist || '[]');
        } catch {
            playlist = [];
        }

        playlist = playlist.filter((item) => item && item.id && item.url);
        if (!playlist.length) {
            root.remove();
            return;
        }

        const audio = root.querySelector<HTMLAudioElement>('[data-audio-element]');
        const toggle = root.querySelector<HTMLButtonElement>('[data-audio-toggle]');
        const toggleIcon = root.querySelector<HTMLElement>('[data-audio-toggle-icon]');
        const prev = root.querySelector<HTMLButtonElement>('[data-audio-prev]');
        const next = root.querySelector<HTMLButtonElement>('[data-audio-next]');
        const title = root.querySelector<HTMLElement>('[data-audio-title]');
        const post = root.querySelector<HTMLElement>('[data-audio-post]');
        const seek = root.querySelector<HTMLInputElement>('[data-audio-seek]');
        const current = root.querySelector<HTMLElement>('[data-audio-current]');
        const duration = root.querySelector<HTMLElement>('[data-audio-duration]');
        const trackButtons = Array.from(root.querySelectorAll<HTMLButtonElement>('[data-audio-track-index]'));

        if (!audio || !toggle || !toggleIcon || !seek || !title) return;

        let activeIndex = 0;
        let isSeeking = false;
        let isSwitchingTrack = false;
        let shouldResumePlayback = false;
        let hasRenderedTrack = false;
        let currentMediaItemId: string | null = null;
        const stateKey = "streamEngine.audio.state";

        function readState(): AudioPlayerState {
            try {
                const parsed = JSON.parse(localStorage.getItem(stateKey) || "{}");
                return {
                    trackId: typeof parsed.trackId === "string" ? parsed.trackId : null,
                    position: Number.isFinite(parsed.position) && parsed.position > 0 ? parsed.position : 0,
                    isPlaying: parsed.isPlaying === true,
                    updatedAt: Number.isFinite(parsed.updatedAt) ? parsed.updatedAt : 0,
                };
            } catch {
                return { trackId: null, position: 0, isPlaying: false, updatedAt: 0 };
            }
        }

        function cleanupLegacyProgressKeys(): void {
            for (let i = localStorage.length - 1; i >= 0; i -= 1) {
                const key = localStorage.key(i);
                if (key?.startsWith("streamEngine.audio.") && key.endsWith(".time")) {
                    localStorage.removeItem(key);
                }
            }
        }

        function saveState(isPlaying = shouldResumePlayback, position = audio.currentTime): void {
            const item = playlist[activeIndex];

            localStorage.setItem(stateKey, JSON.stringify({
                trackId: item?.id ?? null,
                position: Number.isFinite(position) && position > 0 ? Math.floor(position) : 0,
                isPlaying,
                updatedAt: Date.now(),
            }));
        }

        function formatTime(seconds: number): string {
            if (!Number.isFinite(seconds) || seconds < 0) return "0:00";

            const rounded = Math.floor(seconds);
            const minutes = Math.floor(rounded / 60);
            const rest = String(rounded % 60).padStart(2, "0");

            return `${minutes}:${rest}`;
        }

        function saveCurrentPosition(): void {
            const item = playlist[activeIndex];
            if (
                !item ||
                !hasRenderedTrack ||
                currentMediaItemId !== item.id ||
                audio.readyState < HTMLMediaElement.HAVE_METADATA ||
                !Number.isFinite(audio.currentTime)
            ) {
                return;
            }

            saveState();
        }

        function restorePosition(position: number): void {
            if (Number.isFinite(position) && position > 0) {
                audio.currentTime = position;
            }
        }

        function updateProgress(): void {
            if (!isSeeking) {
                const ratio = audio.duration > 0 ? audio.currentTime / audio.duration : 0;
                seek.value = String(Math.round(ratio * 1000));
            }

            if (current) current.textContent = formatTime(audio.currentTime);
            if (duration) duration.textContent = formatTime(audio.duration);
        }

        function setPlayingState(isPlaying: boolean): void {
            toggleIcon.className = `bi ${isPlaying ? "bi-pause-fill" : "bi-play-fill"}`;
            toggle.title = isPlaying ? trans("js.audio.pause") : trans("js.audio.play");
            toggle.setAttribute("aria-label", toggle.title);
        }

        function renderTrack(): void {
            const item = playlist[activeIndex];
            if (!item) return;

            audio.src = item.url;
            currentMediaItemId = item.id;
            hasRenderedTrack = true;
            title.textContent = item.title || trans("js.common.untitled");

            if (post) {
                post.textContent = item.postTitle || trans("js.common.post");
                if (post instanceof HTMLAnchorElement) {
                    if (item.postUrl) {
                        post.href = item.postUrl;
                    } else {
                        post.removeAttribute("href");
                    }
                }
            }

            trackButtons.forEach((button) => {
                button.classList.toggle("is-active", Number(button.dataset.audioTrackIndex) === activeIndex);
            });

            seek.value = "0";
            updateProgress();
        }

        async function playCurrentTrack(): Promise<void> {
            shouldResumePlayback = true;
            try {
                await audio.play();
            } catch {
                shouldResumePlayback = false;
                isSwitchingTrack = false;
                setPlayingState(false);
                saveState(false);
            }
        }

        function loadTrack(index: number, autoplay: boolean, startAt = 0): void {
            isSwitchingTrack = true;
            shouldResumePlayback = autoplay;
            activeIndex = (index + playlist.length) % playlist.length;
            saveState(autoplay, startAt);

            let restored = false;
            const restoreCurrentTrack = () => {
                if (restored) return;
                restored = true;
                restorePosition(startAt);
                updateProgress();
                isSwitchingTrack = false;
            };

            audio.addEventListener("loadedmetadata", restoreCurrentTrack, { once: true });
            audio.addEventListener("error", () => {
                isSwitchingTrack = false;
            }, { once: true });
            renderTrack();

            if (audio.readyState >= HTMLMediaElement.HAVE_METADATA) {
                restoreCurrentTrack();
            }

            if (autoplay) {
                void playCurrentTrack();
            }
        }

        function playTrackById(trackId: string): boolean {
            const index = playlist.findIndex((item) => item.id === trackId);
            if (index < 0) return false;

            loadTrack(index, true, 0);
            return true;
        }

        toggle.addEventListener("click", () => {
            if (audio.paused) {
                void playCurrentTrack();
            } else {
                shouldResumePlayback = false;
                audio.pause();
            }
        });

        prev?.addEventListener("click", () => loadTrack(activeIndex - 1, true));
        next?.addEventListener("click", () => loadTrack(activeIndex + 1, true));

        trackButtons.forEach((button) => {
            button.addEventListener("click", () => {
                loadTrack(Number(button.dataset.audioTrackIndex || "0"), true);
            });
        });

        document.addEventListener("click", (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;

            const button = target.closest<HTMLButtonElement>("[data-audio-play-track-id]");
            const trackId = button?.dataset.audioPlayTrackId;
            if (!trackId) return;

            if (playTrackById(trackId)) {
                event.preventDefault();
            }
        });

        seek.addEventListener("input", () => {
            isSeeking = true;
        });

        seek.addEventListener("change", () => {
            const ratio = Number(seek.value) / 1000;
            if (Number.isFinite(audio.duration) && audio.duration > 0) {
                audio.currentTime = audio.duration * ratio;
                saveCurrentPosition();
            }
            isSeeking = false;
            updateProgress();
        });

        audio.addEventListener("play", () => {
            isSwitchingTrack = false;
            setPlayingState(true);
            saveState(true);
        });
        audio.addEventListener("pause", () => {
            setPlayingState(false);
            if (isSwitchingTrack) return;

            saveCurrentPosition();
            saveState();
        });
        audio.addEventListener("timeupdate", () => {
            updateProgress();
            saveCurrentPosition();
        });
        audio.addEventListener("durationchange", updateProgress);
        audio.addEventListener("ended", () => {
            loadTrack(activeIndex + 1, true);
        });

        const saveStateBeforePageExit = () => {
            saveCurrentPosition();
            saveState();
        };

        window.addEventListener("pagehide", saveStateBeforePageExit);
        window.addEventListener("beforeunload", saveStateBeforePageExit);

        const storedState = readState();
        const storedIndex = storedState.trackId !== null
            ? playlist.findIndex((item) => item.id === storedState.trackId)
            : -1;
        cleanupLegacyProgressKeys();
        loadTrack(
            storedIndex >= 0 ? storedIndex : 0,
            storedState.isPlaying && storedIndex >= 0,
            storedIndex >= 0 ? storedState.position : 0,
        );
    });
}
