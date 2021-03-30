<?php
	/**
	 * Created by PhpStorm.
	 * User: chris
	 * Date: 16.01.19
	 * Time: 07:25
	 */

	namespace MehrIt\LaraLocalSynchronizedCache\Cache;


	use Illuminate\Cache\Repository;
	use Illuminate\Cache\RetrievesMultipleKeys;
	use Illuminate\Contracts\Cache\Store;
	use Illuminate\Contracts\Filesystem\FileNotFoundException;
	use Illuminate\Support\Str;

	class LocalSynchronizedStore implements Store
	{
		use RetrievesMultipleKeys;

		protected $buffered = true;

		protected $buffer = [];


		/**
		 * The Illuminate Filesystem instance.
		 *
		 * @var \Illuminate\Filesystem\Filesystem
		 */
		protected $files;

		/**
		 * @var string The directory where to store cache files
		 */
		protected $directory;


		/**
		 * @var Repository
		 */
		protected $sharedStore;


		protected $localTtl = 60;

		protected $lastLocalSync = null;


		protected $sharedStatePrefix = '__shared_state';

		protected $stateVersion;

		protected $stateBase;

		protected $stateDir;

		protected $sapi;

		protected $filePermission;

		/**
		 * Creates a new instance
		 * @param string $directory The directory where to store the cache entries
		 * @param \Illuminate\Cache\Repository $sharedStore The shared store for synchronization
		 * @param \Illuminate\Filesystem\Filesystem $files Filesystem instance
		 * @param bool $buffered True if to buffer in-memory
		 * @param int $localTtl The TTL for locally cached items without checking for changes
		 * @param string $sharedStatePrefix The shared store key prefix to use
		 * @param int $filePermission File permission
		 */
		public function __construct($directory, \Illuminate\Cache\Repository $sharedStore, \Illuminate\Filesystem\Filesystem $files, $buffered = true, $localTtl = 60, $sharedStatePrefix = 'shared_state__', $filePermission = 0644) {
			$this->sapi = php_sapi_name();

			$this->directory         = rtrim($directory, '/');
			$this->sharedStore       = $sharedStore;
			$this->files             = $files;
			$this->localTtl          = $localTtl;
			$this->sharedStatePrefix = $sharedStatePrefix;
			$this->buffered          = $buffered;
			$this->filePermission    = $filePermission;
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

			return $this->retrieve($key, $this->buffered);
		}

		/**
		 * Store an item in the cache for a given number of minutes.
		 *
		 * @param string $key
		 * @param mixed $value
		 * @param int $minutes
		 */
		public function put($key, $value, $minutes) {
			// refresh local cache if TTL expired
			$this->refreshLocal(false);

			$invalidations = [];
			$this->write($key, $value, $minutes, true, $invalidations);

			$this->locked($this->getLocalStateLockFilename(), function () use ($invalidations) {
				$this->publishState($invalidations);

				$this->persistLocalState();
			});
		}


		/**
		 * Increment the value of an item in the cache.
		 *
		 * @param string $key
		 * @param mixed $value
		 * @return int The incremented value
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
			return $this->increment($value * -1);
		}

		/**
		 * Store an item in the cache indefinitely.
		 *
		 * @param string $key
		 * @param mixed $value
		 */
		public function forever($key, $value) {
			$this->put($key, $value, 0);
		}

		/**
		 * Remove an item from the cache.
		 *
		 * @param string $key
		 * @return bool
		 */
		public function forget($key) {

			$this->forgetLocal($key);

			$this->locked($this->getLocalStateLockFilename(), function () use ($key) {
				$this->publishState([[$key, -1]]);

				$this->persistLocalState();
			});

			return true;
		}

		/**
		 * Remove all items from the cache.
		 *
		 * @return bool
		 */
		public function flush() {

			$this->locked($this->getLocalStateLockFilename(), function () {
				$this->stateBase    = (string)Str::uuid();
				$this->stateVersion = 1;
				$this->stateDir     = $this->stateBase;

				$this->invalidateAllLocal();

				$this->writeGlobalState($this->stateBase, $this->stateVersion);

				$this->persistLocalState();
			});

			return true;
		}

		/**
		 * Flush all local cache entries
		 */
		public function flushLocal() {
			$this->refreshLocal(true);

			$this->invalidateAllLocal();
		}

		/**
		 * Get the cache key prefix.
		 *
		 * @return string
		 */
		public function getPrefix() {
			return '';
		}

		/**
		 * Refreshes the local cache, so any global changes are readable
		 * @param bool $force If set to false, local cache is only synced if local cache's TTL is expired
		 */
		public function refreshLocal($force = true) {

			if ($force || !$this->lastLocalSync || time() > $this->lastLocalSync + $this->localTtl) {
				$this->locked($this->getLocalStateLockFilename(), function () {
					$this->syncLocalState();

					$this->persistLocalState();
				});
			}
		}

		/**
		 * Serializes the given variable as PHP code, so requiring a PHP file containing this code will return the given value
		 * @param mixed $var The value
		 * @return string The PHP code
		 */
		protected function serializePhp($var) {
			return '<?php return ' . var_export($var, true) . ';';
		}

		/**
		 * Gets the filename of the file holding the current local state
		 * @return string The filename
		 */
		protected function getLocalStateFilename() {
			return "{$this->directory}/{$this->sapi}/state.php";
		}

		/**
		 * Gets the filename of the lock file for the current local state
		 * @return string The filename
		 */
		protected function getLocalStateLockFilename() {
			return "{$this->directory}/{$this->sapi}/state.lock";
		}

		/**
		 * Loads the persisted local state from file to this instance
		 */
		protected function loadLocalState() {

			$stateFile = $this->getLocalStateFilename();

			$this->ensureCacheDirectoryExists($stateFile);

			try {
				$stateData = $this->files->getRequire($stateFile);
			}
			catch (FileNotFoundException $ex) {
				// state data not existing, we'll handle this below
			}

			// check if state data is present
			if (!empty($stateData) && is_array($stateData)) {
				// state data seams to be intact

				$this->stateVersion = $stateData['version'] ?? null;
				// if we don't have a state version, we will not accept any key, since data is corrupted
				$this->stateBase = $this->stateVersion ? ($stateData['key'] ?? null) : null;
				// if we don't have a state key, we will not accept any key, since data is corrupted
				$this->stateDir = $this->stateBase ? ($stateData['dir'] ?? null) : null;
			}
			else {
				// no state data

				$this->stateVersion = null;
				$this->stateBase    = null;
				$this->stateDir     = null;
			}

		}

		/**
		 * Persists the current local state to file
		 */
		protected function persistLocalState() {
			$stateFile = $this->getLocalStateFilename();

			$this->ensureCacheDirectoryExists($stateFile);

			$state = [
				'version' => $this->stateVersion,
				'key'     => $this->stateBase,
				'dir'     => $this->stateDir,
			];

			$this->files->put($stateFile, $this->serializePhp($state), true);
			
			$this->ensureFileHasCorrectPermissions($stateFile);

			// invalidate opcache of state file, since it might have changed
			opcache_invalidate($stateFile, true);
		}


		/**
		 * Checks if the current local cache is up to date. Any local cache will publish changes
		 * globally, so other local caches can check for modifications made elsewhere.
		 *
		 * If the global state was incrementally modified and all modification logs are available,
		 * any modified keys will be invalidated locally.
		 *
		 * If the basis of the global state has changed or global state is corrupted or unavailable,
		 * any local keys will be invalidated
		 *
		 * @param bool $reloadLocal True if to reload the current local state from filesystem
		 */
		protected function syncLocalState($reloadLocal = true) {

			// load the current local state
			if ($reloadLocal)
				$this->loadLocalState();

			$localStateBase    = $this->stateBase;
			$localStateVersion = $this->stateVersion;

			// get the global state
			$globalStateBase    = $this->readGlobalStateBase();
			$globalStateVersion = $globalStateBase ? $this->readGlobalStateVersion($globalStateBase) : 0;

			// has global base changed?
			if ($globalStateBase != $localStateBase || $globalStateVersion < $localStateVersion || !$localStateBase || !$localStateVersion) {
				// the global state key has changed => invalidate everything

				// if we are using a state which is now obsolete, we remember the state for garbage collection
				if ($localStateBase && $localStateVersion) {
					$this->ensureCacheDirectoryExists($this->directory);
					$this->files->put("{$this->directory}/global_gc_{$localStateBase}", $localStateVersion);
					
					$this->ensureFileHasCorrectPermissions("{$this->directory}/global_gc_{$localStateBase}");
				}

				if ($globalStateBase && $globalStateVersion) {
					// global state exists => import it
					$this->stateBase    = $globalStateBase;
					$this->stateVersion = $globalStateVersion;
					$this->stateDir     = $this->stateBase;
				}
				else {
					// the global state is not set or corrupted => create a new global state basis
					$this->stateBase    = (string)Str::uuid();
					$this->stateVersion = 1;
					$this->stateDir     = $this->stateBase;

					// publish the new state
					$this->writeGlobalState($this->stateBase, $this->stateVersion);
				}

				// invalidate local cache
				$this->invalidateAllLocal();
			}
			// has version changed?
			elseif ($globalStateVersion > $localStateVersion) {
				// the global state version has changed => try to apply invalidations

				$logs = $this->retrieveModificationLogs($globalStateBase, $localStateVersion + 1, $globalStateVersion);

				// If we could not receive all log entries, we can not update incrementally to global state.
				// We import the global state and invalidate the local cache
				if (count(array_filter($logs)) != $globalStateVersion - $localStateVersion) {

					// import global state
					$this->stateBase    = $globalStateBase;
					$this->stateVersion = $globalStateVersion;
					$this->stateDir     = $this->stateBase;

					// invalidate local cache
					$this->invalidateAllLocal();

					return;
				}

				// here we apply all logged invalidations to local cache
				foreach ($logs as $currLog) {
					foreach ($currLog as $currInvalidation) {
						$this->forgetLocalIfModified($currInvalidation[0], $currInvalidation[1]);
					}
				}
			}

			$this->lastLocalSync = time();

		}

		/**
		 * Flushes any local cache
		 */
		protected function invalidateAllLocal() {
			$this->files->cleanDirectory($this->getStateDirectoryPath());
			$this->buffer = [];
		}

		/**
		 * Removes an item from local cache if value has changed
		 * @param string $key The item key
		 * @param string $valueHash The expected value
		 */
		protected function forgetLocalIfModified($key, $valueHash) {

			if ($this->getCachedItemHash($key) != $valueHash)
				$this->forgetLocal($key);
		}


		/**
		 * Removes an item from local cache
		 * @param string $key The item key
		 */
		protected function forgetLocal($key) {

			$file = $this->getFilename($key);

			$this->files->delete($file);
			opcache_invalidate($file, true);

			unset($this->buffer[$key]);

		}


		/**
		 * Retrieves the value for given key
		 * @param string $key The key
		 * @param boolean $buffered True if to use buffer
		 * @param boolean $locked True if to obtain lock. Should only be set to false, if lock already obtained
		 * @param null|int $expiresAt Will be filled with the expiration time of the returned value
		 * @return mixed|null The value for the given key
		 */
		protected function retrieve($key, $buffered, $locked = true, &$expiresAt = null) {

			if (!$buffered || !array_key_exists($key, $this->buffer)) {
				if (!$locked) {
					$value = $this->load($key);
				}
				else {
					$value = $this->locked($this->getLockFilename($key), function () use ($key) {
						return $this->load($key);
					});
				}

				if ($buffered) {
					if ($value)
						$this->buffer[$key] = $value;
					else
						unset($this->buffer[$key]);
				}
			}
			else {
				$value = $this->buffer[$key];

				// check if expired while in memory
				if ($value[1] && $value[1] < time()) {
					$this->forgetLocal($key);

					$value = null;
				}

			}

			if ($value) {
				$expiresAt = $value[1];

				return $value[0];
			}
			else {
				$expiresAt = 0;

				return null;
			}
		}

		/**
		 * Writes the given value to persistent storage and memory buffer if activated
		 * @param string $key The key
		 * @param mixed $value The value
		 * @param int $seconds The TTL in seconds
		 * @param boolean $locked True if to obtain lock. Should only be set to false, if lock already obtained
		 * @param array $invalidations The invalidations to publish
		 */
		protected function write($key, $value, $seconds = 0, $locked = true, &$invalidations = []) {

			$expires = $seconds != 0 ? (time() + $seconds) : 0;

			if ($this->buffered)
				$this->buffer[$key] = [
					$value,
					$expires,
				];

			if (!$locked) {
				$this->persist($key, $value, $expires, $invalidations);
			}
			else {
				$this->locked($this->getLockFilename($key), function () use ($key, $value, $expires, &$invalidations) {
					$this->persist($key, $value, $expires, $invalidations);
				});
			}
		}

		/**
		 * Gets the current state directory path
		 * @return string The current state directory path
		 */
		protected function getStateDirectoryPath() {
			return "{$this->directory}/{$this->sapi}/{$this->stateDir}";
		}

		/**
		 * Gets the file name for given key
		 * @param string $key The key
		 * @return string The file name to use
		 */
		protected function getFilename($key) {
			$keyHash = $this->hashKey($key);

			$parts = array_slice(str_split($keyHash, 2), 0, 2);

			$stateDir = $this->getStateDirectoryPath();

			return "{$stateDir}/" . implode('/', $parts) . "/$keyHash.php";
		}

		/**
		 * Gets the lock file name for given key
		 * @param string $key The key
		 * @return string The lock file name to use
		 */
		protected function getLockFilename(string $key) {

			$keyHash = $this->hashKey($key);

			$parts = array_slice(str_split($keyHash, 2), 0, 2);

			$stateDir = $this->getStateDirectoryPath();

			return "{$stateDir}/" . implode('/', $parts) . "/$keyHash.lock";
		}

		/**
		 * Create the file cache directory if necessary.
		 *
		 * @param string $path
		 * @return void
		 */
		protected function ensureCacheDirectoryExists($path) {
			if (!$this->files->exists(dirname($path))) {
				$this->files->makeDirectory(dirname($path), 0777, true, true);
			}
		}

		/**
		 * Ensure the cache file has the correct permissions.
		 *
		 * @param string $path
		 * @return void
		 */
		protected function ensureFileHasCorrectPermissions($path) {
			if (is_null($this->filePermission) ||
			    intval($this->files->chmod($path), 8) == $this->filePermission) {
				return;
			}

			$this->files->chmod($path, $this->filePermission);
		}

		/**
		 * Persists the given cache entry
		 * @param string $key The key
		 * @param mixed $value The value
		 * @param int $expires The time when item expires
		 * @param array $invalidations The invalidations to publish
		 */
		protected function persist($key, $value, $expires, &$invalidations = []) {

			$path = $this->getFilename($key);

			$this->ensureCacheDirectoryExists($path);

			// build data
			$shouldSerialize = is_object($value);
			$serializedValue = $shouldSerialize ? serialize($value) : $value;
			$data            = [
				$expires,
				$shouldSerialize,
				$serializedValue,
			];

			$this->files->put($path, $this->serializePhp($data));
			
			$this->ensureFileHasCorrectPermissions($path);

			// invalidate opcache
			opcache_invalidate($path, true);

			$invalidations[] = [$key, sha1($shouldSerialize ? $serializedValue : serialize($value))];
		}

		/**
		 * Loads the given cache entry
		 * @param string $key The key
		 * @return array|null An array with cached value and expiration time. Null if not existing or expired
		 */
		protected function load($key) {

			try {
				$entryData = $this->files->getRequire($this->getFilename($key));

				[$expires, $serialized, $value] = $entryData;

				// check if expired
				if ($expires && $expires < time()) {

					// we only remove locally, since sync is costly and
					// every other instance can detect expiration on it's
					// own which is much cheaper
					$this->forgetLocal($key);

					return null;
				}

				return [
					($serialized ? unserialize($value) : $value),
					$expires,
				];
			}
			catch (\Throwable $ex) {
				return null;
			}
		}

		/**
		 * Gets the hash for given item
		 * @param string $key The key
		 * @return string The hash
		 */
		protected function getCachedItemHash($key) {
			try {
				$entryData = $this->files->getRequire($this->getFilename($key));

				[$expires, $serialized, $value] = $entryData;

				return ($serialized ? sha1($value) : sha1(serialize($value)));
			}
			catch (\Throwable $ex) {
				return null;
			}
		}

		/**
		 * Executes the given callback and locks the given file exclusively
		 * @param string $lockFile The file to lock
		 * @param callable $callback The callback
		 * @return mixed The callback return
		 */
		protected function locked(string $lockFile, callable $callback) {

			try {
				$this->ensureCacheDirectoryExists($lockFile);

				$fh = fopen($lockFile, 'w+');
				if (!$fh)
					throw new \RuntimeException("Could not open lock file \"$lockFile\"");
				
				// ensure correct file permissions
				$this->ensureFileHasCorrectPermissions($lockFile);

				if (!flock($fh, LOCK_EX))
					throw new \RuntimeException("Could not obtain lock for \"$lockFile\"");
				$locked = true;

				return call_user_func($callback);
			}
			finally {
				if (!empty($locked))
					flock($fh, LOCK_UN);
			}
		}

		/**
		 * Hashes the given key
		 * @param string $key The key
		 * @return string The hash
		 */
		protected function hashKey(string $key) {
			return sha1($key);
		}

		protected function publishState(array $invalidations) {

			$currentBase = $this->readGlobalStateBase();

			$tries = 0;
			while (true) {

				// make sure, current state is up to date
				if ($this->stateBase != $currentBase)
					$this->syncLocalState();

				// create a new version
				$version = $this->incrementGlobalStateVersion($this->stateBase);

				// publish modifications
				$this->writeModificationLog($this->stateBase, $version, $invalidations);

				// if the global state base has not changed meanwhile, we are done
				if (($currentBase = $this->readGlobalStateBase()) == $this->stateBase)
					break;

				// The global state base changed meanwhile, we have to repeat otherwise
				// changes might not be published to all local caches

				if (++$tries > 100)
					throw new CacheSyncException("Could not publish cache modification within $tries tries");

				break;
			}

			$this->stateVersion = $version;
		}

		/**
		 * Writes a new global state with given values
		 * @param string $globalStateBase The global state base
		 * @param int $globalStateVersion The state version
		 */
		protected function writeGlobalState($globalStateBase, $globalStateVersion) {
			$this->sharedStore->forever("{$this->sharedStatePrefix}state_base", $globalStateBase);
			$this->sharedStore->forever("{$this->sharedStatePrefix}state_{$globalStateBase}_version", $globalStateVersion);
		}

		/**
		 * Reads the current global state base
		 * @return string|null The state base
		 */
		protected function readGlobalStateBase() {
			return $this->sharedStore->get("{$this->sharedStatePrefix}state_base");
		}

		/**
		 * Reads the current global state version
		 * @param string $globalStateBase The global state base
		 * @return int|null The state version
		 */
		protected function readGlobalStateVersion($globalStateBase) {
			return $this->sharedStore->get("{$this->sharedStatePrefix}state_{$globalStateBase}_version");
		}

		/**
		 * Increments the current global state version
		 * @param string $globalStateBase The global state base
		 * @return int|null The incremented state version
		 */
		protected function incrementGlobalStateVersion($globalStateBase) {
			return $this->sharedStore->increment("{$this->sharedStatePrefix}state_{$globalStateBase}_version");
		}

		/**
		 * Retrieve modification logs
		 * @param string $globalStateBase The global state base
		 * @param int $versionFrom The version from
		 * @param int $versionTo The version to
		 * @return array
		 */
		protected function retrieveModificationLogs($globalStateBase, $versionFrom, $versionTo) {
			$logKeys = [];
			for ($i = $versionFrom; $i <= $versionTo; ++$i) {
				$logKeys[] = "{$this->sharedStatePrefix}state_{$globalStateBase}_{$i}_log";
			}

			// retrieve modification logs
			return array_values($this->sharedStore->many($logKeys));
		}

		/**
		 * Writes the given modification log to the global store
		 * @param string $globalStateBase The global state base
		 * @param int $version The version to write modifications for
		 * @param array $modifications The modifications
		 */
		protected function writeModificationLog($globalStateBase, $version, $modifications) {
			$this->sharedStore->forever("{$this->sharedStatePrefix}state_{$globalStateBase}_{$version}_log", $modifications);
		}

	}