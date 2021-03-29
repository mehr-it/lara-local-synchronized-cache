<?php
	/**
	 * Created by PhpStorm.
	 * User: chris
	 * Date: 19.01.19
	 * Time: 15:55
	 */

	namespace MehrIt\LaraLocalSynchronizedCache;


	use Illuminate\Filesystem\Filesystem;

	trait SerializesPhp
	{
		use InteractsWithFiles;

		/**
		 * @var Filesystem
		 */
		protected $files;

		/**
		 * Serializes the given variable as PHP code, so requiring a PHP file containing this code will return the given value
		 * @param mixed $var The value
		 */
		protected function serializePhp($var, $path) {
			if ($this->requiresSerialization($var)) {
				$value = var_export(serialize($var), true);
				$php  = sprintf('<?php return unserialize(%s);', $value);
			}
			else {
				$value = var_export($var, true);
				$php = sprintf('<?php return %s;', $value);

			}

			$this->withFileLocked($path, 'w+', function ($fh) use ($php) {
				$this->writeFile($fh, $php);
			});

		}

		protected function unserializePhp($path) {
			return $this->withFileLocked($path, 'r', function() use ($path) {
				return include $path;
			});
		}

		/**
		 * Checks if a value must be serialized.
		 * @param mixed $var The value
		 * @return bool True if to serialize. False if simple var_export is sufficient
		 */
		protected function requiresSerialization($var) {

			// walk arrays
			if (is_array($var)) {
				foreach($var as $curr) {
					if($this->requiresSerialization($curr))
						return true;
				}
			}

			// Objects need serialization. Other variables not.
			return is_object($var);

		}

	}