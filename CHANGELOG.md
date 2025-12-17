# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.4] - 2025-12-17

### Added

- Add config-only CSS variable overrides for tour theming
- Adds new migration file

### Changed

- Update ToursService to retrieve all tours across sites by modifying siteId query to use '*' and ensuring unique results.
- Enhance orphaned tour detection in migration by adding detailed logging for total tours and tours with element entries, improving visibility into the migration process.
- Refactor orphaned tour handling in migration: replace element creation with a comprehensive fixOrphanedTour method that restores related data and ensures proper recreation of tours with new IDs.

### Removed

- Remove ExportService and related export functionality, update ImportConfig and ImportController to reflect changes, and adjust Boarding class to remove export references.

## [1.3.0] - 2025-12-10

### Added

- Adds new migration file
- Adds migration file for orphaned tours

### Changed

- Update ToursService to retrieve all tours across sites by modifying siteId query to use '*' and ensuring unique results.
- Enhance orphaned tour detection in migration by adding detailed logging for total tours and tours with element entries, improving visibility into the migration process.
- Refactor orphaned tour handling in migration: replace element creation with a comprehensive fixOrphanedTour method that restores related data and ensures proper recreation of tours with new IDs.

### Removed

- Remove ExportService and related export functionality, update ImportConfig and ImportController to reflect changes, and adjust Boarding class to remove export references.

## [Unreleased]

### Added

- Adds new migration file
- Adds migration file for orphaned tours

### Changed

- Update ToursService to retrieve all tours across sites by modifying siteId query to use '*' and ensuring unique results.
- Enhance orphaned tour detection in migration by adding detailed logging for total tours and tours with element entries, improving visibility into the migration process.
- Refactor orphaned tour handling in migration: replace element creation with a comprehensive fixOrphanedTour method that restores related data and ensures proper recreation of tours with new IDs.

### Removed

- Remove ExportService and related export functionality, update ImportConfig and ImportController to reflect changes, and adjust Boarding class to remove export references.

## [1.1.2] - 2025-10-28

### Changed

- Change version in composer.json

### Removed

- Remove error logs

## [1.1.1] - 2025-10-28

### Changed

- Enhance tour propagation logic by refining translation handling in the TranslationProcessor and implementing a new method for propagating data to site group sites on first save, ensuring proper content management across different site configurations.
- Refactor multi-site tour management by introducing flexible propagation methods, enhancing translation handling in the TranslationProcessor, and updating templates for improved user experience.
- Enhance tour management by adding autoplay and propagation method features, updating import/export configurations to support JSON, CSV, and XML formats, and refactoring related services and templates for improved functionality and clarity.
- Refactor site resolution logic in Boarding class to improve clarity and maintainability, ensuring proper handling of CP requests.

## [1.0.12] - 2025-10-14

### Changed

- Update tour creation link in index.twig to remove query parameters for cleaner URL

## [1.0.11] - 2025-10-13

## [1.0.10] - 2025-10-13

### Changed

- Refactor tourId column type in boarding_tour_completions table from string to integer, update related foreign key constraints, and adjust TourRepository queries for consistency.

## [1.0.9] - 2025-10-13

### Changed

- Improve attachTo logic in core.js to ensure elements exist before assignment

### Fixed

- Fix editions in documentation

## [1.0.8] - 2025-10-13

### Removed

- Removes unused function

## [1.0.7] - 2025-10-13

### Changed

- Enhance SQL queries in TourRepository for PostgreSQL compatibility by conditionally using STRING_AGG and adjusting join conditions for type casting.
- Update SQL query in TourRepository to cast tourId as CHAR for consistency

## [1.0.6] - 2025-10-13

## [1.0.5] - 2025-10-13

### Fixed

- Fix bug where BoardingAsset was not loaded

## [1.0.4] - 2025-10-13

### Changed

- Change from standard to pro edition

## [1.0.3] - 2025-10-08

### Changed

- Update version to 1.0.3 in composer.json

### Fixed

- Fix asset registration logic to only apply for CP requests

## [1.0.2] - 2025-10-08

### Changed

- Update version to 1.0.2 and refactor site resolution logic in ToursController and related services
- Update version to 1.0.1 in composer.json

## [1.0.1] - 2025-10-08

### Changed

- Refactor Boarding plugin settings handling and improve site resolution logic

## [1.0.0] - 2025-10-07

### Changed

- Refactor tour steps rendering and update step insertion logic in tour-edit.js
- Change edition from pro to standard, code cleanup


