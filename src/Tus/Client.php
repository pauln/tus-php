<?php

namespace TusPhp\Tus;

use TusPhp\File;
use TusPhp\Cache\Cacheable;
use TusPhp\Exception\Exception;
use TusPhp\Exception\FileException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use TusPhp\Exception\ConnectionException;
use GuzzleHttp\Exception\ConnectException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class Client extends AbstractTus
{
    /** @var GuzzleClient */
    protected $client;

    /** @var string */
    protected $filePath;

    /** @var int */
    protected $fileSize;

    /** @var string */
    protected $fileName;

    /** @var string */
    protected $key;

    /** @var string */
    protected $checksum;

    /** @var int */
    protected $partialOffset = -1;

    /** @var bool */
    protected $partial = false;

    /** @var string */
    protected $checksumAlgorithm = 'sha256';

    /** @var string */
    protected $baseUri;

    /**
     * Client constructor.
     *
     * @param string           $baseUrl
     * @param Cacheable|string $cacheAdapter
     */
    public function __construct($baseUrl, $cacheAdapter = 'file')
    {
        $this->client = new GuzzleClient([
            'base_uri' => $baseUrl,
        ]);

        $this->baseUri = $baseUrl;

        $this->setCache($cacheAdapter);
    }

    /**
     * Set file properties.
     *
     * @param string $file File path.
     * @param string $name File name.
     *
     * @return Client
     */
    public function file($file, $name = null)
    {
        $this->filePath = $file;

        if ( ! file_exists($file) || ! is_readable($file)) {
            throw new FileException('Cannot read file: ' . $file);
        }

        $this->fileName = isset($name) ? $name : basename($this->filePath);
        $this->fileSize = filesize($file);

        return $this;
    }

    /**
     * Get file path.
     *
     * @return string|null
     */
    public function getFilePath()
    {
        return $this->filePath;
    }

    /**
     * Set file name.
     *
     * @param string $name
     *
     * @return Client
     */
    public function setFileName($name)
    {
        $this->fileName = $name;

        return $this;
    }

    /**
     * Get file name.
     *
     * @return string|null
     */
    public function getFileName()
    {
        return $this->fileName;
    }

    /**
     * Get file size.
     *
     * @return int|null
     */
    public function getFileSize()
    {
        return $this->fileSize;
    }

    /**
     * Get guzzle client.
     *
     * @return GuzzleClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Get checksum.
     *
     * @return string
     */
    public function getChecksum()
    {
        if (empty($this->checksum)) {
            $this->checksum = hash_file($this->getChecksumAlgorithm(), $this->getFilePath());
        }

        return $this->checksum;
    }

    /**
     * Set key.
     *
     * @param string $key
     *
     * @return Client
     */
    public function setKey($key)
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Get key.
     *
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * Set checksum algorithm.
     *
     * @param string $algorithm
     *
     * @return Client
     */
    public function setChecksumAlgorithm($algorithm)
    {
        $this->checksumAlgorithm = $algorithm;

        return $this;
    }

    /**
     * Get checksum algorithm.
     *
     * @return string
     */
    public function getChecksumAlgorithm()
    {
        return $this->checksumAlgorithm;
    }

    /**
     * Check if this is a partial upload request.
     *
     * @return bool
     */
    public function isPartial()
    {
        return $this->partial;
    }

    /**
     * Get partial offset.
     *
     * @return int
     */
    public function getPartialOffset()
    {
        return $this->partialOffset;
    }

    /**
     * Set offset and force this to be a partial upload request.
     *
     * @param int $offset
     *
     * @return self
     */
    public function seek($offset)
    {
        $this->partialOffset = $offset;

        $this->partial();

        return $this;
    }

    /**
     * Upload file.
     *
     * @param int $bytes Bytes to upload
     *
     * @throws ConnectionException
     *
     * @return int
     */
    public function upload($bytes = -1)
    {
        $bytes = $bytes < 0 ? $this->getFileSize() : $bytes;
        $key   = $this->getKey();

        try {
            // Check if this upload exists with HEAD request.
            $this->sendHeadRequest($key);
        } catch (FileException $e) {
            $this->create($key);
        } catch (ClientException $e) {
            $this->create($key);
        } catch (ConnectException $e) {
            throw new ConnectionException("Couldn't connect to server.");
        }

        // Now, resume upload with PATCH request.
        return $this->sendPatchRequest($this->getKey(), $bytes);
    }

    /**
     * Returns offset if file is partially uploaded.
     *
     * @return bool|int
     */
    public function getOffset()
    {
        $key = $this->getKey();

        try {
            $offset = $this->sendHeadRequest($key);
        } catch (FileException $e) {
            return false;
        } catch (ClientException $e) {
            return false;
        }

        return $offset;
    }

    /**
     * Create resource with POST request.
     *
     * @param string $key
     *
     * @throws FileException
     *
     * @return void
     */
    public function create($key)
    {
        $headers = [
            'Tus-Resumable' => self::TUS_PROTOCOL_VERSION,
            'Upload-Length' => $this->fileSize,
            'Upload-Checksum' => $this->getUploadChecksumHeader(),
            'Upload-Metadata' => 'filename ' . base64_encode($this->fileName),
        ];

        if (!empty($key)) {
            $headers['Upload-Key'] = $key;
        }

        if ($this->isPartial()) {
            $headers += ['Upload-Concat' => 'partial'];
        }

        $response = $this->getClient()->post($this->apiPath . '/', [
            'headers' => $headers,
        ]);

        $statusCode = $response->getStatusCode();

        if (HttpResponse::HTTP_CREATED !== $statusCode) {
            throw new FileException('Unable to create resource.');
        }

        if (empty($key)) {
            // Extract key from response headers, if present
            $location = current($response->getHeader('Location'));
            if ($location !== false) {
                $basePath = $this->baseUri . $this->apiPath . '/';
                $newKey = str_replace($basePath, '', $location);
                $this->setKey($newKey);
            }
        }
    }

    /**
     * Concatenate 2 or more partial uploads.
     *
     * @param string $key
     * @param mixed  $partials
     *
     * @return string
     */
    public function concat($key, ...$partials)
    {
        $response = $this->getClient()->post($this->apiPath, [
            'headers' => [
                'Tus-Resumable' => self::TUS_PROTOCOL_VERSION,
                'Upload-Length' => $this->fileSize,
                'Upload-Key' => $key,
                'Upload-Checksum' => $this->getUploadChecksumHeader(),
                'Upload-Metadata' => 'filename ' . base64_encode($this->fileName),
                'Upload-Concat' => self::UPLOAD_TYPE_FINAL . ';' . implode(' ', $partials),
            ],
        ]);

        $data       = json_decode($response->getBody(), true);
        $checksum   = $data['data']['checksum'] ? $data['data']['checksum'] : null;
        $statusCode = $response->getStatusCode();

        if (HttpResponse::HTTP_CREATED !== $statusCode || ! $checksum) {
            throw new FileException('Unable to create resource.');
        }

        return $checksum;
    }

    /**
     * Send DELETE request.
     *
     * @param string $key
     *
     * @throws FileException
     *
     * @return void
     */
    public function delete($key)
    {
        try {
            $this->getClient()->delete($this->apiPath . '/' . $key, [
                'headers' => [
                    'Tus-Resumable' => self::TUS_PROTOCOL_VERSION,
                ],
            ]);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if (HttpResponse::HTTP_NOT_FOUND === $statusCode || HttpResponse::HTTP_GONE === $statusCode) {
                throw new FileException('File not found.');
            }
        }
    }

    /**
     * Set as partial request.
     *
     * @param bool $state
     *
     * @return void
     */
    protected function partial($state = true)
    {
        $this->partial = $state;

        if ( ! $this->partial) {
            return;
        }

        $key = $this->getKey();

        if (false !== strpos($key, self::PARTIAL_UPLOAD_NAME_SEPARATOR)) {
            list($key, /* $partialKey */) = explode(self::PARTIAL_UPLOAD_NAME_SEPARATOR, $key);
        }

        $this->key = $key . uniqid(self::PARTIAL_UPLOAD_NAME_SEPARATOR);
    }

    /**
     * Send HEAD request.
     *
     * @param string $key
     *
     * @throws FileException
     *
     * @return int
     */
    protected function sendHeadRequest($key)
    {
        $response = $this->getClient()->head($this->apiPath . '/' . $key, [
            'headers' => [
                'Tus-Resumable' => self::TUS_PROTOCOL_VERSION,
            ],
        ]);

        $statusCode = $response->getStatusCode();

        if (HttpResponse::HTTP_OK !== $statusCode) {
            throw new FileException('File not found.');
        }

        return (int) current($response->getHeader('upload-offset'));
    }

    /**
     * Send PATCH request.
     *
     * @param string $key
     * @param int    $bytes
     * @param int    $offset
     *
     * @throws Exception
     * @throws FileException
     * @throws ConnectionException
     *
     * @return int
     */
    protected function sendPatchRequest($key, $bytes, $offset = 0)
    {
        $data    = $this->getData($key, $bytes);

        $headers = [
            'Tus-Resumable' => self::TUS_PROTOCOL_VERSION,
            'Content-Type' => 'application/offset+octet-stream',
            'Content-Length' => strlen($data),
            'Upload-Checksum' => $this->getUploadChecksumHeader(),
            'Upload-Offset' => $offset,
        ];

        if ($this->isPartial()) {
            $headers += ['Upload-Concat' => self::UPLOAD_TYPE_PARTIAL];
        }

        try {
            $response = $this->getClient()->patch($this->apiPath . '/' . $key, [
                'body' => $data,
                'headers' => $headers,
            ]);

            return (int) current($response->getHeader('upload-offset'));
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if (HttpResponse::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE === $statusCode) {
                throw new FileException('The uploaded file is corrupt.');
            }

            if (HttpResponse::HTTP_CONTINUE === $statusCode) {
                throw new ConnectionException('Connection aborted by user.');
            }

            throw new Exception($e->getResponse()->getBody(), $statusCode);
        } catch (ConnectException $e) {
            throw new ConnectionException("Couldn't connect to server.");
        }
    }

    /**
     * Get X bytes of data from file.
     *
     * @param string $key
     * @param int    $bytes
     *
     * @return string
     */
    protected function getData($key, $bytes)
    {
        $file   = new File;
        $handle = $file->open($this->getFilePath(), $file::READ_BINARY);
        $offset = $this->partialOffset;

        if ($offset < 0) {
            $fileMeta = $this->getCache()->get($key);
            $offset   = $fileMeta['offset'];
        }

        $file->seek($handle, $offset);

        $data = $file->read($handle, $bytes);

        $file->close($handle);

        return (string) $data;
    }

    /**
     * Get upload checksum header.
     *
     * @return string
     */
    protected function getUploadChecksumHeader()
    {
        return $this->getChecksumAlgorithm() . ' ' . base64_encode($this->getChecksum());
    }
}
