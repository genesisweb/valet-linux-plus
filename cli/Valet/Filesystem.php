<?php

namespace Valet;

use ArrayObject;
use ConsoleComponents\Writer;
use Exception;
use FilesystemIterator;
use Traversable;
use Valet\Facades\CommandLine;

/**
 * Class Filesystem.
 */
class Filesystem
{
    /**
     * Delete the specified file or directory with files.
     *
     * @param string $files
     *
     * @throws Exception
     */
    public function remove($files): void
    {
        $files = iterator_to_array($this->toIterator($files));
        $files = array_reverse($files);
        foreach ($files as $file) {
            if (!file_exists($file) && !is_link($file)) {
                continue;
            }

            if (is_dir($file) && !is_link($file)) {
                $this->remove(new FilesystemIterator($file));

                if (true !== @rmdir($file)) {
                    throw new Exception(sprintf('Failed to remove directory "%s".', $file));
                }
            } else {
                // https://bugs.php.net/bug.php?id=52176
                if ('\\' === DIRECTORY_SEPARATOR && is_dir($file)) {
                    if (true !== @rmdir($file)) {
                        throw new Exception(sprintf('Failed to remove file "%s".', $file));
                    }
                } else {
                    if (true !== @unlink($file)) {
                        throw new Exception(sprintf('Failed to remove file "%s".', $file));
                    }
                }
            }
        }
    }

    /**
     * Determine if the given path is a directory.
     * TODO: Remove this function and use is_dir function directly.
     */
    public function isDir(string $path): bool
    {
        return is_dir($path);
    }

    /**
     * Create a directory.
     */
    public function mkdir(string $path, ?string $owner = null, int $mode = 0755): void
    {
        mkdir($path, $mode, true);

        if ($owner) {
            $this->chown($path, $owner);
        }
    }

    /**
     * Ensure that the given directory exists.
     */
    public function ensureDirExists(string $path, ?string $owner = null, int $mode = 0755): void
    {
        if (!$this->isDir($path)) {
            $this->mkdir($path, $owner, $mode);
        }
    }

    /**
     * Create a directory as the non-root user.
     */
    public function mkdirAsUser(string $path, int $mode = 0755): void
    {
        $this->mkdir($path, user(), $mode);
    }

    /**
     * Touch the given path.
     */
    public function touch(string $path, ?string $owner = null): string
    {
        touch($path);

        if ($owner !== null) {
            $this->chown($path, $owner);
        }

        return $path;
    }

    /**
     * Touch the given path as the non-root user.
     */
    public function touchAsUser(string $path): void
    {
        $this->touch($path, user());
    }

    /**
     * Determine if the given file exists.
     */
    public function exists(string $file): bool
    {
        return file_exists($file);
    }

    /**
     * Read the contents of the given file.
     */
    public function get(string $path): string
    {
        return file_get_contents($path);
    }

    /**
     * Write to the given file.
     */
    public function put(string $path, string $contents, ?string $owner = null): string
    {
        $status = file_put_contents($path, $contents);

        if ($owner) {
            $this->chown($path, $owner);
        }

        return $status;
    }

    /**
     * Write to the given file as the non-root user.
     */
    public function putAsUser(string $path, string $contents): string
    {
        return $this->put($path, $contents, user());
    }

    /**
     * Append the contents to the given file.
     */
    public function append(string $path, string $contents, ?string $owner = null): void
    {
        file_put_contents($path, $contents, FILE_APPEND);

        if ($owner) {
            $this->chown($path, $owner);
        }
    }

    /**
     * Append the contents to the given file as the non-root user.
     */
    public function appendAsUser(string $path, string $contents): void
    {
        $this->append($path, $contents, user());
    }

