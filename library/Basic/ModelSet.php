<?php

class Basic_ModelSet implements ArrayAccess, Iterator, Countable
{
	protected $_set = array();

	public function __construct(array $array = array())
	{
		foreach ($array as $idx => $model)
		{
			$model->__set = $this;
			$this->_set[ $idx ] = $model;
		}
	}

	public function getSimpleList($property = 'name', $key = 'id')
	{
		$output = array();
		foreach ($this->_set as $model)
			$output[ $model->{$key} ] = $model->{$property};

		return $output;
	}

	public function getSingle()
	{
		if (count($this->_set) != 1)
			throw new Basic_ModelSet_NoSingleResultException('There are `%s` results', array(count($this->_set)));

		return current($this->_set);
	}

	public function offsetExists($offset){		return array_key_exists($offset, $this->_set);	}
	public function offsetGet($offset){			return $this->_set[ $offset ];					}
	public function offsetSet($offset, $value){	return $this->_set[ $offset ] = $value;			}
	public function offsetUnset($offset){		unset($this->_set[ $offset ]);					}

    public function rewind(){	reset($this->_set);					}
    public function current(){	return current($this->_set);		}
    public function key(){		return key($this->_set);			}
    public function next(){		return next($this->_set);			}
    public function valid(){	return false !== $this->current();	}

    public function count(){	return count($this->_set);	}
}