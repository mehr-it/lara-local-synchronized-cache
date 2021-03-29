<?php
	/**
	 * Created by PhpStorm.
	 * User: chris
	 * Date: 19.01.19
	 * Time: 16:11
	 */

	namespace MehrIt\LaraLocalSynchronizedCache;


	use MehrIt\LaraLocalSynchronizedCache\Exception\IOException;

	trait InteractsWithFiles
	{
		/**
		 * Opens a given file
		 * @param $filename
		 * @param $mode
		 * @param null $use_include_path
		 * @param null $context
		 * @return bool|resource
		 */
		protected function openFile($filename, $mode, $use_include_path = null, $context = null) {
			$handle = fopen($filename, $mode, $use_include_path, $context);

			if ($handle === false)
				throw new IOException("Failed to open file \"$filename\"");

			return $handle;
		}

		/**
		 * Closes a file
		 * @param resource $handle
		 */
		protected function closeFile($handle) {
			if (!fclose($handle))
				throw new IOException('Could not close file');
		}

		/**
		 * Locks the given file
		 * @param resource $handle
		 * @param bool $exclusive True if to lock exclusive. Else shared lock is acquired
		 * @param bool $noBlock True if not to wait for lock
		 */
		protected function lockFile($handle, $exclusive = false, $noBlock = false) {

			$lock = ($exclusive ? LOCK_EX : LOCK_SH);
			if ($noBlock)
				$lock = $lock | LOCK_NB;

			if (!flock($handle, $lock))
				throw new IOException('Could not lock file');

		}

		/**
		 * Unlocks the given file
		 * @param resource $handle
		 */
		protected function unlockFile($handle) {
			if (!flock($handle, LOCK_UN))
				throw new IOException('Could not unlock file');
		}

		/**
		 * Executes the given callback locking given file meanwhile
		 * @param resource $handle
		 * @param callable $callback
		 * @param bool $exclusive
		 * @param bool $noBlock
		 * @return mixed The function return
		 */
		protected function locked($handle, callable $callback, $exclusive = false, $noBlock = false) {

			/** @noinspection PhpUnusedLocalVariableInspection */
			$lockAcquired = false;
			try {
				$this->lockFile($handle, $exclusive, $noBlock);
				$lockAcquired = true;

				return call_user_func($callback, $handle);
			}
			finally {
				if ($lockAcquired)
					$this->unlockFile($handle);
			}
		}

		/**
		 * @param $handle
		 * @param $content
		 * @param null $length
		 */
		protected function writeFile($handle, $content, $length = null) {
			if (fwrite($handle, $content, $length) === false)
				throw new IOException('Could not write to file');
		}

		protected function withFile($filename, $mode, callable $callback, $locked = false, $use_include_path = null, $context = null) {

			if ($locked)
				return $this->withFileLocked($filename, $mode, $callback, false, $use_include_path, $context);


			$handle = $this->openFile($filename, $mode, $use_include_path, $context);

			try {
				return call_user_func($callback, $handle);
			}
			finally {
				$this->closeFile($handle);
			}
		}

		/**
		 * Executes the given callback with opened and locked file
		 * @param string $filename The filename
		 * @param string $mode The mode
		 * @param callable $callback The callback
		 * @param bool $noBlock
		 * @param null $use_include_path
		 * @param null $context
		 * @return mixed The callback return
		 */
		protected function withFileLocked($filename, $mode, callable $callback, $noBlock = false, $use_include_path = null, $context = null) {

			$exclusive = ($mode[0] != 'r');

			return $this->withFile($filename, $mode, function($fh) use ($callback, $noBlock, $exclusive) {

				return $this->locked($fh, $callback, $exclusive, $noBlock);

			}, false, $use_include_path, $context);
		}
	}