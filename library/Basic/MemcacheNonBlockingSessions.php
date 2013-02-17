<?php

class Basic_MemcacheNonBlockingSessions extends Memcached implements SessionHandlerInterface
{
	protected $_pid;

	public function __construct()
	{
		$this->_pid = getmypid();
		parent::__construct();
	}

	public static function register()
	{
		session_set_save_handler(new self);
	}

	public function open($path, $name)
	{
		$this->addServer('localhost', 11211);
		$this->setOption(Memcached::OPT_PREFIX_KEY, 'session:'. $name .':');

		return true;
	}

	public function close()
	{
		return true;
	}

	public function read($id)
	{
		while ($locked = $this->get($id . ':LOCK') && $locked != $this->pid)
			usleep (1);

		$this->set($id . ':LOCK', $this->pid, ini_get('session.gc_maxlifetime'));

		return $this->get($id);
	}

	public function write($id, $data)
	{
		if ($this->get($id . ':LOCK') !== $this->pid)
			return;

		$this->set($id, $data, ini_get('session.gc_maxlifetime'));
		$this->set($id . ':LOCK', false, ini_get('session.gc_maxlifetime'));

		return true;
	}

	public function destroy($id)
	{
		return $this->delete($id);
	}

	public function gc($max)
	{
	}
}