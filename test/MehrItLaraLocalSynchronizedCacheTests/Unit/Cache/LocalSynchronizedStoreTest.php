<?php
	/**
	 * Created by PhpStorm.
	 * User: chris
	 * Date: 17.01.19
	 * Time: 01:11
	 */

	namespace MehrItLaraLocalSynchronizedCacheTests\Unit\Cache;


	use Illuminate\Cache\ArrayStore;
	use Illuminate\Cache\Repository;
	use Illuminate\Filesystem\Filesystem;
	use MehrIt\LaraLocalSynchronizedCache\Cache\LocalSynchronizedStore;
	use MehrItLaraLocalSynchronizedCacheTests\Helper\FileSystemWithLog;
	use MehrItLaraLocalSynchronizedCacheTests\Unit\TestCase;

	class LocalSynchronizedStoreTest extends TestCase
	{
		protected $tmpDirectories = [];

		/**
		 * @var Repository
		 */
		protected $sharedStore = null;

		protected function createTempDir() {
			$path = sys_get_temp_dir() . uniqid('/PhpUnitTest_');

			mkdir($path);

			$this->tmpDirectories[] = $path;

			return $path;
		}

		protected function rrmdir($dir) {
			if (is_dir($dir)) {
				$objects = scandir($dir);
				foreach ($objects as $object) {
					if ($object != "." && $object != "..") {
						if (is_dir($dir . "/" . $object))
							$this->rrmdir($dir . "/" . $object);
						else
							unlink($dir . "/" . $object);
					}
				}
				rmdir($dir);
			}
		}

		/**
		 * Setup the test environment.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			$this->sharedStore = new Repository(new ArrayStore());
		}


		/**
		 * @after
		 */
		public function clearTempDirectories() {
			foreach($this->tmpDirectories as $curr) {
				$this->rrmdir($curr);
			}
		}


		protected function checkValuePersisted($directory, $key, $expectedValue, Repository $sharedStore = null) {
			$s = new LocalSynchronizedStore($directory, $sharedStore ?: $this->sharedStore, new Filesystem());

			$this->assertEquals($expectedValue, $s->get($key));
		}


		public function testPutGet_buffered() {

			$d1 = $this->createTempDir();


			$fs = new FileSystemWithLog();

			$s = new LocalSynchronizedStore($d1, $this->sharedStore, $fs);

			$s->put('x', 12, 0);
			$s->put('y', ['x' => 12], 14);
			$s->put('z', new \stdClass(), 1);
			$this->checkValuePersisted($d1, 'x', 12);
			$this->checkValuePersisted($d1, 'y', ['x' => 12]);
			$this->checkValuePersisted($d1, 'z', new \stdClass());

			// clear file system log
			$fs->clearLog();

			// read value
			$this->assertEquals(12, $s->get('x'));
			$this->assertEquals(['x' => 12], $s->get('y'));
			$this->assertEquals(new \stdClass(), $s->get('z'));
			$this->assertEmpty($fs->log('read'), 'Store should not read from file system for retrieving buffered value');

			// read value again
			$this->assertEquals(12, $s->get('x'));
			$this->assertEquals(12, $s->get('x'));
			$this->assertEquals(['x' => 12], $s->get('y'));
			$this->assertEquals(new \stdClass(), $s->get('z'));
			$this->assertEmpty($fs->log('read'), 'Store should not read from file system for retrieving buffered value');
		}

		public function testPutGet_unbuffered() {

			$d1 = $this->createTempDir();

			$fs = new FileSystemWithLog();

			$s = new LocalSynchronizedStore($d1, $this->sharedStore, $fs, false);

			$s->put('x', 12, 0);
			$s->put('y', ['x' => 12], 14);
			$s->put('z', new \stdClass(), 1);
			$this->checkValuePersisted($d1, 'x', 12);
			$this->checkValuePersisted($d1, 'y', ['x' => 12]);
			$this->checkValuePersisted($d1, 'z', new \stdClass());

			// clear file system log
			$fs->clearLog();

			$this->assertEquals(12, $s->get('x'));
			$this->assertEquals(['x' => 12], $s->get('y'));
			$this->assertEquals(new \stdClass(), $s->get('z'));
			$this->assertCount(3, $fs->log('read'), 'Store should be read from file system');

			$this->assertEquals(12, $s->get('x'));
			$this->assertEquals(['x' => 12], $s->get('y'));
			$this->assertEquals(new \stdClass(), $s->get('z'));
			$this->assertCount(6, $fs->log('read'), 'Store should be read from file system');

		}

		public function testPutForget_buffered() {

			$d1 = $this->createTempDir();


			$fs = new FileSystemWithLog();

			$s = new LocalSynchronizedStore($d1, $this->sharedStore, $fs);

			$s->put('x', 12, 0);
			$s->put('y', 9, 0);
			$this->checkValuePersisted($d1, 'x', 12);
			$this->checkValuePersisted($d1, 'y', 9);

			$s->forget('x');
			$this->checkValuePersisted($d1, 'x', null);
			$this->checkValuePersisted($d1, 'y', 9);


			// read value
			$this->assertNull($s->get('x'));
			$this->assertEquals(9, $s->get('y'));

			// read value again
			$this->assertNull($s->get('x'));
			$this->assertEquals(9, $s->get('y'));
		}

		public function testPutForget_unbuffered() {

			$d1 = $this->createTempDir();


			$fs = new FileSystemWithLog();

			$s = new LocalSynchronizedStore($d1, $this->sharedStore, $fs, false);

			$s->put('x', 12, 0);
			$s->put('y', 9, 0);
			$this->checkValuePersisted($d1, 'x', 12);
			$this->checkValuePersisted($d1, 'y', 9);

			$s->forget('x');
			$this->checkValuePersisted($d1, 'x', null);
			$this->checkValuePersisted($d1, 'y', 9);


			// read value
			$this->assertNull($s->get('x'));
			$this->assertEquals(9, $s->get('y'));

			// read value again
			$this->assertNull($s->get('x'));
			$this->assertEquals(9, $s->get('y'));

		}

		public function testPutFlush_buffered() {

			$d1 = $this->createTempDir();


			$fs = new FileSystemWithLog();

			$s = new LocalSynchronizedStore($d1, $this->sharedStore, $fs);

			$s->put('x', 12, 0);
			$s->put('y', 9, 0);
			$this->checkValuePersisted($d1, 'x', 12);
			$this->checkValuePersisted($d1, 'y', 9);

			$s->flush();
			$this->checkValuePersisted($d1, 'x', null);
			$this->checkValuePersisted($d1, 'y', null);


			// read value
			$this->assertNull($s->get('x'));
			$this->assertNull($s->get('y'));

			// read value again
			$this->assertNull($s->get('x'));
			$this->assertNull($s->get('y'));
		}

		public function testPutFlush_unbuffered() {

			$d1 = $this->createTempDir();


			$fs = new FileSystemWithLog();

			$s = new LocalSynchronizedStore($d1, $this->sharedStore, $fs, false);

			$s->put('x', 12, 0);
			$s->put('y', 9, 0);
			$this->checkValuePersisted($d1, 'x', 12);
			$this->checkValuePersisted($d1, 'y', 9);

			$s->flush();
			$this->checkValuePersisted($d1, 'x', null);
			$this->checkValuePersisted($d1, 'y', null);


			// read value
			$this->assertNull($s->get('x'));
			$this->assertNull($s->get('y'));

			// read value again
			$this->assertNull($s->get('x'));
			$this->assertNull($s->get('y'));
		}


		public function testPutGetForgetIncDec_newStoreInstanceSynchronizes() {

			$d1 = $this->createTempDir();
			$d2 = $this->createTempDir();

			$fs1 = new FileSystemWithLog();
			$fs2 = new FileSystemWithLog();

			// create two stores and init them with different values
			$s1 = new LocalSynchronizedStore($d1, $this->sharedStore, $fs1);
			$s1->put('x', 12, 0);
			$s1->put('y', 23, 0);
			$s1->put('z', 34, 0);
			$s1->put('a', 99, 0);
			$s1->put('b', 111, 14);
			$s1->put('c', 222, 13);
			$s1->put('d', 100, 0);
			$s1->put('e', 100, 0);

			$s2 = new LocalSynchronizedStore($d2, $this->sharedStore, $fs2);
			$s2->put('x', 1, 0);
			$s2->put('y', 4, 0);
			$s2->put('a', 99, 0);
			$s2->put('b', 111, 23);
			$s2->forget('c');
			$s2->increment('d');
			$s2->increment('e');


			// re-create a new store instance for first store and read values (all values changed in s2, should be invalidated and therefore return null)
			$s1New = new LocalSynchronizedStore($d1, $this->sharedStore, $fs1);
			$this->assertNull($s1New->get('x')); // invalidated because set in s2
			$this->assertNull($s1New->get('y')); // invalidated because set in s2
			$this->assertEquals(34, $s1New->get('z')); // NOT invalidated because not modified by s2
			$this->assertEquals(99, $s1New->get('a')); // NOT invalidated because still same value
			$this->assertEquals(111, $s1New->get('b')); // NOT invalidated because still same value, even if expiration changed
			$this->assertNull($s1New->get('c')); // invalidated because removed in s2
			$this->assertNull($s1New->get('d')); // invalidated because incremented in s2
			$this->assertNull($s1New->get('e')); // invalidated because decremented in s2


			// re-create a new store instance for second store and check assert values still present
			$s2New = new LocalSynchronizedStore($d2, $this->sharedStore, $fs2);
			$this->assertEquals(1, $s2New->get('x'));
			$this->assertEquals(4, $s2New->get('y'));
			$this->assertEquals(99, $s2New->get('a'));
			$this->assertEquals(111, $s2New->get('b'));
			$this->assertNull($s2New->get('c'));
			$this->assertNull($s2New->get('d')); // increment will always forget item
			$this->assertNull($s2New->get('e')); // decrement will always forget item
		}


		public function testPutGetForgetIncDec_existingStoreInstanceSynchronizes() {

			$d1 = $this->createTempDir();
			$d2 = $this->createTempDir();

			$fs1 = new FileSystemWithLog();
			$fs2 = new FileSystemWithLog();

			// create two stores and init them with different values
			$s1 = new LocalSynchronizedStore($d1, $this->sharedStore, $fs1, true, 1);
			$s2 = new LocalSynchronizedStore($d2, $this->sharedStore, $fs2);


			// modify in store 1
			$s1->put('x', 12, 0);
			$s1->put('y', 23, 0);
			$s1->put('z', 34, 0);
			$s1->put('a', 99, 0);
			$s1->put('b', 111, 14);
			$s1->put('c', 222, 13);
			$s1->put('d', 100, 0);
			$s1->put('e', 100, 0);


			// modify in store 2
			$s2->put('x', 1, 0);
			$s2->put('y', 4, 0);
			$s2->put('a', 99, 0);
			$s2->put('b', 111, 23);
			$s2->forget('c');
			$s2->increment('d');
			$s2->increment('e');


			// read from store 1 - should not yet be synchronized
			$this->assertEquals(12, $s1->get('x'));
			$this->assertEquals(23, $s1->get('y'));
			$this->assertEquals(34, $s1->get('z'));
			$this->assertEquals(99, $s1->get('a'));
			$this->assertEquals(111, $s1->get('b'));
			$this->assertEquals(222, $s1->get('c'));
			$this->assertEquals(100, $s1->get('d'));
			$this->assertEquals(100, $s1->get('e'));


			// wait until local TTL of store 1 is expired
			sleep(2);


			// read values from s2 again, after TTL expire (all values changed in s2, should now be invalidated and therefore return null)
			$this->assertNull($s1->get('x')); // invalidated because set in s2
			$this->assertNull($s1->get('y')); // invalidated because set in s2
			$this->assertEquals(34, $s1->get('z')); // NOT invalidated because not modified by s2
			$this->assertEquals(99, $s1->get('a')); // NOT invalidated because still same value
			$this->assertEquals(111, $s1->get('b')); // NOT invalidated because still same value, even if expiration changed
			$this->assertNull($s1->get('c')); // invalidated because removed in s2
			$this->assertNull($s1->get('d')); // invalidated because incremented in s2
			$this->assertNull($s1->get('e')); // invalidated because decremented in s2


			$s2New = new LocalSynchronizedStore($d2, $this->sharedStore, $fs2);
			$this->assertEquals(1, $s2New->get('x'));
			$this->assertEquals(4, $s2New->get('y'));
			$this->assertEquals(99, $s2New->get('a'));
			$this->assertEquals(111, $s2New->get('b'));
			$this->assertNull($s2New->get('c'));
			$this->assertNull($s2New->get('d')); // increment will always forget item
			$this->assertNull($s2New->get('e')); // decrement will always forget item

		}

		public function testFlush_newStoreInstanceSynchronizes() {

			$d1 = $this->createTempDir();
			$d2 = $this->createTempDir();

			$fs1 = new FileSystemWithLog();
			$fs2 = new FileSystemWithLog();

			// create two stores and init them with different values
			$s1 = new LocalSynchronizedStore($d1, $this->sharedStore, $fs1);
			$s1->put('x', 12, 0);
			$s1->put('y', 23, 0);
			$s1->put('z', 34, 0);
			$s1->put('a', 99, 0);
			$s1->put('b', 111, 14);
			$s1->put('c', 222, 13);
			$s1->put('d', 100, 0);
			$s1->put('e', 100, 0);

			$s2 = new LocalSynchronizedStore($d2, $this->sharedStore, $fs2);
			$s2->flush();


			// re-create a new store instance for first store and read values (all values changed in s2, should be invalidated and therefore return null)
			$s1New = new LocalSynchronizedStore($d1, $this->sharedStore, $fs1);
			$this->assertNull($s1New->get('x'));
			$this->assertNull($s1New->get('y'));
			$this->assertNull($s1New->get('z'));
			$this->assertNull($s1New->get('a'));
			$this->assertNull($s1New->get('b'));
			$this->assertNull($s1New->get('c'));
			$this->assertNull($s1New->get('d'));
			$this->assertNull($s1New->get('e'));


			// re-create a new store instance for second store and check assert values still present
			$s2New = new LocalSynchronizedStore($d2, $this->sharedStore, $fs2);
			$this->assertNull($s2New->get('x'));
			$this->assertNull($s2New->get('y'));
			$this->assertNull($s2New->get('a'));
			$this->assertNull($s2New->get('b'));
			$this->assertNull($s2New->get('c'));
			$this->assertNull($s2New->get('d'));
			$this->assertNull($s2New->get('e'));
		}

		public function testFlush_existingStoreInstanceSynchronizes() {

			$d1 = $this->createTempDir();
			$d2 = $this->createTempDir();

			$fs1 = new FileSystemWithLog();
			$fs2 = new FileSystemWithLog();

			// create two stores and init them with different values
			$s1 = new LocalSynchronizedStore($d1, $this->sharedStore, $fs1, true, 1);
			$s2 = new LocalSynchronizedStore($d2, $this->sharedStore, $fs2);


			// modify in store 1
			$s1->put('x', 12, 0);
			$s1->put('y', 23, 0);
			$s1->put('z', 34, 0);
			$s1->put('a', 99, 0);
			$s1->put('b', 111, 14);
			$s1->put('c', 222, 13);
			$s1->put('d', 100, 0);
			$s1->put('e', 100, 0);


			// modify in store 2
			$s2->flush();


			// read from store 1 - should not yet be synchronized
			$this->assertEquals(12, $s1->get('x'));
			$this->assertEquals(23, $s1->get('y'));
			$this->assertEquals(34, $s1->get('z'));
			$this->assertEquals(99, $s1->get('a'));
			$this->assertEquals(111, $s1->get('b'));
			$this->assertEquals(222, $s1->get('c'));
			$this->assertEquals(100, $s1->get('d'));
			$this->assertEquals(100, $s1->get('e'));


			// wait until local TTL of store 1 is expired
			sleep(2);


			// read values from s2 again, after TTL expire (all values changed in s2, should now be invalidated and therefore return null)
			$this->assertNull($s1->get('x'));
			$this->assertNull($s1->get('y'));
			$this->assertNull($s1->get('z'));
			$this->assertNull($s1->get('a'));
			$this->assertNull($s1->get('b'));
			$this->assertNull($s1->get('c'));
			$this->assertNull($s1->get('d'));
			$this->assertNull($s1->get('e'));


			$s2New = new LocalSynchronizedStore($d2, $this->sharedStore, $fs2);
			$this->assertNull($s2New->get('x'));
			$this->assertNull($s2New->get('y'));
			$this->assertNull($s2New->get('a'));
			$this->assertNull($s2New->get('b'));
			$this->assertNull($s2New->get('c'));
			$this->assertNull($s2New->get('d'));
			$this->assertNull($s2New->get('e'));

		}

		public function testReSyncAfterGlobalStateLoss() {

			$d1 = $this->createTempDir();
			$d2 = $this->createTempDir();

			$fs1 = new FileSystemWithLog();
			$fs2 = new FileSystemWithLog();

			// create store
			$s1 = new LocalSynchronizedStore($d1, $this->sharedStore, $fs1, true, 1);
			$s1->put('x', 12, 0);
			$s1->put('y', 23, 0);

			// destroy global state
			$this->sharedStore->flush();

			// values in store one should still exist (TTL not expired yet)
			$this->assertEquals(12, $s1->get('x')); // should be reset, because state is out of sync
			$this->assertEquals(23, $s1->get('y'));

			// wait until ttl is expired
			sleep(2);

			// create another store which should re-init a global state
			$s2 = new LocalSynchronizedStore($d2, $this->sharedStore, $fs2, true, 0);
			$s2->put('x', 1, 0);

			// validate values
			$this->assertNull($s1->get('x')); // should be reset, because state is out of sync
			$this->assertNull($s1->get('y')); // should be reset, because state is out of sync
			$this->assertEquals(1, $s2->get('x'));

		}

		public function testReSyncAfterIncrementalLogBroken() {

			$d1 = $this->createTempDir();
			$d2 = $this->createTempDir();

			$fs1 = new FileSystemWithLog();
			$fs2 = new FileSystemWithLog();

			// create store
			$s1 = new LocalSynchronizedStore($d1, $this->sharedStore, $fs1, true, 1, 'ss');
			$s1->put('x', 12, 0);
			$s1->put('y', 23, 0);


			// create another store which modifies store
			$s2 = new LocalSynchronizedStore($d2, $this->sharedStore, $fs2, true, 0, 'ss');
			$s2->put('x', 1, 0);
			$s2->put('y', 4, 0);

			$stateBase    = $this->sharedStore->get('ssstate_base');
			$stateVersion = $this->sharedStore->get("ssstate_{$stateBase}_version");

			$delVersion = $stateVersion - 1;

			$logData = $this->sharedStore->get("ssstate_{$stateBase}_{$delVersion}_log");
			if (empty($logData))
				$this->fail('Unexpected values in shared store. Test seams not to interact well with store implementation');

			$this->sharedStore->forget("ssstate_{$stateBase}_{$delVersion}_log");

			sleep(2);

			// validate values
			$this->assertNull($s1->get('x')); // should be reset, because state is out of sync
			$this->assertNull($s1->get('y')); // should be reset, because state is out of sync
			$this->assertEquals(1, $s2->get('x'));
			$this->assertEquals(4, $s2->get('y'));

		}
	}