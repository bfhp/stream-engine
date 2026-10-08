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

        // Preserve the checked DOM contract inside the nested callbacks below.
        const playerAudio = audio;
        const playerToggle = toggle;
        const playerToggleIcon = toggleIcon;
        const playerSeek = seek;
        const playerTitle = title;

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

        function saveState(isPlaying = shouldResumePlayback, position = playerAudio.currentTime): void {
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
                playerAudio.readyState < HTMLMediaElement.HAVE_METADATA ||
                !Number.isFinite(playerAudio.currentTime)
            ) {
                return;
            }

            saveState();
        }

        function restorePosition(position: number): void {
            if (Number.isFinite(position) && position > 0) {
                playerAudio.currentTime = position;
            }
        }

        function updateProgress(): void {
            if (!isSeeking) {
                const ratio = playerAudio.duration > 0 ? playerAudio.currentTime / playerAudio.duration : 0;
                playerSeek.value = String(Math.round(ratio * 1000));
            }

            if (current) current.textContent = formatTime(playerAudio.currentTime);
            if (duration) duration.textContent = formatTime(playerAudio.duration);
        }

        function setPlayingState(isPlaying: boolean): void {
            playerToggleIcon.className = `bi ${isPlaying ? "bi-pause-fill" : "bi-play-fill"}`;
            playerToggle.title = isPlaying ? trans("js.audio.pause") : trans("js.audio.play");
            playerToggle.setAttribute("aria-label", playerToggle.title);
        }

        function renderTrack(): void {
            const item = playlist[activeIndex];
            if (!item) return;

            playerAudio.src = item.url;
            currentMediaItemId = item.id;
            hasRenderedTrack = true;
            playerTitle.textContent = item.title || trans("js.common.untitled");

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

            playerSeek.value = "0";
            updateProgress();
        }

        async function playCurrentTrack(): Promise<void> {
            shouldResumePlayback = true;
            try {
                await playerAudio.play();
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

            playerAudio.addEventListener("loadedmetadata", restoreCurrentTrack, { once: true });
            playerAudio.addEventListener("error", () => {
                isSwitchingTrack = false;
            }, { once: true });
            renderTrack();

            if (playerAudio.readyState >= HTMLMediaElement.HAVE_METADATA) {
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

        playerToggle.addEventListener("click", () => {
            if (playerAudio.paused) {
                void playCurrentTrack();
            } else {
                shouldResumePlayback = false;
                playerAudio.pause();
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

        playerSeek.addEventListener("input", () => {
            isSeeking = true;
        });

        playerSeek.addEventListener("change", () => {
            const ratio = Number(playerSeek.value) / 1000;
            if (Number.isFinite(playerAudio.duration) && playerAudio.duration > 0) {
                playerAudio.currentTime = playerAudio.duration * ratio;
                saveCurrentPosition();
            }
            isSeeking = false;
            updateProgress();
        });

        playerAudio.addEventListener("play", () => {
            isSwitchingTrack = false;
            setPlayingState(true);
            saveState(true);
        });
        playerAudio.addEventListener("pause", () => {
            setPlayingState(false);
            if (isSwitchingTrack) return;

            saveCurrentPosition();
            saveState();
        });
        playerAudio.addEventListener("timeupdate", () => {
            updateProgress();
            saveCurrentPosition();
        });
        playerAudio.addEventListener("durationchange", updateProgress);
        playerAudio.addEventListener("ended", () => {
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
