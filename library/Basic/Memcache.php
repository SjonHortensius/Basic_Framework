<?php

class Basic_Memcache extends Memcached
{
	/**
	 * Use servers from configuration to create a memcache-connection.
	 * Sets a namespace prefix to avoid conflicts
	 */
	public function __construct()
	{
		parent::__construct();

		if (isset(Basic::$config->Memcache->servers))
		{
			foreach (Basic::$config->Memcache->servers as $server)
				$this->addServer($server->host, $server->port);
		}
		else
			$this->addServer('localhost', 11211);

		$this->setOption(Memcached::OPT_PREFIX_KEY, dechex(crc32(APPLICATION_PATH)) .':');
		$this->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
		$this->setOption(Memcached::OPT_TCP_NODELAY, true);
	}

	/**
	 * Overload the default @see Memcached::get - adds logging and callback functionality
	 *
	 * @param string $key Key of item to retrieve
	 * @param null $cache_cb Callback that gets called when value is not found. Should return the actual value
	 * @param null $ttl Time-to-live value used when using the value from the callback
	 * @return mixed
	 */
	public function get(string $key, callable $cache_cb = null, int $ttl = null): mixed
	{
		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->start();

		$result = parent::get($key);

		if (false === $result)
		{
			if (!isset($cache_cb))
			{
				if (!Basic::$config->PRODUCTION_MODE)
					Basic::$log->end($key .' | NOT_FOUND');

				throw new Basic_Memcache_ItemNotFoundException('Requested item was not found in the cache');
			}

			$result = call_user_func($cache_cb);
			$this->set($key, $result, $ttl);
		}

		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->end($key .' > '. gettype($result));

		return $result;
	}

	/**
	 * Extends @see Basic_Memcache::get, adds locking to prevent cold-cache-stampedes. This makes sure a cache-miss
	 * will only be refreshed on one client, while other clients wait until that client is done and the cache is filled
	 *
	 * @param string $key Key of item to retrieve
	 * @param null $cache_cb Callback that gets called when value is not found. Should return the actual value
	 * @param null $ttl Time-to-live value used when using the value from the callback
	 * @return mixed
	 */
	public function lockedGet($key, $cache_cb = null, $ttl = null)
	{
		$tries = 5;

		if (!class_exists('Basic_Memcache_Locked'))
			eval('class Basic_Memcache_Locked {}');

		try
		{
			// don't pass CB because we want our custom logic to handle misses
			while (--$tries > 0 && ($result = $this->get($key)) instanceof Basic_Memcache_Locked)
			{
				error_log(__METHOD__ .' - waiting for unlock of '. $key);
				sleep(1);
			}

			if ($tries == 0)
			{
				error_log(__METHOD__ .' - timeout, removing lock for '. $key);
				$this->delete($key);

				$result = $this->get($key, $cache_cb, $ttl);
			}
		}
		catch (Basic_Memcache_ItemNotFoundException $e)
		{
			if (!isset($cache_cb))
				throw $e;

			try
			{
				// add() only succeeds when entry isn't present
				$this->add($key, new Basic_Memcache_Locked, $ttl);
			}
			catch (Basic_Memcache_ItemAlreadyExistsException $e)
			{
				// key was already added - verify it's not locked
				// don't pass CB because another thread is apparantly refreshing
				return $this->lockedGet($key);
			}

			ignore_user_abort(true);

			// we have obtained the lock, execute CB and overwrite lock with result
			$result = call_user_func($cache_cb);
			$this->set($key, $result, $ttl);
		}

		return $result;
	}

	/**
	 * Overload the default @see Memcached::set - adds logging and exceptions
	 */
	public function set($key, $value, $expiration = null): bool
	{
		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->write($key);

		if (parent::set($key, $value, $expiration))
			return true;

		$msg = ucwords(strtolower(parent::getResultMessage()));
		$exception = 'Basic_Memcache_'. str_replace(' ', '', $msg). 'Exception';
		throw new $exception('Cache responded with an error: %s', [$msg]);
	}

	/**
	 * Overload the default @see Memcached::add - adds logging and exceptions
	 */
	public function add(string $key, mixed $value, int $expiration = null): bool
	{
		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->write($key);

		if (parent::add($key, $value, $expiration))
			return true;

		$msg = ucwords(strtolower(parent::getResultMessage()));
		$exception = 'Basic_Memcache_'. str_replace(' ', '', $msg). 'Exception';
		throw new $exception('Cache responded with an error: %s', [$msg]);
	}
}