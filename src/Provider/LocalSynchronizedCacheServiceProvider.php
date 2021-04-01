<?php

	namespace MehrIt\LaraLocalSynchronizedCache\Provider;


	use Cache;
	use Event;
	use Illuminate\Queue\Events\JobProcessing;
	use Illuminate\Support\ServiceProvider;
	use InvalidArgumentException;
	use MehrIt\LaraLocalSynchronizedCache\Cache\LocalSynchronizedStore;
	use MehrIt\LaraLocalSynchronizedCache\Cache\LocalSyncStore;

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
				$localTTL            = $config['local_ttl'] ?? 60;
				$sharedPrefix        = $config['shared_store_pfx'] ?? 'loc-sync-cache_';
				$filePermission      = $config['file_permission'] ?? 0644;
				$directoryPermission = $config['directory_permission'] ?? 0755;

				$lsStore = new LocalSyncStore($config['path'], $sharedStore, $app['files'], $localTTL, $sharedPrefix, $filePermission, $directoryPermission);

				if ($config['listen_events'] ?? true) {
					Event::listen(JobProcessing::class, function () use ($lsStore) {
						$lsStore->refreshLocal(true);
					});
					Event::listen('cache:clearing', function () use ($lsStore) {
						$lsStore->flushLocal();
					});
					
				}

				return Cache::repository($lsStore);
			});
		}

	}