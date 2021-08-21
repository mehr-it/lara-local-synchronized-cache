<?php


	namespace MehrIt\LaraLocalSynchronizedCache\Cache\Concerns;


	use Illuminate\Cache\Repository;
	use Illuminate\Support\Str;
	use MehrIt\LaraLocalSynchronizedCache\Cache\CacheSyncException;
	use MehrIt\PhpCache\PhpCache;
	use Throwable;

	trait SharedState
	{
		/**
		 * @var PhpCache
		 */
		protected $localStateCache;

		/**
		 * @var int
		 */
		protected $localTtl;
		
		/**
		 * @var int|null 
		 */
		protected $lastLocalSync = null;
		
		/**
		 * @var Repository
		 */
		protected $sharedStore;

		protected $sharedStatePrefix = '__shared_state';

		protected $stateVersion;

		protected $stateBase;
		
		protected $maxCatchupVersions = 100;


		/**
		 * Removes an item from local cache if value has changed
		 * @param string $key The item key
		 * @param mixed $valueHash The expected value hash
		 */
		protected abstract function forgetLocalIfModified(string $key, $valueHash): void;

		/**
		 * Invalidates all local cached data
		 */
		protected abstract function invalidateAllLocal(): void;
		

		/**
		 * Executes the given callback while locking the state exclusive
		 * @param callable $callback
		 * @return mixed
		 */
		protected function withStateLocked(callable $callback) {
			$lock = $this->localStateCache->lock('state', LOCK_EX);
			
			try {
				return call_user_func($callback);
			}
			finally {
				$lock->release();
			}
		}

		/**
		 * Loads the persisted local state from file to this instance
		 */
		protected function loadLocalState() {

			$stateData = $this->localStateCache->get('state');
			
			// check if state data is present
			if (!empty($stateData) && is_array($stateData)) {
				// state data seams to be intact

				$this->stateVersion = $stateData['version'] ?? null;
				
				// if we don't have a state version, we will not accept any key, since data is corrupted
				$this->stateBase = $this->stateVersion ? ($stateData['key'] ?? null) : null;
		
			}
			else {
				// no state data

				$this->stateVersion = null;
				$this->stateBase    = null;
			}

		}

		/**
		 * Persists the current local state to file
		 */
		protected function persistLocalState() {

			$state = [
				'version' => $this->stateVersion,
				'key'     => $this->stateBase,
			];

			$this->localStateCache->set('state', $state);
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
				
				
				if ($globalStateBase && $globalStateVersion) {
					// global state exists => import it
					$this->stateBase    = $globalStateBase;
					$this->stateVersion = $globalStateVersion;
					

				}
				else {
					// the global state is not set or corrupted => create a new global state
					$this->resetStateGlobally();
					
				}

				// invalidate local cache
				$this->invalidateAllLocal();

				$this->persistLocalState();
			}
			// has version changed?
			elseif ($globalStateVersion > $localStateVersion) {
				// the global state version has changed => try to apply invalidations

				$logs = [];
				// we only try to catch up with global state, if not too many missing
				if ($globalStateVersion - $localStateVersion <= $this->maxCatchupVersions)
					$logs = $this->retrieveModificationLogs($globalStateBase, $localStateVersion + 1, $globalStateVersion);

				// If we could not receive all log entries, we can not update incrementally to global state.
				// We import the global state and invalidate the local cache.
				// The same if no logs were fetched because too many versions to catch up
				if (!$logs || count(array_filter($logs)) != $globalStateVersion - $localStateVersion) {

					// import global state
					$this->stateBase    = $globalStateBase;
					$this->stateVersion = $globalStateVersion;

					// invalidate local cache
					$this->invalidateAllLocal();

					$this->persistLocalState();

					return;
				}

				// here we apply all logged invalidations to local cache
				$mergedInvalidations = [];
				foreach ($logs as $currLog) {
					foreach ($currLog as $currInvalidation) {
						$key = $currInvalidation[0] ?? null;
													
						if ($key !== null)
							$mergedInvalidations[$key] = $currInvalidation[1];
					}
				}
				foreach($mergedInvalidations as $key => $valueHash) {
					$this->forgetLocalIfModified($key, $valueHash);
				}

				// we are now up-to date with global state
				$this->stateBase    = $globalStateBase;
				$this->stateVersion = $globalStateVersion;
				
				$this->persistLocalState();
			}

			$this->lastLocalSync = time();

		}

		/**
		 * Publishes a new modified state
		 * @param array[] $invalidations The invalidations
		 */
		protected function publishState(array $invalidations) {
			
			try {
				// create a new version
				$version = $this->incrementGlobalStateVersion($this->stateBase);

				// publish modifications
				$this->writeModificationLog($this->stateBase, $version, $invalidations);

			}
			catch(Throwable $ex) {
				throw new CacheSyncException('Failed to publish cache state: ' . $ex->getMessage() , 0, $ex);
			}
			
			$this->stateVersion = $version;
		}
		

		/**
		 * Creates a new state base and publishes it globally
		 */
		protected function resetStateGlobally() {

			$this->stateBase    = (string)Str::uuid();
			$this->stateVersion = 1;
			
			$this->sharedStore->forever("{$this->sharedStatePrefix}state_base", $this->stateBase);
			$this->sharedStore->forever("{$this->sharedStatePrefix}state_{$this->stateBase}_version", $this->stateVersion);
		}

		/**
		 * Reads the current global state base
		 * @return string|null The state base
		 */
		protected function readGlobalStateBase(): ?string {
			return $this->sharedStore->get("{$this->sharedStatePrefix}state_base");
		}

		/**
		 * Reads the current global state version
		 * @param string $globalStateBase The global state base
		 * @return int|null The state version
		 */
		protected function readGlobalStateVersion(string $globalStateBase): ?int {
			return $this->sharedStore->get("{$this->sharedStatePrefix}state_{$globalStateBase}_version");
		}

		/**
		 * Increments the current global state version
		 * @param string $globalStateBase The global state base
		 * @return int|null The incremented state version
		 */
		protected function incrementGlobalStateVersion(string $globalStateBase): ?int {
			return $this->sharedStore->increment("{$this->sharedStatePrefix}state_{$globalStateBase}_version");
		}

		/**
		 * Retrieve modification logs
		 * @param string $globalStateBase The global state base
		 * @param int $versionFrom The version from
		 * @param int $versionTo The version to
		 * @return array
		 */
		protected function retrieveModificationLogs(string $globalStateBase, int $versionFrom, int $versionTo): array {
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
		protected function writeModificationLog(string $globalStateBase, int $version, array $modifications): void {
			$this->sharedStore->forever("{$this->sharedStatePrefix}state_{$globalStateBase}_{$version}_log", $modifications);
		}
	}