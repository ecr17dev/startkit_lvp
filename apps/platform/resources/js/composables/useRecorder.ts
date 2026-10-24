import { onBeforeUnmount, ref } from 'vue';
import { t } from '@/lib/i18n';

export type Recording = { blob: Blob; duration: number; extension: string };

const CANDIDATE_TYPES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/mp4',
];

/**
 * Record audio with the browser microphone (MediaRecorder): permission,
 * timer, automatic stop at the maximum duration and cancellation. The
 * recording is completed before it is transcribed (no streaming).
 */
export function useRecorder() {
    const recording = ref(false);
    const seconds = ref(0);
    const error = ref<string | null>(null);

    let recorder: MediaRecorder | null = null;
    let stream: MediaStream | null = null;
    let chunks: Blob[] = [];
    let timer: ReturnType<typeof setInterval> | undefined;
    let startedAt = 0;
    let finish: ((recording: Recording | null) => void) | null = null;
    let keep = true;

    const supported = () =>
        typeof window !== 'undefined' &&
        !!navigator.mediaDevices?.getUserMedia &&
        typeof MediaRecorder !== 'undefined';

    const release = () => {
        clearInterval(timer);
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        recorder = null;
        recording.value = false;
    };

    /**
     * Start recording. Resolves with the recording when it stops (null when
     * cancelled or when the microphone could not start).
     */
    const start = async (maxSeconds: number): Promise<Recording | null> => {
        error.value = null;

        if (!supported()) {
            error.value = window.isSecureContext
                ? t('Your browser does not support audio recording.')
                : t('The microphone requires a secure connection (HTTPS).');

            return null;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (exception) {
            error.value =
                (exception as Error).name === 'NotAllowedError'
                    ? t(
                          'Microphone permission was denied. Allow it in the browser settings.',
                      )
                    : t('The microphone could not be started.');

            return null;
        }

        const mimeType = CANDIDATE_TYPES.find((type) =>
            MediaRecorder.isTypeSupported(type),
        );

        recorder = new MediaRecorder(
            stream,
            mimeType ? { mimeType } : undefined,
        );
        chunks = [];
        keep = true;
        seconds.value = 0;
        startedAt = Date.now();

        recorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                chunks.push(event.data);
            }
        };

        const done = new Promise<Recording | null>(
            (resolve) => (finish = resolve),
        );

        recorder.onstop = () => {
            const type = recorder?.mimeType || mimeType || 'audio/webm';
            const duration = (Date.now() - startedAt) / 1000;

            release();
            finish?.(
                keep && chunks.length
                    ? {
                          blob: new Blob(chunks, { type }),
                          duration,
                          extension: type.includes('mp4')
                              ? 'm4a'
                              : type.includes('ogg')
                                ? 'ogg'
                                : 'webm',
                      }
                    : null,
            );
        };

        recorder.start(250);
        recording.value = true;

        timer = setInterval(() => {
            seconds.value = Math.floor((Date.now() - startedAt) / 1000);

            if (seconds.value >= maxSeconds) {
                stop();
            }
        }, 250);

        return done;
    };

    const stop = () => {
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }
    };

    const cancel = () => {
        keep = false;
        stop();
    };

    onBeforeUnmount(cancel);

    return { recording, seconds, error, start, stop, cancel, supported };
}
