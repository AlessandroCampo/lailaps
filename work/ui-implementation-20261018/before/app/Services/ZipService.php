<?php

namespace App\Services;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class ZipService
{
    public const int MAX_UPLOAD_KB = 51200;

    private const int MAX_ENTRIES = 10000;

    private const int MAX_EXTRACTED_BYTES = 262144000;

    public function withSnapshot(UploadedFile $upload, Closure $callback): mixed
    {
        if ($upload->getSize() > self::MAX_UPLOAD_KB * 1024) {
            throw ValidationException::withMessages(['source' => 'The ZIP file must not exceed 50 MiB.']);
        }

        $storage = Storage::disk('projects');
        $temporary = '.tmp/project-sources/'.Str::uuid();
        $destination = null;
        $completed = false;

        try {
            if (! $storage->makeDirectory($temporary.'/files')) {
                throw new RuntimeException('Unable to create the temporary directory.');
            }

            $path = $storage->path($temporary.'/files');
            $this->extract($upload, $path);
            $hash = $this->contentHash($path);

            if ($storage->putFileAs($temporary, $upload, 'source.zip') === false) {
                throw new RuntimeException('Unable to save the ZIP archive.');
            }

            $result = $callback($hash, function (string $path) use ($storage, $temporary, &$destination): void {
                if ($storage->directoryExists($path)) {
                    throw new RuntimeException('The snapshot directory already exists.');
                }

                $destination = $path;

                if (! $storage->move($temporary, $destination)) {
                    throw new RuntimeException('Unable to save the snapshot.');
                }
            });
            $completed = true;

            return $result;
        } finally {
            $storage->deleteDirectory($temporary);

            if (! $completed && $destination !== null) {
                $storage->deleteDirectory($destination);
            }
        }
    }

    private function extract(UploadedFile $upload, string $destination): void
    {
        $archive = new ZipArchive;

        if ($archive->open($upload->getPathname(), ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw ValidationException::withMessages(['source' => 'The ZIP archive is invalid or corrupted.']);
        }

        try {
            if ($archive->numFiles < 1 || $archive->numFiles > self::MAX_ENTRIES) {
                throw ValidationException::withMessages(['source' => 'The ZIP archive must contain between 1 and 10,000 entries.']);
            }

            $seen = [];
            $bytes = 0;
            $files = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);

                if ($entry === false) {
                    throw ValidationException::withMessages(['source' => 'Unable to read a ZIP entry.']);
                }

                $name = $entry['name'];
                $directory = str_ends_with($name, '/');
                $relative = $directory ? substr($name, 0, -1) : $name;
                $segments = explode('/', $relative);

                if ($relative === '' || preg_match('/[\\\\:\x00-\x1F]/', $relative)
                    || array_intersect($segments, ['', '.', '..']) || isset($seen[$relative])) {
                    throw ValidationException::withMessages(['source' => 'The ZIP archive contains invalid or duplicate paths.']);
                }

                $parent = '';

                foreach (array_slice($segments, 0, -1) as $segment) {
                    $parent = $parent === '' ? $segment : $parent.'/'.$segment;

                    if (isset($seen[$parent]) && ! $seen[$parent]) {
                        throw ValidationException::withMessages(['source' => 'The ZIP archive contains conflicting file and directory paths.']);
                    }
                }

                $seen[$relative] = $directory;
                $system = 0;
                $attributes = 0;

                if (! $archive->getExternalAttributesIndex($index, $system, $attributes)) {
                    throw ValidationException::withMessages(['source' => 'Unable to verify the type of a ZIP entry.']);
                }

                $type = ($attributes >> 16) & 0170000;

                if ($system === ZipArchive::OPSYS_UNIX && $type !== 0
                    && $type !== ($directory ? 0040000 : 0100000)) {
                    throw ValidationException::withMessages(['source' => 'The ZIP archive must not contain symlinks or special files.']);
                }

                $target = $destination.'/'.$relative;

                if (! $directory && is_dir($target)) {
                    throw ValidationException::withMessages(['source' => 'The ZIP archive contains conflicting file and directory paths.']);
                }

                if ($directory) {
                    File::ensureDirectoryExists($target, 0700);

                    continue;
                }

                if ($entry['size'] > self::MAX_EXTRACTED_BYTES - $bytes) {
                    throw ValidationException::withMessages(['source' => 'The extracted content must not exceed 250 MiB.']);
                }

                File::ensureDirectoryExists(dirname($target), 0700);
                $input = $archive->getStreamIndex($index);

                if ($input === false) {
                    throw ValidationException::withMessages(['source' => 'Unable to read a file from the ZIP archive.']);
                }

                try {
                    $output = fopen($target, 'xb');

                    if ($output === false) {
                        throw new RuntimeException('Unable to write an extracted file.');
                    }

                    try {
                        $copied = stream_copy_to_stream($input, $output, self::MAX_EXTRACTED_BYTES - $bytes + 1);

                        if ($copied === false || $copied !== $entry['size']) {
                            throw ValidationException::withMessages(['source' => 'The ZIP content is corrupted or exceeds the extraction limit.']);
                        }

                        $bytes += $copied;

                        if ($bytes > self::MAX_EXTRACTED_BYTES) {
                            throw ValidationException::withMessages(['source' => 'The extracted content must not exceed 250 MiB.']);
                        }
                    } finally {
                        fclose($output);
                    }
                } finally {
                    fclose($input);
                }

                if (hash_file('crc32b', $target) !== sprintf('%08x', $entry['crc'])) {
                    throw ValidationException::withMessages(['source' => 'A file in the ZIP archive is corrupted.']);
                }

                $files++;
            }

            if ($files === 0) {
                throw ValidationException::withMessages(['source' => 'The ZIP archive must contain at least one file.']);
            }
        } finally {
            $archive->close();
        }
    }

    private function contentHash(string $directory): string
    {
        $files = File::allFiles($directory, true);
        usort($files, fn ($left, $right): int => strcmp($left->getRelativePathname(), $right->getRelativePathname()));
        $context = hash_init('sha256');

        foreach ($files as $file) {
            $path = $file->getRelativePathname();
            $hash = hash_file('sha256', $file->getPathname(), true);

            if ($hash === false) {
                throw new RuntimeException('Unable to calculate the file hash.');
            }

            hash_update($context, strlen($path).':'.$path.$hash);
        }

        return hash_final($context);
    }
}
