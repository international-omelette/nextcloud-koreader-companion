<?php

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#getKoreaderPassword', 'url' => '/settings/koreader-password', 'verb' => 'GET'],
        ['name' => 'page#setKoreaderPassword', 'url' => '/settings/koreader-password', 'verb' => 'PUT'],
        ['name' => 'page#uploadBook', 'url' => '/upload', 'verb' => 'POST'],
        ['name' => 'page#updateMetadata', 'url' => '/books/{id}/metadata', 'verb' => 'PUT'],
        ['name' => 'page#deleteBook', 'url' => '/books/{id}', 'verb' => 'DELETE'],
        ['name' => 'settings#setFolder', 'url' => '/settings/folder', 'verb' => 'PUT'],
        ['name' => 'settings#setRestrictUploads', 'url' => '/settings/restrict_uploads', 'verb' => 'PUT'],
        ['name' => 'settings#setAutoCleanup', 'url' => '/settings/auto_cleanup', 'verb' => 'PUT'],
        ['name' => 'settings#setAutoRename', 'url' => '/settings/auto_rename', 'verb' => 'PUT'],
        
        // OPDS endpoints
        ['name' => 'opds#index', 'url' => '/opds', 'verb' => 'GET'],
        ['name' => 'opds#opensearch', 'url' => '/opds/opensearch.xml', 'verb' => 'GET'],
        ['name' => 'opds#search', 'url' => '/opds/search', 'verb' => 'GET'],
        ['name' => 'opds#download', 'url' => '/opds/books/{id}/download/{format}', 'verb' => 'GET'],
        ['name' => 'opds#thumbnail', 'url' => '/opds/books/{id}/thumb', 'verb' => 'GET'],
        
        // OPDS faceted browsing endpoints
        ['name' => 'opds#authors', 'url' => '/opds/authors', 'verb' => 'GET'],
        ['name' => 'opds#authorBooks', 'url' => '/opds/authors/{author}', 'verb' => 'GET'],
        ['name' => 'opds#series', 'url' => '/opds/series', 'verb' => 'GET'],
        ['name' => 'opds#seriesBooks', 'url' => '/opds/series/{seriesName}', 'verb' => 'GET'],
        ['name' => 'opds#genres', 'url' => '/opds/genres', 'verb' => 'GET'],
        ['name' => 'opds#genreBooks', 'url' => '/opds/genres/{genre}', 'verb' => 'GET'],
        ['name' => 'opds#formats', 'url' => '/opds/formats', 'verb' => 'GET'],
        ['name' => 'opds#formatBooks', 'url' => '/opds/formats/{format}', 'verb' => 'GET'],
        ['name' => 'opds#languages', 'url' => '/opds/languages', 'verb' => 'GET'],
        ['name' => 'opds#languageBooks', 'url' => '/opds/languages/{language}', 'verb' => 'GET'],
        
        // KOReader sync API endpoints (official spec)
        ['name' => 'koreader#authUser', 'url' => '/sync/users/auth', 'verb' => 'GET'],
        ['name' => 'koreader#updateProgress', 'url' => '/sync/syncs/progress', 'verb' => 'PUT'],
        ['name' => 'koreader#getProgress', 'url' => '/sync/syncs/progress/{document}', 'verb' => 'GET'],
        ['name' => 'koreader#healthcheck', 'url' => '/sync/healthcheck', 'verb' => 'GET'],
    ]
];
