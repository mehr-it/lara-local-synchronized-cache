# Lara local synchronized cache
High-performance local cache which synchronizes automatically
on distributed systems.

## Introduction
Using PHP files for caching data is one of the fastest caching
methods for PHP. Especially opcache makes this method
very fast. However, this caching method cannot be used for
distributed systems without any kind of synchronization.

This package implements a fast and synchronized local cache.
Locally cached data is synchronized periodically and every 
time when processing a queued job.

The local cache state is synchronized using a common cache
backend such as redis or whatever network cache you prefer.

## Installation

Install via composer:

    composer require mehr-it/lara-local-synchronized-cache

## Configuration

    'local-sync' => [
        'driver' => 'local-synchronized',

        // path for cache data
        'path'   => storage_path('framework/cache/local-sync'),

        // true if to buffer data in memory
        'buffer' => true,

        // name of the cache used for synchronizing the global state
        'shared_store' => null,

        // time to live for locally cached data (in seconds) before the global state is synchronized again 
        'local_ttl' => 60,

        // prefix for keys in shared store
        'shared_store_pfx' => 'loc-sync-cache_',

        // set file permission
        'file_permission' => 0644,

        // set directory permission
        'directory_permission' => 0755,
    ],
