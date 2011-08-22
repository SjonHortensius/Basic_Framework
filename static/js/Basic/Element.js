Basic.Element = new Class({
	selector: null,
	element: null,
	elements: null,

	initialize: function()
	{
		this.elements = $$(this.selector);

		if (0 == this.elements.length)
			return;

		this.handle(this.elements);
	},

	handle: function(elements)
	{
		elements.each(function(element)
		{
			this.element = element;
			this.handleElement(element);
		}.bind(this));
	},
});