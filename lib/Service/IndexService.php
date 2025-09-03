<?php
namespace OCA\KoreaderCompanion\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUserSession;
use Kiwilan\Archive\Archive;
use Psr\Log\LoggerInterface;

class IndexService {

    private $rootFolder;
    private $config;
    private $logger;
    private $bookService;
    private $pdfExtractor;

    public function __construct(IRootFolder $rootFolder, IConfig $config, LoggerInterface $logger, BookService $bookService, PdfMetadataExtractor $pdfExtractor) {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->logger = $logger;
        $this->bookService = $bookService;
        $this->pdfExtractor = $pdfExtractor;
    }

    /**
     * Index books for a specific user
     */
    public function indexUserBooks($userId) {
        try {
            $folderName = $this->config->getAppValue('koreader_companion', 'folder', 'eBooks');
            $userFolder = $this->rootFolder->getUserFolder($userId);
            
            try {
                $booksFolder = $userFolder->get($folderName);
            } catch (\Exception $e) {
                // Folder doesn't exist for this user
                return ['total' => 0, 'new' => 0, 'updated' => 0];
            }

            if (!$booksFolder->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                return ['total' => 0, 'new' => 0, 'updated' => 0];
            }

            // Get existing index
            $existingIndex = $this->getExistingIndex($userId);
            $currentBooks = [];
            $newBooks = 0;
            $updatedBooks = 0;
            
            // Scan for books
            $this->scanFolderForIndex($booksFolder, $currentBooks, $existingIndex, $newBooks, $updatedBooks);
            
            // Save the updated index
            $this->saveIndex($userId, $currentBooks);
            
            return [
                'total' => count($currentBooks),
                'new' => $newBooks,
                'updated' => $updatedBooks
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to index books for user ' . $userId . ': ' . $e->getMessage());
            return ['total' => 0, 'new' => 0, 'updated' => 0];
        }
    }

    private function scanFolderForIndex(Node $folder, &$currentBooks, $existingIndex, &$newBooks, &$updatedBooks) {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                $this->scanFolderForIndex($node, $currentBooks, $existingIndex, $newBooks, $updatedBooks);
            } else {
                $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
                if (in_array($extension, ['epub', 'pdf', 'cbr'])) {
                    $fileId = $node->getId();
                    $lastModified = $node->getMTime();
                    
                    // Check if this is a new book or if it's been modified
                    if (!isset($existingIndex[$fileId])) {
                        // New book
                        $metadata = $this->extractMetadata($node);
                        $currentBooks[$fileId] = $metadata;
                        $newBooks++;
                        $this->logger->info('New book found: ' . $node->getName());
                    } elseif ($existingIndex[$fileId]['modified_time'] !== $lastModified) {
                        // Book has been modified
                        $metadata = $this->extractMetadata($node);
                        $currentBooks[$fileId] = $metadata;
                        $updatedBooks++;
                        $this->logger->info('Updated book found: ' . $node->getName());
                    } else {
                        // Book hasn't changed
                        $currentBooks[$fileId] = $existingIndex[$fileId];
                    }
                }
            }
        }
    }

    private function extractMetadata(Node $file) {
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        
        $metadata = [
            'id' => $file->getId(),
            'name' => $file->getName(),
            'path' => $file->getPath(),
            'size' => $file->getSize(),
            'modified_time' => $file->getMTime(),
            'format' => $extension,
            'title' => pathinfo($file->getName(), PATHINFO_FILENAME),
            'author' => 'Unknown',
            'description' => '',
            'language' => '',
            'publisher' => '',
            'subject' => '',
            'publication_date' => '',
            'identifier' => '',
            'cover' => null,
            'indexed_at' => time()
        ];

        if ($extension === 'epub') {
            $this->extractEpubMetadata($file, $metadata);
        } elseif ($extension === 'pdf') {
            $this->extractPdfMetadata($file, $metadata);
        } elseif ($extension === 'cbr') {
            // Only process CBR if Archive class is available
            if (class_exists('Kiwilan\Archive\Archive')) {
                $this->extractCbrMetadata($file, $metadata);
            } else {
                // Fallback: basic filename metadata for CBR
                $filename = pathinfo($file->getName(), PATHINFO_FILENAME);
                $metadata['title'] = $filename;
                $metadata['format'] = 'cbr';
            }
        }

        return $metadata;
    }

    private function extractEpubMetadata(Node $file, &$metadata) {
        try {
            // Read the EPUB file content
            $content = $file->getContent();
            
            // Create temporary file to work with ZipArchive
            $tempFile = tempnam(sys_get_temp_dir(), 'epub_meta_');
            file_put_contents($tempFile, $content);
            
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === TRUE) {
                $epubMetadata = $this->parseEpubOPF($zip);
                $zip->close();
                
                // Merge extracted metadata
                if ($epubMetadata) {
                    foreach (['title', 'author', 'description', 'language', 'publisher', 'subject', 'publication_date', 'identifier'] as $field) {
                        if (!empty($epubMetadata[$field])) {
                            $metadata[$field] = $epubMetadata[$field];
                        }
                    }
                }
            }
            
            unlink($tempFile);
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract EPUB metadata for ' . $file->getName() . ': ' . $e->getMessage());
            
            // Fall back to filename parsing
            $filename = pathinfo($file->getName(), PATHINFO_FILENAME);
            if (strpos($filename, ' - ') !== false) {
                $parts = explode(' - ', $filename, 2);
                $metadata['author'] = trim($parts[0]);
                $metadata['title'] = trim($parts[1]);
            }
        }
    }

    private function parseEpubOPF($zip) {
        // This is the same implementation as in BookService
        try {
            // Find the OPF file location from container.xml
            $containerXml = $zip->getFromName('META-INF/container.xml');
            if (!$containerXml) {
                return null;
            }
            
            $container = simplexml_load_string($containerXml);
            if (!$container) {
                return null;
            }
            
            $opfPath = (string)$container->rootfiles->rootfile['full-path'];
            if (!$opfPath) {
                return null;
            }
            
            // Parse the OPF file
            $opfContent = $zip->getFromName($opfPath);
            if (!$opfContent) {
                return null;
            }
            
            $opf = simplexml_load_string($opfContent);
            if (!$opf) {
                return null;
            }
            
            // Register namespaces and extract metadata (same as BookService)
            $opf->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
            $opf->registerXPathNamespace('opf', 'http://www.idpf.org/2007/opf');
            
            $metadata = [];
            
            // Extract title
            $titles = $opf->xpath('//dc:title');
            if (!empty($titles)) {
                $metadata['title'] = (string)$titles[0];
            }
            
            // Extract author(s)
            $authors = $opf->xpath('//dc:creator[@opf:role="aut"] | //dc:creator[not(@opf:role)] | //dc:creator');
            if (!empty($authors)) {
                $authorList = [];
                foreach ($authors as $author) {
                    $authorList[] = (string)$author;
                }
                $metadata['author'] = implode(', ', $authorList);
            }
            
            // Extract other metadata fields
            $fields = [
                'description' => '//dc:description',
                'language' => '//dc:language', 
                'publisher' => '//dc:publisher',
                'publication_date' => '//dc:date'
            ];
            
            foreach ($fields as $field => $xpath) {
                $elements = $opf->xpath($xpath);
                if (!empty($elements)) {
                    $metadata[$field] = (string)$elements[0];
                }
            }
            
            // Extract subjects
            $subjects = $opf->xpath('//dc:subject');
            if (!empty($subjects)) {
                $subjectList = [];
                foreach ($subjects as $subject) {
                    $subjectList[] = (string)$subject;
                }
                $metadata['subject'] = implode(', ', $subjectList);
            }
            
            return $metadata;
            
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractPdfMetadata(Node $file, &$metadata) {
        $pdfMetadata = $this->pdfExtractor->extractMetadata($file);
        
        // Merge PDF metadata into existing metadata array
        foreach ($pdfMetadata as $key => $value) {
            if (!empty($value) || $key === 'title') {
                $metadata[$key] = $value;
            }
        }
    }

    private function extractCbrMetadata(Node $file, &$metadata) {
        try {
            $filename = pathinfo($file->getName(), PATHINFO_FILENAME);
            
            // Set basic metadata from filename
            $metadata['title'] = $filename;
            $metadata['format'] = 'cbr';
            
            // Try to parse comic book information from filename
            // Common patterns: "Series Name #001 (Year)", "Series Name 001", etc.
            if (preg_match('/(.*?)\\s*#?(\\d+).*?\\((\\d{4})\\)/', $filename, $matches)) {
                $metadata['title'] = trim($matches[1]) . ' #' . $matches[2];
                $metadata['series'] = trim($matches[1]);
                $metadata['issue'] = $matches[2];
                $metadata['publication_date'] = $this->parsePublicationDate($matches[3]);
            } elseif (preg_match('/(.*?)\\s*(\\d+)/', $filename, $matches)) {
                $metadata['title'] = trim($matches[1]) . ' #' . $matches[2];
                $metadata['series'] = trim($matches[1]);
                $metadata['issue'] = $matches[2];
            }
            
            // Extract ComicInfo.xml metadata if present
            $this->extractComicInfoMetadata($file, $metadata);
            
        } catch (\Exception $e) {
            // If extraction fails, keep defaults
            error_log('CBR metadata extraction failed: ' . $e->getMessage());
        }
    }

    private function extractComicInfoMetadata(Node $file, &$metadata) {
        try {
            $content = $file->getContent();
            $tempFile = tempnam(sys_get_temp_dir(), 'cbr_info_');
            file_put_contents($tempFile, $content);
            
            $archive = Archive::make($tempFile);
            if (!$archive) {
                unlink($tempFile);
                return;
            }
            
            // Look for ComicInfo.xml in the archive
            $files = $archive->getFiles();
            $comicInfoXml = null;
            
            foreach ($files as $archiveFile) {
                if (strtolower($archiveFile->getName()) === 'comicinfo.xml') {
                    $comicInfoXml = $archiveFile->getContent();
                    break;
                }
            }
            
            unlink($tempFile);
            
            if ($comicInfoXml) {
                $this->parseComicInfoXml($comicInfoXml, $metadata);
            }
            
        } catch (\Exception $e) {
            error_log('ComicInfo.xml extraction failed: ' . $e->getMessage());
        }
    }
    
    private function parseComicInfoXml($xmlContent, &$metadata) {
        try {
            $xml = simplexml_load_string($xmlContent);
            if (!$xml) {
                return;
            }
            
            // Extract comic-specific metadata
            if (!empty($xml->Title)) {
                $metadata['title'] = (string)$xml->Title;
            }
            
            if (!empty($xml->Series)) {
                $metadata['series'] = (string)$xml->Series;
                // Combine series and issue number for title if available
                if (!empty($xml->Number)) {
                    $metadata['title'] = $metadata['series'] . ' #' . (string)$xml->Number;
                }
            }
            
            if (!empty($xml->Number)) {
                $metadata['issue'] = (string)$xml->Number;
            }
            
            if (!empty($xml->Writer)) {
                $metadata['author'] = (string)$xml->Writer;
            }
            
            if (!empty($xml->Summary)) {
                $metadata['description'] = (string)$xml->Summary;
            }
            
            if (!empty($xml->Publisher)) {
                $metadata['publisher'] = (string)$xml->Publisher;
            }
            
            if (!empty($xml->Year) && !empty($xml->Month)) {
                $metadata['publication_date'] = (string)$xml->Year . '-' . sprintf('%02d', (int)$xml->Month) . '-01';
            } elseif (!empty($xml->Year)) {
                $metadata['publication_date'] = (string)$xml->Year . '-01-01';
            }
            
            if (!empty($xml->Genre)) {
                $metadata['subject'] = (string)$xml->Genre;
            }
            
            if (!empty($xml->LanguageISO)) {
                $metadata['language'] = (string)$xml->LanguageISO;
            }
            
            // Additional comic-specific fields
            if (!empty($xml->Volume)) {
                $metadata['volume'] = (string)$xml->Volume;
            }
            
            if (!empty($xml->Web)) {
                $metadata['web'] = (string)$xml->Web;
            }
            
        } catch (\Exception $e) {
            error_log('ComicInfo.xml parsing failed: ' . $e->getMessage());
        }
    }

    private function getExistingIndex($userId) {
        // For now, return empty array
        return [];
    }

    private function saveIndex($userId, $books) {
        // For now, just log the operation
        $this->logger->info("Saved index for user $userId with " . count($books) . " books");
    }

    /**
     * Parse various date formats into YYYY-MM-DD format for publication_date field
     */
    private function parsePublicationDate(?string $dateValue): ?string {
        if (empty($dateValue)) {
            return null;
        }

        $dateValue = trim($dateValue);

        // Handle 4-digit year format (most common case)
        if (preg_match('/^\d{4}$/', $dateValue)) {
            return $dateValue . '-01-01'; // Default to January 1st
        }

        // Handle YYYY-MM format
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $dateValue, $matches)) {
            $year = $matches[1];
            $month = sprintf('%02d', intval($matches[2]));
            return "$year-$month-01"; // Default to 1st of month
        }

        // Handle YYYY-MM-DD format (already correct)
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $dateValue, $matches)) {
            $year = $matches[1];
            $month = sprintf('%02d', intval($matches[2]));
            $day = sprintf('%02d', intval($matches[3]));
            
            // Validate the date
            if (checkdate(intval($month), intval($day), intval($year))) {
                return "$year-$month-$day";
            }
        }

        // Extract year from any string containing a 4-digit year
        if (preg_match('/(\d{4})/', $dateValue, $matches)) {
            $year = $matches[1];
            // Validate year range
            if (intval($year) >= 1000 && intval($year) <= 2099) {
                return "$year-01-01"; // Default to January 1st
            }
        }

        // Could not parse the date
        return null;
    }
}