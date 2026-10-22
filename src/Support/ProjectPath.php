<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Support;

use Symfony\Component\Filesystem\Path;

final class ProjectPath
{

    public static function relative(string $path, string $projectRoot): string
    {
        $absolute = Path::isAbsolute($path) ? $path : Path::join($projectRoot, $path);

        return Path::makeRelative(Path::canonicalize($absolute), Path::canonicalize($projectRoot));
    }

}
