# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.21] - 2025-09-04

### Removed
- Removed redundant IndexService to simplify codebase

## [1.0.20] - 2025-09-03

### Added
- Enhanced PDF metadata extraction (author, title, dates, page count)

### Improved
- PDF files now show rich metadata instead of just filenames
- Better handling of large and corrupted PDF files

### Fixed
- Internal server errors when processing PDF files
- PDF files not appearing in book library

## [1.0.17] - 2025-09-03

### Added
- OPDS library functionality for ebooks
- KOReader sync support
- Authenticated OPDS feeds
- Reading progress synchronization across devices
- Support for EPUB and PDF formats
- Admin settings panel
- Background indexing of ebook libraries

### Features
- Transform Nextcloud folders into OPDS-compatible ebook libraries
- KOReader integration with sync capabilities
- Compatible with any OPDS-compatible reader
- Secure authentication using Nextcloud credentials