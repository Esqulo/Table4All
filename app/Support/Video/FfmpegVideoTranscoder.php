<?php

namespace App\Support\Video;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Video handling with ffmpeg/ffprobe, run as separate processes (argument arrays, never a shell).
 *
 * Uploaded videos are untrusted input for a large C program, so:
 * - only plain local files are read (`-protocol_whitelist file`): a crafted file can't make
 *   ffmpeg fetch URLs or read other files through playlist/concat tricks;
 * - only the MP4/MOV and WebM/Matroska containers with common camera codecs are accepted, and
 *   the container is forced on conversion instead of guessed again;
 * - every run has a timeout and a thread cap, so one upload can't hold the server;
 * - the output is re-encoded from scratch, without metadata (GPS position, device, dates).
 */
final class FfmpegVideoTranscoder implements VideoTranscoder
{
    /** ffprobe's names for the accepted containers → the demuxer forced when converting. */
    private const CONTAINERS = [
        'mov,mp4,m4a,3gp,3g2,mj2' => 'mov',
        'matroska,webm' => 'matroska',
    ];

    /** What phones and browsers record. */
    private const VIDEO_CODECS = ['h264', 'hevc', 'vp8', 'vp9', 'av1', 'mpeg4'];

    private const MAX_DIMENSION = 4096;

    /** Longest side of the result: 720p, plenty for a phone screen, light on mobile data. */
    private const OUTPUT_LONG_SIDE = 1280;

    private const PROBE_TIMEOUT = 15;

    private const CONVERT_TIMEOUT = 150;

    public function __construct(
        private readonly string $ffmpeg = 'ffmpeg',
        private readonly string $ffprobe = 'ffprobe',
    ) {}

    public function available(): bool
    {
        $finder = new ExecutableFinder;

        return $this->resolves($finder, $this->ffmpeg) && $this->resolves($finder, $this->ffprobe);
    }

    public function probe(string $path): ?VideoProbe
    {
        $result = Process::timeout(self::PROBE_TIMEOUT)->run([
            $this->ffprobe, '-v', 'error',
            '-protocol_whitelist', 'file',
            '-print_format', 'json',
            '-show_format', '-show_streams',
            $path,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $info = json_decode($result->output(), true);

        if (! is_array($info)) {
            return null;
        }

        $format = is_array($info['format'] ?? null) ? $info['format'] : [];
        $demuxer = self::CONTAINERS[(string) ($format['format_name'] ?? '')] ?? null;

        if ($demuxer === null) {
            return null;
        }

        $video = null;

        foreach ((array) ($info['streams'] ?? []) as $stream) {
            // Cover art in an audio file is a "video" stream too: skip it.
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video'
                && (int) ($stream['disposition']['attached_pic'] ?? 0) !== 1) {
                $video = $stream;
                break;
            }
        }

        if ($video === null || ! in_array($video['codec_name'] ?? null, self::VIDEO_CODECS, true)) {
            return null;
        }

        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        $seconds = (float) ($format['duration'] ?? $video['duration'] ?? 0);

        if ($width < 16 || $height < 16 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $seconds <= 0) {
            return null;
        }

        return new VideoProbe($demuxer, $seconds, $width, $height);
    }

    public function transcode(string $input, VideoProbe $probe, string $output, string $poster, int $maxSeconds): int
    {
        $long = self::OUTPUT_LONG_SIDE;

        $this->run([
            $this->ffmpeg, '-hide_banner', '-nostdin', '-y', '-v', 'error',
            '-protocol_whitelist', 'file',
            '-f', $probe->demuxer,
            '-i', $input,
            '-t', (string) $maxSeconds,
            '-map', '0:v:0', '-map', '0:a:0?',
            // Phones store rotation as metadata; ffmpeg applies it, then the metadata is dropped.
            '-vf', "scale='if(gte(iw,ih),min({$long},iw),-2)':'if(gte(iw,ih),-2,min({$long},ih))',format=yuv420p",
            '-fpsmax', '30',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-profile:v', 'main',
            '-maxrate', '2500k', '-bufsize', '5000k',
            '-c:a', 'aac', '-b:a', '96k', '-ac', '2',
            '-map_metadata', '-1', '-map_chapters', '-1', '-sn', '-dn',
            '-movflags', '+faststart',
            '-threads', '2',
            '-f', 'mp4', $output,
        ]);

        // The poster comes from our own output, never from the upload.
        $this->run([
            $this->ffmpeg, '-hide_banner', '-nostdin', '-y', '-v', 'error',
            '-protocol_whitelist', 'file',
            '-f', 'mov', '-i', $output,
            '-frames:v', '1', '-q:v', '5',
            '-map_metadata', '-1',
            '-f', 'image2', '-c:v', 'mjpeg', $poster,
        ]);

        $result = $this->probe($output);

        if ($result === null || ! is_file($poster) || filesize($poster) === 0) {
            throw new VideoConversionFailed('The converted video could not be read back.');
        }

        return (int) round($result->seconds * 1000);
    }

    /** @param  list<string>  $command */
    private function run(array $command): void
    {
        $result = Process::timeout(self::CONVERT_TIMEOUT)->run($command);

        if (! $result->successful()) {
            throw new VideoConversionFailed(trim($result->errorOutput()) ?: 'ffmpeg failed.');
        }
    }

    private function resolves(ExecutableFinder $finder, string $binary): bool
    {
        return str_contains($binary, '/') ? is_executable($binary) : $finder->find($binary) !== null;
    }
}
