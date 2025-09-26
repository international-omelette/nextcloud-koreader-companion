<?php
namespace OCA\KoreaderCompanion\Service;

/**
 * Service for standardized filename generation
 */
class FilenameService {

    /**
     * Generate standardized filename based on metadata: "Author - Title (Year).ext"
     */
    public function generateStandardFilename($metadata, $originalFilename) {
        // Get file extension
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        // Extract components for filename
        $author = trim($metadata['author'] ?? '');
        $title = trim($metadata['title'] ?? '');
        $publicationDate = trim($metadata['publication_date'] ?? '');

        // Extract year from publication_date (YYYY-MM-DD format)
        $year = '';
        if (!empty($publicationDate)) {
            // Extract year from YYYY-MM-DD format
            if (preg_match('/^(\d{4})-/', $publicationDate, $matches)) {
                $year = $matches[1];
            }
        }

        // Build filename in "Author - Title (Year)" format
        $filename = '';

        if (!empty($author)) {
            $filename .= $this->sanitizeFilename($author);
            if (!empty($title)) {
                $filename .= ' - ' . $this->sanitizeFilename($title);
            }
            if (!empty($year)) {
                $filename .= ' ' . "($year)";
            }
        } elseif (!empty($title)) {
            $filename = $this->sanitizeFilename($title);
            if (!empty($year)) {
                $filename .= ' ' . "($year)";
            }
        } else {
            // If we have no author or title, use original filename without extension
            $filename = pathinfo($originalFilename, PATHINFO_FILENAME);
        }

        return $filename . '.' . $extension;
    }

    /**
     * Sanitize filename components by removing/replacing problematic characters
     */
    public function sanitizeFilename($name) {
        // Remove or replace problematic characters
        $sanitized = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $name);

        // Replace multiple spaces with single space
        $sanitized = preg_replace('/\s+/', ' ', $sanitized);

        // Trim whitespace and limit length
        $sanitized = trim($sanitized);
        if (strlen($sanitized) > 100) {
            $sanitized = substr($sanitized, 0, 100);
            $sanitized = trim($sanitized);
        }

        return $sanitized;
    }

    /**
     * Resolve filename conflicts by adding a counter
     */
    public function resolveFilenameConflict($parentFolder, $desiredName) {
        $counter = 1;
        $finalName = $desiredName;

        while ($parentFolder->nodeExists($finalName)) {
            $pathInfo = pathinfo($desiredName);
            $finalName = $pathInfo['filename'] . "_$counter." . $pathInfo['extension'];
            $counter++;
        }

        return $finalName;
    }
}