<?php

	namespace MehrIt\LaraLocalSynchronizedCache\Provider;


	use Cache;
	use Event;
	use Illuminate\Queue\Events\JobProcessing;
	use Illuminate\Support\ServiceProvider;
	use InvalidArgumentException;
	use MehrIt\LaraLocalSynchronizedCache\Cache\LocalSynchronizedStore;

	class LocalSynchronizedCacheServiceProvider extends ServiceProvider
	{

		public function boot() {
			// register driver

			Cache::extend('local-synchronized', function ($app, $config) {

				// path is mandatory
				if (!$config['path'])
					throw new InvalidArgumentException('Path must be configured for local-synchronized driver');

				// get store for shared state
				$sharedStore = Cache::store($config['shared_store'] ?? null);

				// other settings with default
				$buffered       = $config['buffered'] ?? true;
				$localTTL       = $config['local_ttl'] ?? 60;
				$sharedPrefix   = $config['shared_store_pfx'] ?? 'loc-sync-cache_';
				$filePermission = $config['file_permission'] ?? 0644;

				$lsStore = new LocalSynchronizedStore($config['path'], $sharedStore, $app['files'], $buffered, $localTTL, $sharedPrefix, $filePermission);

				if ($config['listen_events'] ?? true) {
					Event::listen(JobProcessing::class, function () use ($lsStore) {
						$lsStore->refreshLocal(true);
					});
					Event::listen('cache:clearing', function () use ($lsStore) {
						$lsStore->flushLocal();
					});

					// TODO: any other events to fetch
				}

				return Cache::repository($lsStore);
			});
		}

	}