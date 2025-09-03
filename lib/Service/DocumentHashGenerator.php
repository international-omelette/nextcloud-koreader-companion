<?php
namespace OCA\KoreaderCompanion\Service;

use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Service for generating document hashes compatible with KOReader sync
 * 
 * This service implements both binary and filename-based hashing methods 
 * used by KOReader for document identification in sync operations.
 */
class DocumentHashGenerator {

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * Generate binary hash using KOReader's fastDigest algorithm
     * 
     * This method implements the exact algorithm used by KOReader:
     * - Samples 1024-byte chunks at exponentially spaced offsets
     * - 11 samples at positions 1024 << (2*i) where i = -1 to 10
     * - Concatenates all samples and returns MD5 hash
     *
     * @param string $filePath Absolute path to the file
     * @return string|null MD5 hash of concatenated samples, or null on error
     */
    public function generateBinaryHash(string $filePath): ?string {
        try {
            if (!file_exists($filePath)) {
                $this->logger->debug('Binary hash generation failed: file not found', ['file' => $filePath]);
                return null;
            }

            if (!is_readable($filePath)) {
                $this->logger->debug('Binary hash generation failed: file not readable', ['file' => $filePath]);
                return null;
            }

            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                $this->logger->debug('Binary hash generation failed: cannot open file', ['file' => $filePath]);
                return null;
            }

            $fileSize = filesize($filePath);
            if ($fileSize === false) {
                fclose($handle);
                $this->logger->debug('Binary hash generation failed: cannot get file size', ['file' => $filePath]);
                return null;
            }

            $size = 1024;
            $samples = '';
            $samplesRead = 0;

            // KOReader fastDigest offsets: 11 samples at exponentially spaced positions
            // Offsets: 0, 1024, 4096, 16384, 65536, 262144, 1048576, 4194304, 16777216, 67108864, 268435456, 1073741824
            $expectedOffsets = [0, 1024, 4096, 16384, 65536, 262144, 1048576, 4194304, 16777216, 67108864, 268435456, 1073741824];
            
            foreach ($expectedOffsets as $offset) {
                
                // Check if offset exceeds file size
                if ($offset >= $fileSize) {
                    $this->logger->debug('Binary hash: offset exceeds file size, stopping', [
                        'file' => basename($filePath),
                        'offset' => $offset,
                        'fileSize' => $fileSize,
                        'samplesRead' => $samplesRead
                    ]);
                    break;
                }

                // Seek to offset
                if (fseek($handle, $offset) !== 0) {
                    $this->logger->debug('Binary hash: fseek failed, stopping', [
                        'file' => basename($filePath),
                        'offset' => $offset,
                        'samplesRead' => $samplesRead
                    ]);
                    break;
                }

                // Read sample
                $sample = fread($handle, $size);
                if ($sample === false || strlen($sample) === 0) {
                    $this->logger->debug('Binary hash: fread failed or empty, stopping', [
                        'file' => basename($filePath),
                        'offset' => $offset,
                        'samplesRead' => $samplesRead
                    ]);
                    break;
                }

                $samples .= $sample;
                $samplesRead++;
            }

            fclose($handle);

            if ($samplesRead === 0) {
                $this->logger->debug('Binary hash generation failed: no samples read', ['file' => $filePath]);
                return null;
            }

            $hash = md5($samples);
            
            $this->logger->debug('Binary hash generated successfully', [
                'file' => basename($filePath),
                'fileSize' => $fileSize,
                'samplesRead' => $samplesRead,
                'totalSampleBytes' => strlen($samples),
                'hash' => $hash
            ]);

            return $hash;

        } catch (\Exception $e) {
            $this->logger->error('Binary hash generation failed with exception', [
                'file' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Generate filename hash using MD5 of just the basename
     *
     * @param string $filePath Absolute path to the file
     * @return string|null MD5 hash of filename, or null on error
     */
    public function generateFilenameHash(string $filePath): ?string {
        try {
            $filename = basename($filePath);
            
            if (empty($filename)) {
                $this->logger->debug('Filename hash generation failed: empty filename', ['file' => $filePath]);
                return null;
            }

            $hash = md5($filename);
            
            $this->logger->debug('Filename hash generated successfully', [
                'filename' => $filename,
                'hash' => $hash
            ]);

            return $hash;

        } catch (\Exception $e) {
            $this->logger->error('Filename hash generation failed with exception', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate both binary and filename hashes for a document
     *
     * @param string $filePath Absolute path to the file
     * @return array Array with 'binary_hash', 'filename_hash', and 'file_path' keys
     */
    public function generateDocumentHashes(string $filePath): array {
        $result = [
            'binary_hash' => null,
            'filename_hash' => null,
            'file_path' => $filePath,
            'filename' => basename($filePath)
        ];

        $result['binary_hash'] = $this->generateBinaryHash($filePath);
        $result['filename_hash'] = $this->generateFilenameHash($filePath);

        $this->logger->debug('Document hashes generated', [
            'file' => basename($filePath),
            'binary_hash' => $result['binary_hash'],
            'filename_hash' => $result['filename_hash']
        ]);

        return $result;
    }

    /**
     * Generate binary hash for a Nextcloud Node object
     *
     * @param Node $file Nextcloud file node
     * @return string|null MD5 hash of concatenated samples, or null on error
     */
    public function generateBinaryHashFromNode(Node $file): ?string {
        try {
            // For Node objects, we need to read the content differently
            $content = $file->fopen('r');
            if (!$content) {
                $this->logger->debug('Binary hash generation failed: cannot open node', ['file' => $file->getName()]);
                return null;
            }

            $fileSize = $file->getSize();
            $size = 1024;
            $samples = '';
            $samplesRead = 0;

            // KOReader fastDigest offsets: 11 samples at exponentially spaced positions
            $expectedOffsets = [0, 1024, 4096, 16384, 65536, 262144, 1048576, 4194304, 16777216, 67108864, 268435456, 1073741824];
            
            foreach ($expectedOffsets as $offset) {
                
                // Check if offset exceeds file size
                if ($offset >= $fileSize) {
                    $this->logger->debug('Binary hash (node): offset exceeds file size, stopping', [
                        'file' => $file->getName(),
                        'offset' => $offset,
                        'fileSize' => $fileSize,
                        'samplesRead' => $samplesRead
                    ]);
                    break;
                }

                // Seek to offset
                if (fseek($content, $offset) !== 0) {
                    $this->logger->debug('Binary hash (node): fseek failed, stopping', [
                        'file' => $file->getName(),
                        'offset' => $offset,
                        'samplesRead' => $samplesRead
                    ]);
                    break;
                }

                // Read sample
                $sample = fread($content, $size);
                if ($sample === false || strlen($sample) === 0) {
                    $this->logger->debug('Binary hash (node): fread failed or empty, stopping', [
                        'file' => $file->getName(),
                        'offset' => $offset,
                        'samplesRead' => $samplesRead
                    ]);
                    break;
                }

                $samples .= $sample;
                $samplesRead++;
            }

            fclose($content);

            if ($samplesRead === 0) {
                $this->logger->debug('Binary hash generation (node) failed: no samples read', ['file' => $file->getName()]);
                return null;
            }

            $hash = md5($samples);
            
            $this->logger->debug('Binary hash (node) generated successfully', [
                'file' => $file->getName(),
                'fileSize' => $fileSize,
                'samplesRead' => $samplesRead,
                'totalSampleBytes' => strlen($samples),
                'hash' => $hash
            ]);

            return $hash;

        } catch (\Exception $e) {
            $this->logger->error('Binary hash generation (node) failed with exception', [
                'file' => $file->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Generate filename hash for a Nextcloud Node object
     *
     * @param Node $file Nextcloud file node
     * @return string|null MD5 hash of filename, or null on error
     */
    public function generateFilenameHashFromNode(Node $file): ?string {
        try {
            $filename = $file->getName();
            
            if (empty($filename)) {
                $this->logger->debug('Filename hash generation (node) failed: empty filename');
                return null;
            }

            $hash = md5($filename);
            
            $this->logger->debug('Filename hash (node) generated successfully', [
                'filename' => $filename,
                'hash' => $hash
            ]);

            return $hash;

        } catch (\Exception $e) {
            $this->logger->error('Filename hash generation (node) failed with exception', [
                'file' => $file->getName(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate both hashes for a Nextcloud Node object
     *
     * @param Node $file Nextcloud file node
     * @return array Array with 'binary_hash', 'filename_hash', 'file_path', and 'filename' keys
     */
    public function generateDocumentHashesFromNode(Node $file): array {
        $result = [
            'binary_hash' => null,
            'filename_hash' => null,
            'file_path' => $file->getPath(),
            'filename' => $file->getName(),
            'file_id' => $file->getId()
        ];

        $result['binary_hash'] = $this->generateBinaryHashFromNode($file);
        $result['filename_hash'] = $this->generateFilenameHashFromNode($file);

        $this->logger->debug('Document hashes (node) generated', [
            'file' => $file->getName(),
            'binary_hash' => $result['binary_hash'],
            'filename_hash' => $result['filename_hash']
        ]);

        return $result;
    }

    /**
     * Validate that a generated hash matches expected KOReader format
     *
     * @param string|null $hash The hash to validate
     * @return bool True if hash is valid MD5 format, false otherwise
     */
    public function isValidHash(?string $hash): bool {
        if ($hash === null) {
            return false;
        }
        
        // MD5 hashes are 32 character hexadecimal strings
        return preg_match('/^[a-f0-9]{32}$/', $hash) === 1;
    }

    /**
     * Get human-readable information about the binary hash algorithm
     *
     * @return array Information about the algorithm implementation
     */
    public function getAlgorithmInfo(): array {
        return [
            'name' => 'KOReader fastDigest',
            'description' => 'Samples 1024-byte chunks at exponentially spaced offsets',
            'sample_size' => 1024,
            'max_samples' => 11,
            'offsets' => [
                0, 1024, 4096, 16384, 65536, 262144, 1048576, 
                4194304, 16777216, 67108864, 268435456, 1073741824
            ],
            'hash_function' => 'MD5',
            'compatible_with' => 'KOReader sync binary matching method'
        ];
    }
}