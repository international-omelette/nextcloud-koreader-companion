/**
 * Client-Side Metadata Extractor for eBooks App
 * Extracts real metadata from EPUB, PDF, CBR/CBZ, and MOBI files
 */
(function() {
    'use strict';

    // Make functions available globally
    window.MetadataExtractor = {
        extractMetadataFromFile,
        loadZipLibrary
    };

    /**
     * Extract metadata from file content based on format
     */
    async function extractMetadataFromFile(file) {
        const extension = file.name.toLowerCase().split('.').pop();
        
        try {
            switch (extension) {
                case 'epub':
                    return await extractEpubMetadata(file);
                case 'pdf':
                    return await extractPdfMetadata(file);
                case 'cbr':
                case 'cbz':
                    return await extractCbrMetadata(file);
                case 'mobi':
                    return await extractMobiMetadata(file);
                default:
                    // Fallback to filename parsing
                    return extractMetadataFromFilename(file);
            }
        } catch (error) {
            console.error('Error extracting metadata from file:', error);
            // Fallback to filename parsing
            return extractMetadataFromFilename(file);
        }
    }

    /**
     * Extract EPUB metadata by parsing ZIP contents and OPF file
     */
    async function extractEpubMetadata(file) {
        try {
            // Ensure ZIP.js is loaded
            if (!window.zip) {
                await loadZipLibrary();
            }

            const arrayBuffer = await file.arrayBuffer();
            const zipReader = new zip.ZipReader(new zip.Uint8ArrayReader(new Uint8Array(arrayBuffer)));
            const entries = await zipReader.getEntries();
            
            // Find container.xml to locate OPF file
            const containerEntry = entries.find(entry => entry.filename === 'META-INF/container.xml');
            if (!containerEntry) {
                throw new Error('No container.xml found');
            }
            
            const containerText = await containerEntry.getData(new zip.TextWriter());
            const containerParser = new DOMParser();
            const containerDoc = containerParser.parseFromString(containerText, 'application/xml');
            
            const rootFileElement = containerDoc.querySelector('rootfile');
            if (!rootFileElement) {
                throw new Error('No rootfile found in container.xml');
            }
            
            const opfPath = rootFileElement.getAttribute('full-path');
            if (!opfPath) {
                throw new Error('No OPF path found');
            }
            
            // Find and parse OPF file
            const opfEntry = entries.find(entry => entry.filename === opfPath);
            if (!opfEntry) {
                throw new Error('OPF file not found: ' + opfPath);
            }
            
            const opfText = await opfEntry.getData(new zip.TextWriter());
            const opfParser = new DOMParser();
            const opfDoc = opfParser.parseFromString(opfText, 'application/xml');
            
            await zipReader.close();
            
            // Extract metadata from OPF
            return parseOpfMetadata(opfDoc, file);
            
        } catch (error) {
            console.error('EPUB metadata extraction failed:', error);
            throw error;
        }
    }

    /**
     * Parse OPF document for metadata using proper namespace handling
     */
    function parseOpfMetadata(opfDoc, file) {
        const extension = file.name.toLowerCase().split('.').pop();
        
        const metadata = {
            title: '',
            author: '',
            format: extension,
            language: 'en',
            publisher: '',
            date: '',
            description: '',
            tags: '',
            series: '',
            issue: '',
            volume: ''
        };
        
        // Handle namespaces - try both with and without namespace prefixes
        const getDcElement = (name) => {
            // Try different namespace approaches
            let element = opfDoc.querySelector(`dc\\:${name}, ${name}`);
            if (!element) {
                // Try with namespace URI
                element = opfDoc.getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', name)[0];
            }
            return element;
        };
        
        // Extract title
        const titleElement = getDcElement('title');
        if (titleElement) {
            metadata.title = titleElement.textContent.trim();
        }
        
        // Extract author(s) - look for creator elements
        const creatorElements = Array.from(opfDoc.querySelectorAll('creator, dc\\:creator')).concat(
            Array.from(opfDoc.getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'creator'))
        );
        
        const authors = [];
        creatorElements.forEach(creator => {
            const role = creator.getAttribute('role') || creator.getAttributeNS('http://www.idpf.org/2007/opf', 'role');
            if (!role || role === 'aut') {
                const authorName = creator.textContent.trim();
                if (authorName && !authors.includes(authorName)) {
                    authors.push(authorName);
                }
            }
        });
        if (authors.length > 0) {
            metadata.author = authors.join(', ');
        }
        
        // Extract description
        const descriptionElement = getDcElement('description');
        if (descriptionElement) {
            metadata.description = descriptionElement.textContent.trim();
        }
        
        // Extract language
        const languageElement = getDcElement('language');
        if (languageElement) {
            metadata.language = languageElement.textContent.trim();
        }
        
        // Extract publisher
        const publisherElement = getDcElement('publisher');
        if (publisherElement) {
            metadata.publisher = publisherElement.textContent.trim();
        }
        
        // Extract date (extract year only)
        const dateElement = getDcElement('date');
        if (dateElement) {
            const fullDate = dateElement.textContent.trim();
            const yearMatch = fullDate.match(/(\d{4})/);
            if (yearMatch) {
                metadata.date = yearMatch[1];
            } else {
                metadata.date = fullDate;
            }
        }
        
        // Extract subject/tags
        const subjectElements = Array.from(opfDoc.querySelectorAll('subject, dc\\:subject')).concat(
            Array.from(opfDoc.getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'subject'))
        );
        
        const subjects = [];
        subjectElements.forEach(subject => {
            const subjectText = subject.textContent.trim();
            if (subjectText && !subjects.includes(subjectText)) {
                subjects.push(subjectText);
            }
        });
        if (subjects.length > 0) {
            metadata.tags = subjects.join(', ');
        }
        
        // Fallback to filename if no title found
        if (!metadata.title) {
            metadata.title = file.name.replace(/\.[^/.]+$/, "");
        }
        
        return metadata;
    }

    /**
     * Extract PDF metadata from file headers
     */
    async function extractPdfMetadata(file) {
        try {
            // Read first 8KB of PDF for metadata
            const chunk = await file.slice(0, 8192).arrayBuffer();
            const text = new TextDecoder('latin1').decode(chunk);
            
            const extension = file.name.toLowerCase().split('.').pop();
            const metadata = {
                title: '',
                author: '',
                format: extension,
                language: 'en',
                publisher: '',
                date: '',
                description: '',
                tags: '',
                series: '',
                issue: '',
                volume: ''
            };
            
            // Helper function to decode PDF strings
            const decodePdfString = (str) => {
                // Remove parentheses and decode escape sequences
                return str.replace(/\\[nrtb\\()]/g, match => {
                    switch(match) {
                        case '\\n': return '\n';
                        case '\\r': return '\r';
                        case '\\t': return '\t';
                        case '\\b': return '\b';
                        case '\\\\': return '\\';
                        case '\\(': return '(';
                        case '\\)': return ')';
                        default: return match;
                    }
                });
            };
            
            // Extract PDF metadata fields
            const titleMatch = text.match(/\/Title\s*\(([^)]+)\)/);
            if (titleMatch) {
                metadata.title = decodePdfString(titleMatch[1]).trim();
            }
            
            const authorMatch = text.match(/\/Author\s*\(([^)]+)\)/);
            if (authorMatch) {
                metadata.author = decodePdfString(authorMatch[1]).trim();
            }
            
            const creatorMatch = text.match(/\/Creator\s*\(([^)]+)\)/);
            if (creatorMatch && !metadata.author) {
                metadata.author = decodePdfString(creatorMatch[1]).trim();
            }
            
            const subjectMatch = text.match(/\/Subject\s*\(([^)]+)\)/);
            if (subjectMatch) {
                metadata.description = decodePdfString(subjectMatch[1]).trim();
            }
            
            const creationDateMatch = text.match(/\/CreationDate\s*\(D:(\d{4})/);
            if (creationDateMatch) {
                metadata.date = creationDateMatch[1];
            }
            
            const keywordsMatch = text.match(/\/Keywords\s*\(([^)]+)\)/);
            if (keywordsMatch) {
                metadata.tags = decodePdfString(keywordsMatch[1]).trim();
            }
            
            // Fallback to filename if no title found
            if (!metadata.title) {
                metadata.title = file.name.replace(/\.[^/.]+$/, "");
            }
            
            return metadata;
            
        } catch (error) {
            console.error('PDF metadata extraction failed:', error);
            throw error;
        }
    }

    /**
     * Extract CBR/CBZ metadata from ComicInfo.xml
     */
    async function extractCbrMetadata(file) {
        try {
            // Ensure ZIP.js is loaded
            if (!window.zip) {
                await loadZipLibrary();
            }

            const arrayBuffer = await file.arrayBuffer();
            const zipReader = new zip.ZipReader(new zip.Uint8ArrayReader(new Uint8Array(arrayBuffer)));
            const entries = await zipReader.getEntries();
            
            const extension = file.name.toLowerCase().split('.').pop();
            const metadata = {
                title: '',
                author: '',
                format: extension,
                language: 'en',
                publisher: '',
                date: '',
                description: '',
                tags: '',
                series: '',
                issue: '',
                volume: ''
            };
            
            // Look for ComicInfo.xml
            const comicInfoEntry = entries.find(entry => 
                entry.filename.toLowerCase() === 'comicinfo.xml'
            );
            
            if (comicInfoEntry) {
                const comicInfoText = await comicInfoEntry.getData(new zip.TextWriter());
                const parser = new DOMParser();
                const comicDoc = parser.parseFromString(comicInfoText, 'application/xml');
                
                // Extract comic metadata
                const getElementText = (selector) => {
                    const element = comicDoc.querySelector(selector);
                    return element ? element.textContent.trim() : '';
                };
                
                const title = getElementText('Title');
                const series = getElementText('Series');
                const number = getElementText('Number');
                
                if (title) {
                    metadata.title = title;
                } else if (series && number) {
                    metadata.title = `${series} #${number}`;
                }
                
                metadata.series = series;
                metadata.issue = number;
                metadata.author = getElementText('Writer');
                metadata.description = getElementText('Summary');
                metadata.publisher = getElementText('Publisher');
                metadata.date = getElementText('Year') || getElementText('Month');
                metadata.volume = getElementText('Volume');
                metadata.tags = getElementText('Genre') || getElementText('Tags');
                
                const languageISO = getElementText('LanguageISO');
                if (languageISO) {
                    metadata.language = languageISO;
                }
            }
            
            await zipReader.close();
            
            // Fallback to filename parsing if no metadata found
            if (!metadata.title) {
                const filenameMetadata = extractMetadataFromFilename(file);
                return { ...metadata, ...filenameMetadata };
            }
            
            return metadata;
            
        } catch (error) {
            console.error('CBR metadata extraction failed:', error);
            throw error;
        }
    }

    /**
     * Extract MOBI metadata from file headers
     */
    async function extractMobiMetadata(file) {
        try {
            // Read first 1KB of MOBI file for header info
            const chunk = await file.slice(0, 1024).arrayBuffer();
            const view = new Uint8Array(chunk);
            
            const extension = file.name.toLowerCase().split('.').pop();
            const metadata = {
                title: '',
                author: '',
                format: extension,
                language: 'en',
                publisher: '',
                date: '',
                description: '',
                tags: '',
                series: '',
                issue: '',
                volume: ''
            };
            
            // Check for MOBI magic bytes at offset 60
            const mobiMagic = new TextDecoder().decode(view.slice(60, 68));
            if (mobiMagic === 'BOOKMOBI' || new TextDecoder().decode(view.slice(60, 63)) === 'TPZ') {
                // This is a valid MOBI file
                // For now, we'll use filename parsing as full MOBI parsing is complex
                const filenameMetadata = extractMetadataFromFilename(file);
                return { ...metadata, ...filenameMetadata, format: 'mobi' };
            } else {
                throw new Error('Not a valid MOBI file');
            }
            
        } catch (error) {
            console.error('MOBI metadata extraction failed:', error);
            // Fallback to filename parsing
            const filenameMetadata = extractMetadataFromFilename(file);
            return { ...filenameMetadata, format: 'mobi' };
        }
    }

    /**
     * Fallback: Extract metadata from filename patterns
     */
    function extractMetadataFromFilename(file) {
        const filename = file.name.replace(/\.[^/.]+$/, ""); // Remove extension
        const extension = file.name.toLowerCase().split('.').pop();
        
        let metadata = {
            title: filename,
            author: '',
            format: extension,
            language: 'en',
            publisher: '',
            date: '',
            description: '',
            tags: '',
            series: '',
            issue: '',
            volume: ''
        };

        // Try to extract more info from filename patterns
        // Pattern: "Title - Author (Year)"
        let match = filename.match(/^(.+?)\s*-\s*(.+?)\s*\((\d{4})\)/);
        if (match) {
            metadata.title = match[1].trim();
            metadata.author = match[2].trim();
            metadata.date = match[3];
        }
        // Pattern: "Author - Title (Year)"  
        else if ((match = filename.match(/^(.+?)\s*-\s*(.+?)\s*\((\d{4})\)/))) {
            metadata.author = match[1].trim();
            metadata.title = match[2].trim();
            metadata.date = match[3];
        }
        // Pattern: "Author - Title"
        else if (filename.includes(' - ')) {
            const parts = filename.split(' - ');
            if (parts.length >= 2) {
                metadata.author = parts[0].trim();
                metadata.title = parts.slice(1).join(' - ').trim();
            }
        }
        
        // Additional year extraction patterns - try to find any 4-digit year in parentheses
        if (!metadata.date) {
            const yearMatch = filename.match(/\((\d{4})\)/);
            if (yearMatch) {
                metadata.date = yearMatch[1];
            }
        }
        
        // Last resort: look for any standalone 4-digit number that could be a year
        if (!metadata.date) {
            const standAloneYear = filename.match(/\b(19\d{2}|20\d{2})\b/);
            if (standAloneYear) {
                metadata.date = standAloneYear[1];
            }
        }

        // Comic book patterns for CBR files
        if (extension === 'cbr' || extension === 'cbz') {
            // Pattern: "Series Name #001 (Year)"
            match = filename.match(/^(.+?)\s*#?(\d+).*?\((\d{4})\)/);
            if (match) {
                metadata.series = match[1].trim();
                metadata.issue = match[2];
                metadata.title = `${metadata.series} #${metadata.issue}`;
                metadata.date = match[3];
            }
            // Pattern: "Series Name 001"
            else {
                match = filename.match(/^(.+?)\s+(\d+)/);
                if (match) {
                    metadata.series = match[1].trim();
                    metadata.issue = match[2];
                    metadata.title = `${metadata.series} #${metadata.issue}`;
                }
            }
        }

        return metadata;
    }

    /**
     * Load ZIP.js library dynamically if not already loaded
     */
    function loadZipLibrary() {
        return new Promise((resolve, reject) => {
            if (window.zip) {
                resolve();
                return;
            }
            
            const script = document.createElement('script');
            script.src = 'https://unpkg.com/@zip.js/zip.js/dist/zip-fs.js';
            script.onload = () => {
                // Configure zip.js
                if (window.zip) {
                    zip.configure({
                        useWebWorkers: false // For compatibility
                    });
                }
                resolve();
            };
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    // Load ZIP.js library automatically when this script loads
    loadZipLibrary().catch(error => {
        console.warn('Failed to load ZIP.js library:', error);
        console.warn('EPUB and CBR metadata extraction will use filename parsing fallback');
    });

})();