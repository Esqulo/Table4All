<?php

namespace App\Support\Video;

/** What ffprobe found in an uploaded file that passed the checks of VideoTranscoder::probe(). */
final readonly class VideoProbe
{
    /**
     * @param  string  $demuxer  The ffmpeg demuxer to read it with ("mov" or "matroska"); forced on conversion.
     */
    public function __construct(
        public string $demuxer,
        public float $seconds,
        public int $width,
        public int $height,
    ) {}
}
