<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service;

use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use Kiwilan\Archive\Archive;

/**
 * Service for extracting metadata from PDF documents
 * 
 * This service provides comprehensive PDF metadata extraction using
 * the kiwilan/php-archive library with fallback to filename parsing.
 */
class PdfMetadataExtractor {

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * Extract metadata from PDF file
     * 
     * @param Node $file PDF file node
     * @return array Metadata array with keys: title, author, subject, creator, 
     *              creation_date, modification_date, pages, language, publisher
     */
    public function extractMetadata(Node $file): array {
        // Validate PDF file
        if (!$this->validatePdfFile($file)) {
            $this->logger->info('Invalid PDF file, falling back to filename', ['file' => $file->getPath()]);
            return $this->extractFromFilename($file);
        }

        // Try to extract using PDF library
        $metadata = $this->extractFromPdfLibrary($file);
        
        if ($metadata === null) {
            $this->logger->info('PDF library extraction failed, falling back to filename', ['file' => $file->getPath()]);
            return $this->extractFromFilename($file);
        }

        // Enhance with filename parsing if title is missing
        if (empty($metadata['title'])) {
            $filenameData = $this->extractFromFilename($file);
            $metadata['title'] = $filenameData['title'];
        }

        return $metadata;
    }

    /**
     * Extract metadata using kiwilan/php-archive PDF parser
     */
    private function extractFromPdfLibrary(Node $file): ?array {
        $tempFile = null;
        try {
            $tempFile = $this->createTemporaryFile($file);
            $archive = Archive::read($tempFile);
            
            // Check if it's a PDF by trying to get the PDF reader
            $pdf = $archive->getPdf();
            if (!$pdf) {
                return null;
            }

            return $this->normalizePdfMetadata([
                'title' => $pdf->getTitle(),
                'author' => $pdf->getAuthor(),
                'subject' => $pdf->getSubject(),
                'creator' => $pdf->getCreator(),
                'creation_date' => $pdf->getCreationDate(),
                'modification_date' => $pdf->getModDate(),
                'pages' => $pdf->getPages(),
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('PDF metadata extraction failed', [
                'file' => $file->getPath(),
                'error' => $e->getMessage()
            ]);
            return null;
        } finally {
            if ($tempFile !== null && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Extract basic metadata from filename
     */
    private function extractFromFilename(Node $file): array {
        $filename = pathinfo($file->getName(), PATHINFO_FILENAME);
        
        // Try to extract author and title from patterns like "Author - Title"
        if (strpos($filename, ' - ') !== false) {
            $parts = explode(' - ', $filename, 2);
            return [
                'title' => trim($parts[1]),
                'author' => trim($parts[0]),
                'subject' => '',
                'creator' => '',
                'creation_date' => null,
                'modification_date' => null,
                'pages' => 0,
                'language' => '',
                'publisher' => '',
            ];
        }

        return [
            'title' => $filename,
            'author' => 'Unknown',
            'subject' => '',
            'creator' => '',
            'creation_date' => null,
            'modification_date' => null,
            'pages' => 0,
            'language' => '',
            'publisher' => '',
        ];
    }

    /**
     * Validate if file is a readable PDF
     */
    private function validatePdfFile(Node $file): bool {
        try {
            $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
            if ($extension !== 'pdf') {
                return false;
            }

            // Check file size (skip files larger than 100MB for performance)
            if ($file->getSize() > 100 * 1024 * 1024) {
                $this->logger->info('PDF file too large for metadata extraction', [
                    'file' => $file->getPath(),
                    'size' => $file->getSize()
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Normalize and clean PDF metadata
     */
    private function normalizePdfMetadata(array $rawMetadata): array {
        return [
            'title' => $this->cleanString($rawMetadata['title'] ?? ''),
            'author' => $this->cleanString($rawMetadata['author'] ?? 'Unknown'),
            'subject' => $this->cleanString($rawMetadata['subject'] ?? ''),
            'creator' => $this->cleanString($rawMetadata['creator'] ?? ''),
            'creation_date' => $this->formatDate($rawMetadata['creation_date'] ?? null),
            'modification_date' => $this->formatDate($rawMetadata['modification_date'] ?? null),
            'pages' => (int) ($rawMetadata['pages'] ?? 0),
            'language' => '',
            'publisher' => '',
        ];
    }

    /**
     * Create temporary file for PDF processing
     */
    private function createTemporaryFile(Node $file): string {
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_metadata_');
        file_put_contents($tempFile, $file->getContent());
        return $tempFile;
    }

    /**
     * Clean and sanitize string metadata
     */
    private function cleanString(?string $value): string {
        if (empty($value)) {
            return '';
        }
        
        // Remove null bytes and control characters
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        
        // Trim whitespace
        $cleaned = trim($cleaned);
        
        // Limit length to prevent database issues
        return substr($cleaned, 0, 500);
    }

    /**
     * Format date from PDF metadata
     */
    private function formatDate($date): ?string {
        if (empty($date)) {
            return null;
        }

        try {
            if ($date instanceof \DateTime) {
                return $date->format('Y-m-d H:i:s');
            }

            // Handle PDF date strings (D:YYYYMMDDHHmmSSOHH'mm')
            if (is_string($date) && strpos($date, 'D:') === 0) {
                $dateString = substr($date, 2, 14); // YYYYMMDDHHmmSS
                $parsed = \DateTime::createFromFormat('YmdHis', $dateString);
                return $parsed ? $parsed->format('Y-m-d H:i:s') : null;
            }

            // Try to parse as regular date string
            $parsed = new \DateTime($date);
            return $parsed->format('Y-m-d H:i:s');

        } catch (\Exception $e) {
            $this->logger->debug('Date parsing failed', ['date' => $date, 'error' => $e->getMessage()]);
            return null;
        }
    }
}