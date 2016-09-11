<?php

class Basic_Memcache
{
	private $_m;

	public function __construct()
	{
		$this->_m = new Memcached;

		if (isset(Basic::$config->Memcache->servers))
		{
			foreach (Basic::$config->Memcache->servers as $server)
				$this->_m->addServer($server->host, $server->port);
		}
		else
			$this->_m->addServer('localhost', 11211);

		$this->_m->setOption(Memcached::OPT_PREFIX_KEY, dechex(crc32(APPLICATION_PATH)). '::');
	}

	public function __call($m, $p)
	{
		return call_user_func_array([$this->_m, $m], $p);
	}

	public function get($key, $cache_cb = null, $ttl = null)
	{
		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->start();

		$result = $this->_m->get($key, null, $cas_token);

		if (false === $result)
		{
			if (!isset($cache_cb))
			{
				if (!Basic::$config->PRODUCTION_MODE)
					Basic::$log->end($key .' | NOT_FOUND');

				throw new Basic_Memcache_ItemNotFoundException('Requested item was not found in the cache');
			}

			$result = call_user_func($cache_cb);
			$this->_m->set($key, $result, $ttl);
		}

		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->end($key .' > '. gettype($result));

		return $result;
	}
}