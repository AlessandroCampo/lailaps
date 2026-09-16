<?php

namespace App\Services\Source;

use App\Models\SourceRevision;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

final class SourceRevisionMaterializer
{
    public function materialize(SourceRevision $revision, string $archivePath): void
    {
        $revision->update(['status' => 'importing', 'error' => null]);
        $staging = storage_path('app/private/lailaps/staging/'.$revision->id);
        $destination = storage_path("app/private/lailaps/projects/{$revision->project_id}/revisions/{$revision->id}/source");
        File::deleteDirectory($staging);
        File::ensureDirectoryExists($staging);

        try {
            $this->extract($archivePath, $staging);
            $application = $this->applicationDirectory($staging, $revision->application_subdirectory);
            $metadata = array_replace($revision->provider_metadata ?? [], $this->sourceWarnings($staging));
            $hash = $this->contentHash($staging);

            File::ensureDirectoryExists(dirname($destination));
            if (is_dir($destination)) {
                throw new RuntimeException('La revisione immutabile e gia stata pubblicata.');
            }
            if (! File::moveDirectory($staging, $destination)) {
                throw new RuntimeException('Impossibile pubblicare la revisione sorgente.');
            }

            $relativeApplication = $application === $staging
                ? null
                : ltrim(str_replace('\\', '/', substr($application, strlen($staging))), '/');
            $revision->update([
                'status' => 'ready',
                'content_hash' => $hash,
                'source_path' => str_replace('\\', '/', $destination),
                'application_subdirectory' => $relativeApplication,
                'provider_metadata' => $metadata,
                'imported_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $exception) {
            File::deleteDirectory($staging);
            $revision->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000)]);
            throw $exception;
        } finally {
            @unlink($archivePath);
        }
    }

    private function extract(string $archivePath, string $destination): void
    {
        $compressedLimit = (int) config('audits.imports.compressed_bytes');
        if (! is_file($archivePath) || (int) filesize($archivePath) > $compressedLimit) {
            throw new RuntimeException('Archivio assente o superiore al limite compresso.');
        }

        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Archivio ZIP non leggibile.');
        }

        try {
            $entries = $this->entries($zip);
            $prefix = $this->containerPrefix($entries);
            $seen = [];
            $bytes = 0;
            $files = 0;
            foreach ($entries as $entry) {
                $relative = $prefix !== null && str_starts_with($entry['path'], $prefix.'/')
                    ? substr($entry['path'], strlen($prefix) + 1)
                    : $entry['path'];
                if ($relative === '' || $entry['directory']) {
                    continue;
                }
                $key = mb_strtolower($relative);
                if (isset($seen[$key])) {
                    throw new RuntimeException("Collisione tra percorsi ZIP: {$relative}");
                }
                foreach ($this->ancestors($relative) as $ancestor) {
                    if (($seen[mb_strtolower($ancestor)] ?? null) === 'file') {
                        throw new RuntimeException("Collisione file/directory ZIP: {$relative}");
                    }
                    $seen[mb_strtolower($ancestor)] ??= 'directory';
                }
                $seen[$key] = 'file';
                $files++;
                if ($files > (int) config('audits.imports.files')) {
                    throw new RuntimeException('Archivio superiore al limite di file.');
                }

                $target = $destination.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                File::ensureDirectoryExists(dirname($target));
                $input = $zip->getStream($entry['original']);
                $output = fopen($target, 'wb');
                if ($input === false || $output === false) {
                    throw new RuntimeException("Impossibile estrarre {$relative}");
                }
                try {
                    while (! feof($input)) {
                        $chunk = fread($input, 1024 * 1024);
                        if ($chunk === false) {
                            throw new RuntimeException("Errore durante l'estrazione di {$relative}");
                        }
                        $bytes += strlen($chunk);
                        if ($bytes > (int) config('audits.imports.extracted_bytes')) {
                            throw new RuntimeException('Archivio superiore al limite estratto.');
                        }
                        if (fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException("Scrittura incompleta di {$relative}");
                        }
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
            }
            if ($files === 0) {
                throw new RuntimeException('Archivio ZIP privo di file.');
            }
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, array{original: string, path: string, directory: bool}> */
    private function entries(ZipArchive $zip): array
    {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $original = $zip->getNameIndex($index);
            if (! is_string($original) || str_contains($original, "\0")) {
                throw new RuntimeException('Nome ZIP non valido.');
            }
            $name = str_replace('\\', '/', $original);
            if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name)) {
                throw new RuntimeException("Percorso assoluto vietato nello ZIP: {$name}");
            }
            $directory = str_ends_with($name, '/');
            $parts = array_values(array_filter(explode('/', rtrim($name, '/')), fn (string $part): bool => $part !== ''));
            if ($parts === [] || in_array('..', $parts, true)) {
                throw new RuntimeException("Traversal o percorso vuoto nello ZIP: {$name}");
            }
            $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== '.'));
            $path = implode('/', $parts);
            if ($path === '') {
                continue;
            }
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) && (($attributes >> 16) & 0xF000) === 0xA000) {
                throw new RuntimeException("Link simbolico vietato nello ZIP: {$name}");
            }
            $entries[] = compact('original', 'path', 'directory');
        }

        return $entries;
    }

    /** @param array<int, array{path: string}> $entries */
    private function containerPrefix(array $entries): ?string
    {
        $files = array_values(array_filter($entries, fn (array $entry): bool => ! ($entry['directory'] ?? false)));
        if ($files === []) {
            return null;
        }
        $first = explode('/', $files[0]['path'], 2);
        if (count($first) !== 2) {
            return null;
        }
        foreach ($files as $entry) {
            if (! str_starts_with($entry['path'], $first[0].'/')) {
                return null;
            }
        }

        return $first[0];
    }

    /** @return array<int, string> */
    private function ancestors(string $relative): array
    {
        $parts = explode('/', $relative);
        array_pop($parts);
        $ancestors = [];
        while ($parts !== []) {
            $ancestors[] = implode('/', $parts);
            array_pop($parts);
        }

        return $ancestors;
    }

    private function applicationDirectory(string $root, ?string $subdirectory): string
    {
        if ($subdirectory === null || trim($subdirectory) === '') {
            return $root;
        }
        $relative = trim(str_replace('\\', '/', $subdirectory), '/');
        if ($relative === '' || str_contains($relative, '..') || preg_match('/^[A-Za-z]:\//', $relative)) {
            throw new RuntimeException('Sottodirectory applicativa non valida.');
        }
        $candidate = realpath($root.'/'.$relative);
        $resolvedRoot = realpath($root);
        if ($candidate === false || $resolvedRoot === false || ! is_dir($candidate) || ! str_starts_with($candidate, $resolvedRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Sottodirectory applicativa inesistente.');
        }

        return $candidate;
    }

    private function contentHash(string $root): string
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new RuntimeException('La revisione estratta contiene un tipo di file non supportato.');
            }
            $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
        sort($paths, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($paths as $relative) {
            hash_update($hash, $relative."\0");
            $stream = fopen($root.'/'.$relative, 'rb');
            if ($stream === false) {
                throw new RuntimeException("Impossibile leggere {$relative}");
            }
            hash_update_stream($hash, $stream);
            fclose($stream);
            hash_update($hash, "\0");
        }

        return hash_final($hash);
    }

    /** @return array<string, mixed> */
    private function sourceWarnings(string $root): array
    {
        $warnings = [];
        if (is_file($root.'/.gitmodules')) {
            $warnings[] = 'Sono presenti submodule Git non materializzati.';
        }
        $lfs = false;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getSize() > 2048) {
                continue;
            }
            $stream = fopen($file->getPathname(), 'rb');
            $prefix = $stream ? (string) fread($stream, 160) : '';
            if ($stream) {
                fclose($stream);
            }
            if (str_starts_with($prefix, "version https://git-lfs.github.com/spec/")) {
                $lfs = true;
                break;
            }
        }
        if ($lfs) {
            $warnings[] = 'Sono presenti puntatori Git LFS non materializzati.';
        }

        return ['warnings' => $warnings];
    }
}
