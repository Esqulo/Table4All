<?php

namespace Tests\Support;

use App\Support\Video\VideoConversionFailed;
use App\Support\Video\VideoProbe;
use App\Support\Video\VideoTranscoder;

/** Stands in for ffmpeg in tests: answers what each test sets up and writes placeholder files. */
final class FakeVideoTranscoder implements VideoTranscoder
{
    public bool $available = true;

    /** Null makes every file unreadable. */
    public ?VideoProbe $probe;

    public bool $failConversion = false;

    /** @var list<string> */
    public array $converted = [];

    public function __construct()
    {
        $this->probe = new VideoProbe('mov', 12.0, 1080, 1920);
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function probe(string $path): ?VideoProbe
    {
        return $this->probe;
    }

    public function transcode(string $input, VideoProbe $probe, string $output, string $poster, int $maxSeconds): int
    {
        if ($this->failConversion) {
            throw new VideoConversionFailed('Fake failure.');
        }

        $this->converted[] = $input;
        file_put_contents($output, 'converted video');
        file_put_contents($poster, 'poster frame');

        return (int) round(min($probe->seconds, $maxSeconds) * 1000);
    }
}
