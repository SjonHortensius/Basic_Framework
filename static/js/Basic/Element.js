Basic.Element = new Class({
	selector: null,
	element: null,
	elements: null,
	_toggleBehaviour: false,

	initialize: function()
	{
		this.elements = $$(this.selector);

		if (0 == this.elements.length)
		{
			// If we have no match, make sure Basic re-runs us for every run() (we reset in handle())
			if ('once' == this.initBehaviour && !this._toggleBehaviour)
			{
				this._toggleBehaviour = true;
				this.initBehaviour = 'refresh';
			}

			return;
		}

		this.refresh(this.elements);
	},

	refresh: function(elements)
	{
		this.elements = elements || $$(this.selector);

		if (0 != this.elements.length)
			return this.handle(this.elements);
	},

	handle: function(elements)
	{
		if (this._toggleBehaviour)
			this.initBehaviour = 'once';

		elements.each(function(element)
		{
			this.element = element;
			this.handleElement(element);
		}.bind(this));
	},
});