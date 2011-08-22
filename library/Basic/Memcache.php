<?php

class Basic_Memcache extends Memcache
{
	protected $_prefix;

	public function __construct()
	{
		if (!isset(Basic::$config->Memcache->servers))
			$this->addServer('localhost');
		else
		{
			foreach (Basic::$config->Memcache->servers as $server)
				$this->addServer($server->host);
		}

		$this->_prefix = substr(md5(APPLICATION_PATH), -5). '::';

		$this->setCompressThreshold(5120, 0.2);
	}

	public function add($key, $var, $flag = 0, $expire = 0)
	{
		if ($var === false)
			throw new Basic_Memcache_CannotStoreBooleanFalse('Memcache cannot store a boolean:false');

		Basic::$log->start();

		$result = parent::add($this->_prefix . $key, $var, $flag, $expire);

		Basic::$log->end($key);

		return $result;
	}

	public function decrement($key, $value = 1)
	{
		return parent::decrement($this->_prefix . $key, $value);
	}

	public function delete($key)
	{
		Basic::$log->start();

		$result = parent::delete($this->_prefix . $key);

		Basic::$log->end($key);

		return $result;
	}

	public function get($key, $flag = 0)
	{
		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->start();

		$result = parent::get($this->_prefix . $key, $flag);

		if (false === $result)
			$result = null;

		if (!Basic::$config->PRODUCTION_MODE)
			Basic::$log->end($key .' > '. gettype($result));

		return $result;
	}

	public function increment($key, $value = 1)
	{
		return parent::increment($this->_prefix . $key, $value);
	}

	public function set($key, $var, $flag = 0, $expire = 0)
	{
		if ($var === false)
			throw new Basic_Memcache_CannotStoreBooleanFalse('Memcache cannot store a boolean:false');

		Basic::$log->start();

		$result = parent::set($this->_prefix . $key, $var, $flag, $expire);

		Basic::$log->end($key);

		return $result;
	}

	public function replace($key, $var, $flag = 0, $expire = 0)
	{
		if ($var === false)
			throw new Basic_Memcache_CannotStoreBooleanFalse('Memcache cannot store a boolean:false');

		Basic::$log->start();

		$result = parent::replace($this->_prefix . $key, $var, $flag, $expire);

		Basic::$log->end($key);

		return $result;
	}
}