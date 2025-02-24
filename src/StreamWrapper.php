<?php

namespace DrupalLibraries\Flystream;

use ArrayIterator;
use Iterator;
use IteratorAggregate;
use League\Flysystem\Config;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use Pimple\Container;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class StreamWrapper
{
    protected ?Lock $lock = null;

    protected ?string $path = null;

    protected ?string $mode = null;

    protected ?Iterator $dir = null;

    /**
     * @var resource|null
     */
    protected $read = null;

    protected ?BufferInterface $buffer = null;

    /** @var resource */
    public $context;

    public function dir_closedir(): bool
    {
        $this->log('info', __METHOD__);
        $this->dir = null;
        $this->path = null;
        return true;
    }

    public function dir_opendir($path, $options)
    {
        $this->log('info', __METHOD__, func_get_args());
        try {
            $this->dir = $this->getDir($path);
            $this->path = $path;
            return true;

            // @codeCoverageIgnoreStart
            // InMemoryFilesystemAdapter->listContents() returns an empty
            // array when a directory doesn't exist.
        } catch (Throwable $e) {
            $this->log('error', __METHOD__, func_get_args() + [
                'exception' => $e,
            ]);
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @return string|false
     */
    public function dir_readdir()
    {
        $this->log('info', __METHOD__);
        if ($this->dir->valid()) {
            $current = $this->dir->current();
            $this->dir->next();
            return $current->path();
        }
        return false;
    }

    public function dir_rewinddir(): bool
    {
        $this->log('info', __METHOD__);
        $this->dir = $this->getDir($this->path);
        return true;
    }

    public function mkdir($path, $mode, $options)
    {
        $this->log('info', __METHOD__, func_get_args());
        $visibility = $this->get(VisibilityConverter::class);
        $filesystem = $this->getFilesystemObject($path);
        try {
            $config = $this->getConfig($path, [
                Config::OPTION_DIRECTORY_VISIBILITY =>
                    $visibility->inverseForDirectory($mode),
            ]);
            $filesystem->createDirectory($path, $config);
            return true;
            // @codeCoverageIgnoreStart
        } catch (Throwable $e) {
            $this->log('error', __METHOD__, func_get_args() + [
                'exception' => $e,
            ]);
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    public function rename($path_from, $path_to)
    {
        $this->log('info', __METHOD__, func_get_args());
        $filesystem = $this->getFilesystemObject($path_from);
        try {
            $config = $this->getConfig($path_to);
            $filesystem->move($path_from, $path_to, $config);
            return true;
        } catch (Throwable $e) {
            $this->log('error', __METHOD__, func_get_args() + [
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function rmdir($path, $options)
    {
        $this->log('info', __METHOD__, func_get_args());
        $filesystem = $this->getFilesystemObject($path);
        try {
            $filesystem->deleteDirectory($path);
            return true;

            // @codeCoverageIgnoreStart
            // InMemoryFilesystemAdapter->deleteDirectory() does not raise
            // an error if the target doesn't exist.
        } catch (Throwable $e) {
            $this->log('error', __METHOD__, func_get_args() + [
                'exception' => $e,
            ]);
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @return resource
     */
    public function stream_cast($cast_as)
    {
        $this->log('info', __METHOD__, func_get_args());
        $this->openRead();
        return $this->read;
    }

    public function stream_close(): void
    {
        $this->log('info', __METHOD__);
        if ($this->read !== null) {
            fclose($this->read);
            $this->read = null;
        }
        if ($this->buffer !== null) {
            $this->buffer->close();
            $this->buffer = null;
        }
    }

    public function stream_eof(): bool
    {
        $this->log('info', __METHOD__);
        return feof($this->read);
    }

    public function stream_flush(): bool
    {
        $this->log('info', __METHOD__);
        try {
            $this->buffer->flush(
                $this->getFilesystemObject($this->path),
                $this->path,
                $this->getConfig($this->path)
            );
        } catch (Throwable $e) {
            $this->log('error', __METHOD__, func_get_args() + [
                'exception' => $e,
            ]);
            return false;
        }
        return true;
    }

    public function stream_lock($operation)
    {
        $this->log('info', __METHOD__, func_get_args());

        $locks = $this->get(LockRegistryInterface::class);

        // For now, ignore non-blocking requests
        $operation &= ~LOCK_NB;

        $shared = $operation === LOCK_SH;
        $exclusive = $operation === LOCK_EX;
        if ($shared || $exclusive) {
            $type = $shared
                ? Lock::TYPE_SHARED
                : Lock::TYPE_EXCLUSIVE;
            $lock = new Lock($this->path, $type);
            $result = $locks->acquire($lock);
            if ($result) {
                $this->lock = $lock;
            }
            return $result;
        }

        $result = $locks->release($this->lock);
        if ($result) {
            $this->lock = null;
        }
        return $result;
    }

    /**
     * @param mixed $value
     */
    public function stream_metadata($path, $option, $value) {
        $this->log('info', __METHOD__, func_get_args());
        if ($option === STREAM_META_TOUCH) {
            $time = time();
            $filesystem = $this->getFilesystemObject($path);
            $config = $this->getConfig($path);
            try {
                $filesystem->write($path, '', $config);
                return true;

                // @codeCoverageIgnoreStart
                // InMemoryFilesystemAdapter->write() does not raise errors
            } catch (Throwable $e) {
                $this->log('error', __METHOD__, func_get_args() + [
                    'exception' => $e,
                ]);
            }
            // @codeCoverageIgnoreEnd
        }
        return false;
    }

    public function stream_open($path, $mode, $options, &$opened_path) {
        $this->log('info', __METHOD__, func_get_args());
        $this->path = $path;
        $this->mode = $mode;
        if (strpbrk($mode, 'waxc') !== false) {
            $this->stream_write('');
            $this->stream_flush();
        } elseif (strpbrk($mode, 'r') !== false) {
            $this->openRead();
        }
        return true;
    }

    public function stream_read($count) {
        $this->log('info', __METHOD__, func_get_args());
        $this->openRead();
        return stream_get_contents($this->read, $count);
    }

    public function stream_seek($offset, $whence = \Drupal\Core\StreamWrapper\SEEK_SET) {
        $this->log('info', __METHOD__, func_get_args());
        $this->openRead();
        return fseek($this->read, $offset, $whence) === 0;
    }

    public function stream_set_option($option, $arg1, $arg2) {
        $this->log('info', __METHOD__, func_get_args());
        $this->openRead();

        if ($option === STREAM_OPTION_BLOCKING) {
            return stream_set_blocking($this->read, $arg1);
        }

        if ($option === STREAM_OPTION_READ_TIMEOUT) {
            return stream_set_timeout($this->read, $arg1, $arg2);
        }

        return stream_set_write_buffer($this->read, $arg2) === 0;
    }

    /**
     * @return array|false
     */
    public function stream_stat() {
        $this->log('info', __METHOD__);
        $this->openRead();
        return fstat($this->read);
    }

    public function stream_tell() {
        $this->log('info', __METHOD__);
        $this->openRead();
        return (int) ftell($this->read);
    }

    public function stream_truncate($new_size) {
        $this->log('info', __METHOD__, func_get_args());
        $this->openRead();
        return ftruncate($this->read, $new_size);
    }

    /**
     * @return int|false
     */
    public function stream_write($data) {
        $this->log('info', __METHOD__, func_get_args());
        try {
            if ($this->mode === 'r') {
                throw UnableToWriteFile::atLocation(
                    $this->path,
                    'Stream mode is "r" which does not allow writing'
                );
            }
            if ($this->buffer === null) {
                $this->buffer = $this->get(BufferInterface::class);
            }
            return $this->buffer->write($data);
        } catch (Throwable $e) {
            $this->log('error', __METHOD__, func_get_args() + [
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function unlink($path) {
        $this->log('info', __METHOD__, func_get_args());
        $filesystem = $this->getFilesystemObject($path);
        try {
            $filesystem->delete($path);
            return true;

            // @codeCoverageIgnoreStart
            // InMemoryFilesystemAdapter->delete() does not raise an error if the target doesn't exist.
        } catch (Throwable $e) {
            $this->log('error', __METHOD__, func_get_args() + [
                'exception' => $e,
            ]);
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @return array|false
     */
    public function url_stat($path, $flags) {
        $this->log('info', __METHOD__, func_get_args());

        $filesystem = $this->getFilesystemObject($path);
        $visibility = $this->get(VisibilityConverter::class);

        if ($filesystem->fileExists($path)) {
            $mode = 0100000 | $visibility->forFile(
                $filesystem->visibility($path)
            );
            $size =  $filesystem->fileSize($path);
            $mtime = $filesystem->lastModified($path);
        } elseif ($filesystem->directoryExists($path)) {
            $mode = 0040000 | $visibility->forDirectory(
                $filesystem->visibility($path)
            );
            $size =  0;
            $mtime = $filesystem->lastModified($path);
        } else {
            return false;
        }

        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => $mode,
            'nlink' => 0,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => $size,
            'atime' => 0,
            'mtime' => $mtime,
            'ctime' => 0,
            'blksize' => 0,
            'blocks' => 0,
        ];
    }

    protected function getConfig(string $path, array $overrides = []): array
    {
        $config = [];
        if ($this->context !== null) {
            $protocol = $this->getProtocol($path);
            $context = stream_context_get_options($this->context);
            $config = $context[$protocol] ?? [];
        }
        return array_merge($config, $overrides);
    }

    protected function getFilesystemObject(string $path): FilesystemOperator
    {
        $protocol = $this->getProtocol($path);
        $registry = $this->get(FilesystemRegistry::class);
        return $registry->get($protocol);
    }

    /**
     * parse_url() chokes on path-less URLs (like foo://), so in that case, fall back to manual parsing.
     *
     * @param string $path
     * @return string|null
     */
    protected function getProtocol(string $path): ?string
    {
        $protocol = parse_url($path, PHP_URL_SCHEME);
        if ($protocol === false && ($pos = strpos($path, ':/')) !== false) {
            $protocol = substr($path, 0, $pos);
        }
        return $protocol ?: null;
    }

    protected function get(string $key)
    {
        return ServiceLocator::get($key);
    }

    protected function getDir(string $path): Iterator
    {
        $filesystem = $this->getFilesystemObject($path);
        $dir = $filesystem->listContents($path, false);
        if ($dir instanceof IteratorAggregate) {
            return $dir->getIterator();
        }
        // @codeCoverageIgnoreStart
        // InMemoryFilesystemAdapter->listContents() only ever returns
        // a generator
        if ($dir instanceof Iterator) {
            return $dir;
        }
        return new ArrayIterator($dir);
        // @codeCoverageIgnoreEnd
    }

    protected function openRead(): void
    {
        if ($this->read === null) {
            $filesystem = $this->getFilesystemObject($this->path);
            $this->read = $filesystem->readStream($this->path);
        }
    }

    protected function log(
        string $level,
        string $message,
        array $context = []
    ): void {
        $logger = $this->get(LoggerInterface::class);
        $logger->log($level, $message, $context);
    }
}
