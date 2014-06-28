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

		$this->setOption(Memcached::OPT_PREFIX_KEY, crc32(APPLICATION_PATH). '::');
	}

	public function get($key, $cache_cb = null, &$cas_token = null, &$udf_flags = null)
	{
		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->start();

		$result = parent::get($key, $cache_cb, $cas_token, $udf_flags);

		if (false === $result)
		{
			if (!Basic::$config->PRODUCTION_MODE)
				Basic::$log->end($key .' | NOT_FOUND');

			throw new Basic_Memcache_ItemNotFoundException('Requested item was not found in the cache');
		}

		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->end($key .' > '. gettype($result));

		return $result;
	}
}