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

Basic.include = function(src, f, dups)
{
	arguments.callee.done = arguments.callee.done || [];

	if (!dups && arguments.callee.done[src])
	{
		if (f)
			arguments.callee.done[src].addEvent('load', f);

		return;
	}

	var s = new Element('script', {type: 'text/javascript', src: src});
	if (f)
		s.addEvent('load', f);
	s.inject($(document.body));

	arguments.callee.done[src] = s;
};

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
				return this.checked ? this.value : '';

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

Class.refactor(Element, {
	fireEvent: function(type, e)
	{
		if ('undefined' != typeof e)
			return this.previous(type, e);

		var e = window.event;
		type = type || 'click';

		if (document.createEvent)
		{
			e = document.createEvent('HTMLEvents');
			e.initEvent(type, false, true);
		}

		e = new DOMEvent(e);
		e.target = this;

		return this.previous(type, e);
	}
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