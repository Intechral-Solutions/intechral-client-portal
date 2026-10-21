<?php

namespace App\Jobs;

use App\Support\Runtime\RuntimeChallenges;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The queue half of the cross-context runtime check (R0.9 WP3-A, slice A2): queued once by `runtime:preflight --issue`, it records the
 * observation of whichever REAL queue worker runs it (that worker's configuration, release directory and key proof). It sends no mail and
 * changes no business data.
 *
 * It carries only its challenge id. It records nothing for an expired or superseded challenge, and running it twice is harmless (the
 * observation is written once per challenge; an identical repeat changes nothing). A failure is swallowed rather than retried or parked in
 * `failed_jobs`: the verifier then reports the queue as missing, which fails closed, and a failed-job row would carry the exception text.
 * docs/architecture/runtime-preflight.md.
 */
class RecordRuntimeObservation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $challengeId) {}

    public function handle(): void
    {
        RuntimeChallenges::observeQuietly('queue', $this->challengeId);
    }
}
