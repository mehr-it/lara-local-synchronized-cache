<?php /** @noinspection PhpMissingParamTypeInspection */

	/** @noinspection PhpMissingReturnTypeInspection */


	namespace MehrIt\LaraLocalSynchronizedCache\Cache;


	use Illuminate\Cache\Repository;
	use Illuminate\Cache\RetrievesMultipleKeys;
	use Illuminate\Contracts\Cache\Store;
	use Illuminate\Filesystem\Filesystem;
	use MehrIt\LaraLocalSynchronizedCache\Cache\Concerns\SharedState;
	use MehrIt\PhpCache\PhpCache;
	use Throwable;

	class LocalSyncStore implements Store
	{
		use RetrievesMultipleKeys;
		use SharedState;

		/**
		 * @var PhpCache
		 */
		protected $localCache;


		/**
		 * Creates a new instance
		 * @param string $directory The directory where to store the cache entries
		 * @param Repository $sharedStore The shared store for synchronization
		 * @param Filesystem $files Filesystem instance
		 * @param int $localTtl The TTL for locally cached items without checking for changes
		 * @param string $sharedStatePrefix The shared store key prefix to use
		 * @param int $filePermission File permission
		 * @param int $directoryPermission Directory permission
		 */
		public function __construct($directory, Repository $sharedStore, Filesystem $files, $localTtl = 60, $sharedStatePrefix = 'shared_state__', $filePermission = 0644, $directoryPermission = 0755) {

			$this->sharedStore       = $sharedStore;
			$this->localTtl          = $localTtl;
			$this->sharedStatePrefix = $sharedStatePrefix;

			$directory = rtrim($directory, '/');

			// create base directory if not exists
			if (!$files->exists($directory)) {
				$files->makeDirectory($directory, 0755, true);

				// ensure correct permission
				$files->chmod($directory, $directoryPermission);
			}

			// create PHP cache instances for local cache and state
			$this->localCache      = new PhpCache("{$directory}/local", $filePermission, $directoryPermission, false);
			$this->localStateCache = new PhpCache("{$directory}/state", $filePermission, $directoryPermission, false);
		}


		/**
		 * Retrieve an item from the cache by key.
		 *
		 * @param string|array $key
		 * @return mixed
		 */
		public function get($key) {

			// refresh local cache if TTL expired
			$this->refreshLocal(false);

			return $this->localCache->get($key);
		}

		/**
		 * Store an item in the cache for a given number of seconds.
		 *
		 * @param string $key
		 * @param mixed $value
		 * @param int $seconds
		 * @return bool
		 */
		public function put($key, $value, $seconds) {
			
			$this->refreshLocal(false);

			$this->withStateLocked(function () use ($key, $value, $seconds) {

				// publish state
				$this->publishState([[$key, $this->valueHash($value)]]);
				$this->persistLocalState();

				// passing 0 to PSR-16 cache would expire immediately, but 0 should mean "forever"
				if (!$seconds)
					$seconds = null;
				
				$this->localCache->set($key, $value, $seconds);
			});

			return true;
		}

		/**
		 * Increment the value of an item in the cache.
		 *
		 * @param string $key
		 * @param mixed $value
		 * @return int|bool
		 */
		public function increment($key, $value = 1) {
			// this function is not supported, we forget item as if it was expired or deleted and return value

			$this->forget($key);

			return $value;
		}

		/**
		 * Decrement the value of an item in the cache.
		 *
		 * @param string $key
		 * @param mixed $value
		 * @return int|bool
		 */
		public function decrement($key, $value = 1) {
			// this function is not supported, we forget item as if it was expired or deleted and return value

			$this->forget($key);

			return $value;
		}

		/**
		 * Store an item in the cache indefinitely.
		 *
		 * @param string $key
		 * @param mixed $value
		 * @return bool
		 */
		public function forever($key, $value) {
			return $this->put($key, $value, 0);
		}

		/**
		 * Remove an item from the cache.
		 *
		 * @param string $key
		 * @return bool
		 */
		public function forget($key) {

			$this->refreshLocal(false);

			$this->withStateLocked(function () use ($key) {

				// publish state
				$this->publishState([[$key, $this->valueHash(null)]]);
				$this->persistLocalState();

				// delete key in local cache
				$this->localCache->delete($key);
			});

			return true;
		}

		/**
		 * Remove all items from the cache.
		 *
		 * @return bool
		 */
		public function flush() {

			$this->withStateLocked(function () {

				$this->resetStateGlobally();

				$this->invalidateAllLocal();

				$this->persistLocalState();
			});

			return true;
		}

		/**
		 * Get the cache key prefix.
		 *
		 * @return string
		 */
		public function getPrefix() {
			// no prefixes supported
			return '';
		}

		/**
		 * Refreshes the local cache, so any global changes are readable
		 * @param bool $force If set to false, local cache is only synced if local cache's TTL is expired
		 */
		public function refreshLocal($force = true) {

			if ($force || !$this->lastLocalSync || time() > $this->lastLocalSync + $this->localTtl) {

				$this->withStateLocked(function () {
					$this->syncLocalState();

					$this->persistLocalState();
				});
			}
		}

		/**
		 * Flush all local cache entries
		 */
		public function flushLocal() {
			$this->refreshLocal(false);
			
			$this->invalidateAllLocal();

		}

		public function invalidateAllLocal(): void {
			
			$this->localCache->clear();
		}

		/**
		 * Gets the hash for the given value
		 * @param mixed $value Value
		 * @return string The hash
		 */
		protected function valueHash($value): string {

			if ($value === null)
				return '-1';

			return sha1(serialize($value));
		}

		/**
		 * Removes an item from local cache if value has changed
		 * @param string $key The item key
		 * @param mixed $valueHash The expected value hash
		 */
		protected function forgetLocalIfModified(string $key, $valueHash): void {

			try {
				$currentValueHash = $this->valueHash($this->localCache->get($key));
			}
			catch (Throwable $ex) {
				// if unserialization fails, we stop
				$currentValueHash = null;
			}
			

			if ($currentValueHash != $valueHash)
				$this->localCache->delete($key);
		}

	}