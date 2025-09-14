# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.8] - 2025-09-14

### Fixed
- Remove hardcoded authentication from KOReader sync API and implement user-specific passwords
- Add automatic migration for existing password storage format

## [1.1.7] - 2025-09-14

### Fixed
- Replace bcrypt verification with MD5 hash verification for KOReader sync endpoint
- Remove extra slashes in PageController response paths
- Update app signature for production builds

## [1.1.6] - 2025-09-13

### Fixed
- Include img directory in production builds for proper icon display

## [1.1.5] - 2025-09-13

### Fixed
- Resolve production deployment issues
- Improve build reliability

## [1.1.4] - 2025-09-13

### Fixed
- Correct app name in Makefile to match app ID

## [1.1.3] - 2025-09-13

### Fixed
- Replace GitHub release action for reliable download URLs
- Improve release workflow robustness

## [1.1.2] - 2025-09-13

### Fixed
- Correct release workflow output variables and pin actions
- Correct APP_NAME to match app ID in info.xml

## [1.1.1] - 2025-09-12

### Added
- Automated app store upload with signing

### Changed
- Simplified release workflow and improved build compatibility

## [1.1.0] - 2025-09-12

### Added
- Automated release workflow

## [1.0.28] - 2025-09-12

### Changed
- Simplified release workflow to manual release-only process
- Build system now handles file paths with spaces correctly

### Changed
- More predictable and lightweight release management
- Better cross-platform build compatibility

## [1.0.27] - 2025-09-12

### Added
- Automated release pipeline with semantic versioning
- GitHub Actions workflow for streamlined deployments
- Automated tarball building and GitHub release creation

### Changed  
- Version management now automated based on commit message conventions
- Simplified deployment process for maintainers

## [1.0.26] - 2025-09-11

### Added
- HTTP Basic Auth authentication for OPDS endpoints
- Secure access control for external ebook reader apps

### Changed
- OPDS feeds now require Nextcloud username and password for access

## [1.0.25] - 2025-09-09

### Changed
- Code readability and maintainability
- Removed unnecessary code comments for cleaner codebase

## [1.0.24] - 2025-09-09

### Added
- Infinite scrolling replaces "Load More" button
- Server-side search across entire book database
- Real-time search results as you type

### Changed
- Automatic loading of books when scrolling to bottom
- Larger page sizes (50 books) for better performance

### Changed
- Mobile user experience with touch-friendly infinite scroll
- Search now finds books across entire collection, not just visible ones

## [1.0.23] - 2025-09-09

### Added
- Enhanced search interface with icon and visual improvements
- Better responsive design for mobile and tablet devices

### Changed
- Larger search icon for better visibility
- Unified search container design with divider
- Improved spacing and layout on smaller screens

## [1.0.22] - 2025-09-08

### Added
- CSS custom properties for consistent theming
- Universal transition system for smoother interactions
- Enhanced upload modal state management

### Changed
- Consistent visual timing across all interface elements
- Better maintainability with centralized design values
- Smoother animations and hover effects

## [1.0.21] - 2025-09-08

### Added
- Complete UI redesign with side-panel navigation
- Separate sections for Books, Sync, and OPDS management
- Modal-based file upload interface
- Pagination support for large book libraries
- Responsive collapsible navigation for mobile

### Changed
- Side-panel layout replaces previous design
- Edge-to-edge table display on mobile devices
- Updated icons following Nextcloud design standards

### Changed
- Better organization and navigation between features
- Enhanced mobile and tablet user experience
- Sticky table headers with scrollable content

## [1.0.20] - 2025-09-04

### Removed
- Redundant background indexing service to simplify architecture

## [1.0.20] - 2025-09-03

### Added
- Enhanced PDF metadata extraction (author, title, dates, page count)

### Changed
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

### Added
- Transform Nextcloud folders into OPDS-compatible ebook libraries
- KOReader integration with sync capabilities
- Compatible with any OPDS-compatible reader
- Secure authentication using Nextcloud credentials