    /**
     * Copy the given directory to a new location.
     */
    public function copyDirectory(string $from, string $to): void
    {
        if ($this->isDir($to)) {
            Writer::warn('Destination directory already exists');
            return;
        }

        $this->mkdir($to);
        $sourceContents = $this->scandir($from);

        foreach ($sourceContents as $sourceContent) {
            if ($sourceContent == '.' || $sourceContent == '..') {
                continue;
            }

            $sourcePath = $from . '/' . $sourceContent;
            $destinationPath = $to . '/' . $sourceContent;

            if (!$this->isLink($sourcePath) && $this->isDir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destinationPath);
            } elseif ($this->isLink($sourcePath)) {
                $sourcePath = $this->readLink($sourcePath);
                $this->symlink($sourcePath, $destinationPath);
            } else {
                $this->copy($sourcePath, $destinationPath);
            }
        }
    }

    /**
     * Copy the given file to a new location.
     */
    public function copy(string $from, string $to): void
    {
        copy($from, $to);
    }

    /**
     * Copy the given file to a new location for the non-root user.
     */
    public function copyAsUser(string $from, string $to): void
    {
        copy($from, $to);

        $this->chown($to, user());
    }

    /**
     * Backup the given file.
     */
    public function backup(string $file): void
    {
        $to = $file.'.bak';

        if (!$this->exists($to) && $this->exists($file)) {
            rename($file, $to);
        }
    }

    /**
     * Restore a backed up file.
     */
    public function restore(string $file): void
    {
        $from = $file.'.bak';

        if ($this->exists($from)) {
            rename($from, $file);
        }
    }

    /**
     * Create a symlink to the given target.
     */
    public function symlink(string $target, string $link): void
    {
        if ($this->exists($link)) {
            $this->unlink($link);
        }

        symlink($target, $link);
    }

    /**
     * Create a symlink to the given target for the non-root user.
     *
     * This uses the command line as PHP can't change symlink permissions.
     */
    public function symlinkAsUser(string $target, string $link): void
    {
        if ($this->exists($link)) {
            $this->unlink($link);
        }

        CommandLine::runAsUser('ln -s '.escapeshellarg($target).' '.escapeshellarg($link));
    }

    /**
     * Comment a line in a file.
     */
    public function commentLine(string $line, string $file): void
    {
        if ($this->exists($file)) {
            $command = "sed -i '/{$line}/ s/^/# /' {$file}";
            CommandLine::run($command);
        }
    }

    /**
     * Uncomment a line in a file.
     */
    public function uncommentLine(string $line, string $file): void
    {
        if ($this->exists($file)) {
            $command = "sed -i '/{$line}/ s/# *//' {$file}";
            CommandLine::run($command);
        }
    }

    /**
     * Delete the file at the given path.
     */
    public function unlink(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /**
     * Change the owner of the given path.
     * TODO: Remove this function and use chown directly.
     */
    public function chown(string $path, string $user): void
    {
        chown($path, $user);
    }

    /**
     * Change the group of the given path.
     * TODO: Remove this function and use chgrp directly.
     */
    public function chgrp(string $path, string $group): void
    {
        chgrp($path, $group);
    }

    /**
     * Resolve the given path.
     */
    public function realpath(string $path): string
    {
        return realpath($path);
    }

    /**
     * Determine if the given path is a symbolic link.
     */
    public function isLink(string $path): bool
    {
        return is_link($path);
    }

    /**
     * Resolve the given symbolic link.
     */
    public function readLink(string $path): string
    {
        $link = $path;

        while (is_link($link)) {
            $link = readlink($link);
        }

        return $link;
    }

    /**
     * Remove all the broken symbolic links at the given path.
     */
    public function removeBrokenLinksAt(string $path): void
    {
        collect($this->scandir($path))
                ->filter(function ($file) use ($path) {
                    return $this->isBrokenLink($path.'/'.$file);
                })
                ->each(function ($file) use ($path) {
                    $this->unlink($path.'/'.$file);
                });
    }

    /**
     * Determine if the given path is a broken symbolic link.
     */
    public function isBrokenLink(string $path): bool
    {
        return is_link($path) && !file_exists($path);
    }

    /**
     * Scan the given directory path.
     */
    public function scandir(string $path): array
    {
        return collect(scandir($path))
            ->reject(function ($file) {
                return in_array($file, ['.', '..', '.keep']);
            })->values()->all();
    }

    /**
     * @param array|string $files
     *
     * @return ArrayObject|Traversable
     */
    private function toIterator($files)
    {
        if (!$files instanceof Traversable) {
            $files = new ArrayObject(is_array($files) ? $files : [$files]);
        }

        return $files;
    }
}
