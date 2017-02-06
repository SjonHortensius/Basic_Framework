<?php

class Basic_Memcache extends Memcached
{
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

		$this->setOption(Memcached::OPT_PREFIX_KEY, dechex(crc32(APPLICATION_PATH)). '::');
	}

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
}