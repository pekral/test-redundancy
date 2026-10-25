<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

final readonly class FileFingerprints
{

    /**
     * @param array<string, array<string, int>> $fingerprints
     * @param array<string, \Pekral\TestRedundancy\Fingerprint\SharedDataset> $sharedDatasets
     * @param array<string, \Pekral\TestRedundancy\Fingerprint\DatasetParameters> $datasetParameters
     */
    public function __construct(public array $fingerprints, public array $sharedDatasets, public array $datasetParameters)
    {
    }

}
