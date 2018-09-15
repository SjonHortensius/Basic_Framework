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

		$this->setOption(Memcached::OPT_PREFIX_KEY, dechex(crc32(APPLICATION_PATH) + filemtime(APPLICATION_PATH .'/cache/')) .':');
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
	public function get($key, $cache_cb = null, $ttl = null)
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
	 * Overload the default @see Memcached::set - adds logging and exceptions
	 */
	public function set($key, $value, $expiration = null)
	{
		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->write($key);

		if (parent::set($key, $value, $expiration))
			return;

		$msg = ucwords(strtolower(parent::getResultMessage()));
		$exception = 'Basic_Memcache_'. str_replace(' ', '', $msg). 'Exception';
		throw new $exception('Cache responded with an error: %s', [$msg]);
	}

	/**
	 * Overload the default @see Memcached::add - adds logging and exceptions
	 */
	public function add($key, $value, $expiration = null)
	{
		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->write($key);

		if (parent::add($key, $value, $expiration))
			return;

		$msg = ucwords(strtolower(parent::getResultMessage()));
		$exception = 'Basic_Memcache_'. str_replace(' ', '', $msg). 'Exception';
		throw new $exception('Cache responded with an error: %s', [$msg]);
	}
}