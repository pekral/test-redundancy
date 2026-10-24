<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

final readonly class FileFingerprints
{

    /**
     * @param array<string, array<string, int>> $fingerprints
     * @param array<string, \Pekral\TestRedundancy\Fingerprint\SharedDataset> $sharedDatasets
     */
    public function __construct(public array $fingerprints, public array $sharedDatasets)
    {
    }

}
