<?php

class Basic_Log
{
	public static $queryCount = 0;
	protected $_startTime;
	protected $_logs = [];
	protected $_started = [];

	public function __construct()
	{
		$this->_startTime = microtime(true);
	}

	public function start(string $method = null): void
	{
		if (Basic::$config->PRODUCTION_MODE)
			return;

		if (!isset($method))
			$method = self::getCaller();

		// Start the log by showing what the initial memory usage is
		if (empty($this->_logs))
			array_push($this->_logs, array(0, 'Basic::bootstrap', microtime(true)-$this->_startTime, round(memory_get_usage() / 1024)));

		array_push($this->_started, array($method, microtime(true), memory_get_usage()));
	}

	public function write(string $text): void
	{
		$this->start(self::getCaller());
		$this->end($text);
	}

	public function end(string $text = null): void
	{
		if (Basic::$config->PRODUCTION_MODE)
			return;

		if (empty($this->_started))
			throw new Basic_Log_NotStartedException('Basic_Log::end() was called for but we didn\'t start yet');

		list($method, $time, $memory) = array_pop($this->_started);

		array_push($this->_logs, array(count($this->_started), $method, microtime(true) - $time, round((memory_get_usage() - $memory) / 1024), $text));
	}

	public function getStatistics(): array
	{
		return array(
			'time' => microtime(true) - $this->_startTime,
			'queryCount' => self::$queryCount,
			'memory' => (int)round(memory_get_usage()/1024),
		);
	}

	public function getTimers(): string
	{
		if (Basic::$config->PRODUCTION_MODE)
			return '';

		$timers = $counters = [];
		foreach ($this->_logs as $logEntry)
		{
			list(, $method, $time, ) = $logEntry;

			$counters[ $method ]++;
			$timers[ $method ] += $time;
		}

		arsort($timers, SORT_NUMERIC);

		$output = '';
		foreach ($timers as $name => $time)
		{
			$output .= '<dt>'. $name. '</dt><dd><b>'. number_format($time*1000, 2) . '</b> ms for <b>'. $counters[$name] .'</b> call';

			if ($counters[$name] > 1)
				$output .= 's ('. number_format(($time / $counters[$name]) * 1000, 2) .' ms per call)</dd>';
		}

		return '<pre><dl>'. $output .'</dl></pre>';
	}

	public function getLogs(): string
	{
		$output = [];

		foreach ($this->_logs as $logEntry)
		{
			list($indenting, $method, $time, $memory, $text) = $logEntry;

			array_push($output, sprintf('%s %5.2f ms %+5d KiB <b>%s</b> %s', htmlspecialchars(str_pad(str_repeat(">", $indenting), 5, '_', STR_PAD_RIGHT)), 1000*$time, $memory, $method, htmlspecialchars($text)));
		}

		return implode('<br/>', $output);
	}

	public static function getCaller(): string
	{
		$trace = debug_backtrace();

		return $trace[2]['class'] .'::'. $trace[2]['function'];
	}

	public static function getSimpleTrace(): string
	{
		$trace = [];
		$_trace = array_reverse(array_slice(debug_backtrace(), 2));

		foreach ($_trace as $point)
		{
			if (isset($point['class']))
				$line = $point['class'] . $point['type'] . $point['function'];
			else if (isset($point['file']))
				$line = basename($point['file']) .':'. $point['function'];

			array_push($trace, $line);
		}

		return implode(' > ', $trace);
	}
}