<?php

namespace App\Support\Video;

/** Reads and converts uploaded videos. Bound to FfmpegVideoTranscoder; tests swap in a fake. */
interface VideoTranscoder
{
    /** Whether videos can be handled here at all (ffmpeg installed). */
    public function available(): bool;

    /**
     * Inspects an uploaded file. Null when it is not a video this app accepts:
     * unreadable, an unexpected container or codec, no picture, or absurd dimensions.
     */
    public function probe(string $path): ?VideoProbe;

    /**
     * Converts $input to a small H.264/AAC MP4 at $output (metadata stripped, at most
     * $maxSeconds long) and writes a JPEG of its first frame to $poster.
     * Returns the duration of the result in milliseconds.
     *
     * @throws VideoConversionFailed
     */
    public function transcode(string $input, VideoProbe $probe, string $output, string $poster, int $maxSeconds): int;
}
