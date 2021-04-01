<?php
	/**
	 * Created by PhpStorm.
	 * User: chris
	 * Date: 17.01.19
	 * Time: 09:50
	 */

	namespace MehrItLaraLocalSynchronizedCacheTests\Helper;


	use Illuminate\Filesystem\Filesystem;

	class FileSystemWithLog extends Filesystem
	{
		protected $log = [];

		/**
		 * Returns the log entries for given operation type
		 * @param string $type
		 * @return array The log entries
		 */
		public function log($type) {
			return $this->log[$type] ?? [];
		}


		/**
		 * Removes all log entries
		 */
		public function clearLog() {
			$this->log = [];
		}

		/**
		 * Get the contents of a file.
		 *
		 * @param  string $path
		 * @param  bool $lock
		 * @return string
		 *
		 * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
		 */
		public function get($path, $lock = false) {

			$this->log['read'][] = $path;

			return parent::get($path, $lock);
		}

		/**
		 * Determine if a file or directory exists.
		 *
		 * @param  string $path
		 * @return bool
		 */
		public function exists($path) {
			$this->log['exists'][] = $path;

			return parent::exists($path);
		}

		/**
		 * Get contents of a file with shared access.
		 *
		 * @param  string $path
		 * @return string
		 */
		public function sharedGet($path) {
			$this->log['read'][] = $path;

			return parent::sharedGet($path);
		}

		/**
		 * Get the returned value of a file.
		 *
		 * @param  string $path
		 * @return mixed
		 *
		 * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
		 */
		public function getRequire($path, array $data = []) {
			$this->log['read'][] = $path;

			return parent::getRequire($path);
		}

		/**
		 * Get the MD5 hash of the file at the given path.
		 *
		 * @param  string $path
		 * @return string
		 */
		public function hash($path) {
			$this->log['hash'][] = $path;

			return parent::hash($path);
		}

		/**
		 * Write the contents of a file.
		 *
		 * @param  string $path
		 * @param  string $contents
		 * @param  bool $lock
		 * @return int|bool
		 */
		public function put($path, $contents, $lock = false) {

			$this->log['write'][] = $path;

			return parent::put($path, $contents, $lock);
		}

		/**
		 * Write the contents of a file, replacing it atomically if it already exists.
		 *
		 * @param  string $path
		 * @param  string $content
		 * @return void
		 */
		public function replace($path, $content) {

			$this->log['write'][] = $path;

			parent::replace($path, $content);
		}

		/**
		 * Prepend to a file.
		 *
		 * @param  string $path
		 * @param  string $data
		 * @return int
		 */
		public function prepend($path, $data) {
			$this->log['write'][] = $path;

			return parent::prepend($path, $data);
		}

		/**
		 * Append to a file.
		 *
		 * @param  string $path
		 * @param  string $data
		 * @return int
		 */
		public function append($path, $data) {
			$this->log['write'][] = $path;

			return parent::append($path, $data);
		}

		/**
		 * Delete the file at a given path.
		 *
		 * @param  string|array $paths
		 * @return bool
		 */
		public function delete($paths) {
			foreach((array)$paths as $currPath) {
				$this->log['delete'][] = $currPath;
			}

			return parent::delete($paths);
		}

		/**
		 * Create a directory.
		 *
		 * @param  string $path
		 * @param  int $mode
		 * @param  bool $recursive
		 * @param  bool $force
		 * @return bool
		 */
		public function makeDirectory($path, $mode = 0755, $recursive = false, $force = false) {
			$this->log['create'][] = $path;

			return parent::makeDirectory($path, $mode, $recursive, $force);
		}

		/**
		 * Recursively delete a directory.
		 *
		 * The directory itself may be optionally preserved.
		 *
		 * @param  string $directory
		 * @param  bool $preserve
		 * @return bool
		 */
		public function deleteDirectory($directory, $preserve = false) {
			$this->log['delete'][] = $directory;

			return parent::deleteDirectory($directory, $preserve);
		}


		/**
		 * Empty the specified directory of all files and folders.
		 *
		 * @param  string $directory
		 * @return bool
		 */
		public function cleanDirectory($directory) {
			$this->log['clean'][] = $directory;

			return parent::cleanDirectory($directory);
		}


	}