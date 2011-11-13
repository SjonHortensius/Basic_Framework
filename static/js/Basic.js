var Basic = new Class({
	version: '1.0',
	prefixes: ['Basic'],
	instance: {},

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
			var behaviour = 'replace';

			if (this.instance[$fullClass] && this.instance[$fullClass].initBehaviour)
				behaviour = this.instance[$fullClass].initBehaviour;
//console.log(behaviour, $fullClass);
			switch (behaviour)
			{
				case 'once':
					if (this.instance[$fullClass])
						break;
				// FALLTHROUGH
				case 'replace':
					this.instance[$fullClass] = eval('new '+ $fullClass);
				break;
				
				case 'refresh':
					this.instance[$fullClass].refresh();
				break;
				
				default:
					alert('unknown initBehaviour: '+ behaviour);
			}
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