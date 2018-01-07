<?php

namespace RobbyTaylor\FileStream;

use League\Flysystem\Filesystem;

trait StreamsFiles
{
    public function stream(Filesystem $filesystem, string $path)
    {
        return new FileStream($filesystem, $path);
    }
}