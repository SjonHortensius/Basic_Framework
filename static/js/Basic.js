var Basic = new Class({
	version: '1.0',
	prefixes: ['Basic'],

	initialize: function(prefixes)
	{
		this.prefixes = prefixes.concat(this.prefixes);

		window.addEvent('domready', this.run.bind(this));
	},

	run: function()
	{
		this.prefixes.map(this._load, this);
	},

	_load: function($class, $parentName)
	{
		var $fullClass, $child, $parent;

		if ('string' == typeof $parentName)
		{
			$parent = eval($parentName);
			$fullClass = $parentName +'.'+ $class;
			eval('new '+ $fullClass);
		}
		else
		{
			$parent = window;
			$fullClass = $class;
		}

		for ($child in $parent[$class])
		{
			if (!$child[0].match(/^[A-Z]/))
				continue;

			if ('function' == typeof $parent[$class][$child])
				this._load($child, $fullClass);
		}
	}
});