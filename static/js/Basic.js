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

Element.implement({
	getValue: function()
	{
		switch (this.type)
		{
			case 'select-one':
				if (-1 == this.selectedIndex)
					return '';

				return this.options[ this.selectedIndex ].value;

			case 'checkbox':
			case 'radio':
				var options = this.form.getElements('input[name="'+ this.name +'"]'), value;

				options.each(function (option)
				{
					if (option.checked)
						value = option.value;
				});

				return value;

			default:
				return this.value;
		}
	},

    toggleClass: function(className, state)
    {
    	if (null == state)
    		state = !this.hasClass(className);

        return state ? this.addClass(className) : this.removeClass(className);
    },
});

String.implement({
	pad: function (length, pad)
	{
		var str = '' + this, pad = pad || ' ';
		while (str.length < length)
			str = pad + str;

		return str;
	},
});