<?php

namespace RobbyTaylor\FileStream;

use League\Flysystem\Filesystem;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileStream
{
    /**
     * @var string
     */
    protected $disposition = 'inline';

    /**
     * @var int|null
     */
    protected $end = null;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var string
     */
    protected $mimetype = '';

    /**
     * @var string
     */
    protected $path;

    /**
     * @var int
     */
    protected $size = 0;

    /**
     * @var int|null
     */
    protected $start = null;

    /**
     * @var int
     */
    protected $status = 200;

    public function __construct(Filesystem $filesystem, string $path)
    {
        $this->filesystem = $filesystem;
        $this->path = $path;
    }

    public function download(): self
    {
        $this->disposition = 'download';

        return $this;
    }

    public function filename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    protected function fullResponse($stream)
    {
        fpassthru($stream);
    }

    public function size(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename ?: basename($this->path);
    }

    public function getHeaders(): array
    {
        $contentLength = $this->getSize();
        $start = (int) $this->start;

        $headers = [
            'Accept-Ranges'       => 'bytes',
            'Content-Disposition' => $this->disposition.'; filename="'.$this->getFilename().'"',
            'Content-Range'       => "bytes {$start}-{$this->getLastByte()}/$contentLength}",
            'Content-Length'      => $contentLength,
            'Content-Type'        => $this->getMimetype(),
        ];

        return $headers;
    }

    protected function getLastByte(): int
    {
        if ($this->end === null) {
            return $this->getSize() - 1;
        }

        return $this->end;
    }

    public function getMimetype(): string
    {
        return $this->mimetype ?: $this->filesystem->getMimetype($this->path);
    }

    public function getSize(): int
    {
        return $this->size ?: $this->filesystem->size($this->path);
    }

    public function mimetype($type): self
    {
        $this->mimetype = $type;

        return $this;
    }

    public function partial($rangeHeader = ''): self
    {
        $size = $length = $this->getSize();
        $this->start = 0;
        $this->end = $size - 1;

        if (empty($rangeHeader) === false) {
            $start = $this->start;
            $end = $this->end;
            list(, $range) = explode('=', $rangeHeader, 2);

            if (strpos($range, ',') !== false) {
                abort(416, null, ['Content-Range' => "bytes $this->start-$this->end/$size"]);
            }

            if ($range === '-') {
                $start = $size - substr($range, 1);
            } else {
                $range = explode('-', $range);
                $start = $range[0];
                $end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size;
            }

            $end = ($end > $this->end) ? $this->end : $end;

            if ($start > $end || $start > $size - 1 || $end >= $size) {
                abort(416, null, ['Content-Range' => "bytes $this->start-$this->end/$size"]);
            }

            $this->start = $start;
            $this->end = $end;

            $this->size($this->end - $this->start + 1)->status(206);
        }

        return $this;
    }

    protected function partialResponse($stream)
    {
        fseek($stream, $this->start);
        $buffer = 1024 * 8;

        while (!feof($stream) && ($p = ftell($stream)) <= $this->end) {
            if ($p + $buffer > $this->end) {
                $buffer = $this->end - $p + 1;
            }

            set_time_limit(0);
            echo fread($stream, $buffer);
            flush();
        }
    }

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function toResponse()
    {
        $stream = $this->filesystem->readStream($this->path);

        return new StreamedResponse(function () use ($stream) {
            $strategy = $this->start === null ? 'fullResponse' : 'partialResponse';

            $this->$strategy($stream);
        }, $this->status, $this->getHeaders());
    }
}
