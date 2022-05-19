<?php

class Basic_Log
{
	public static $queryCount = 0;
	protected $_logs = [];
	protected $_started = [];

	/**
	 * Log the start of an event, shown in the debug-logs
	 *
	 * @param string|null $method Name of event that starts
	 */
	public function start(string $method = null): void
	{
		if (Basic::$config->PRODUCTION_MODE)
			return;

		if (!isset($method))
			$method = self::getCaller();

		// Start the log by showing what the initial memory usage is
		if (empty($this->_logs))
			array_push($this->_logs, [0, 'Basic::bootstrap', microtime(true)-$_SERVER['REQUEST_TIME_FLOAT'], round(memory_get_usage() / 1024), '']);

		array_push($this->_started, [$method, microtime(true), memory_get_usage()]);
	}

	/**
	 * Log an event without start/end, but only a description
	 *
	 * @param string $text Name of the event that occured
	 */
	public function write(string $text): void
	{
		$this->start(self::getCaller());
		$this->end($text);
	}

	/**
	 * Log the end of the last start()ed event
	 *
	 * @param string|null $text Additional description
	 */
	public function end(string $text = null): void
	{
		if (Basic::$config->PRODUCTION_MODE)
			return;

		if (empty($this->_started))
			throw new Basic_Log_NotStartedException('Basic_Log::end() was called for but we didn\'t start yet');

		list($method, $time, $memory) = array_pop($this->_started);

		array_push($this->_logs, [count($this->_started), $method, microtime(true) - $time, round((memory_get_usage() - $memory) / 1024), $text]);
	}

	/**
	 * Return quick statistics, currently time / queryCount and memory
	 *
	 * @return array
	 */
	public function getStatistics(): array
	{
		return [
			'time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
			'queryCount' => self::$queryCount,
			'memory' => (int)round(memory_get_usage()/1024),
		];
	}

	/**
	 * Return Html list of all events that had start/end calls, grouped by event
	 *
	 * @return string
	 */
	public function getTimers(): string
	{
		if (Basic::$config->PRODUCTION_MODE)
			return '';

		$timers = $counters = [];
		foreach ($this->_logs as [$indenting, $method, $time, $memory, $text])
		{
			if (!isset($counters[ $method ]))
				$counters[ $method ] = $timers[ $method ] = 0;

			$counters[ $method ]++;

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

	/**
	 * Return Html list of all events including time, memory and descriptions
	 *
	 * @return string
	 */
	public function getLogs(): string
	{
		$output = [];

		foreach ($this->_logs as [$indenting, $method, $time, $memory, $text])
			array_push($output, sprintf('%s %5.2f ms %+5d KiB <b>%s</b> %s', htmlspecialchars(str_pad(str_repeat('>', $indenting), 5, '_', STR_PAD_RIGHT)), 1000*$time, $memory, $method, htmlspecialchars($text?? '')));

		return implode('<br/>', $output);
	}

	public static function getCaller(): string
	{
		$trace = debug_backtrace();

		return $trace[2]['class'] .'::'. $trace[2]['function'];
	}

	/**
	 * Get a very simple backtrace consisting of class/method or file/method pairs
	 *
	 * @param Exception|null $e Use given exception instead of current position
	 *
	 * @return string
	 */
	public static function getSimpleTrace(Exception $e = null): string
	{
		$_trace = $e ? $e->getTrace() : array_slice(debug_backtrace(), 2);

		$trace = [];
		foreach (array_reverse($_trace) as $point)
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