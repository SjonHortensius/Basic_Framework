Basic.Validator = new Class({
	Implements: [Events, Options],
	options: {},
	submitEvent: null,
	rowSelector: null,
	registered: false,

	initialize: function(form, rowSelector, options)
	{
		if (!form)
			return;

		this.form = $(form);
		this.rowSelector = rowSelector || 'tr';
		this.setOptions(options);

		this.register();
	},

	fields: function()
	{
		return this.form.getElements(this.rowSelector + ' input, '+ this.rowSelector +' select, '+ this.rowSelector +' textarea');
	},

	refresh: function()
	{
		this.fields().each(this.validate, this);
	},

	register: function()
	{
		if (this.registered)
			return false;

		this.fields().each(this._register, this);
		this.refresh();

		this.submitEvent = this.form.addEvent('submit', this._onSubmit.bind(this));

		this.registered = true;
	},

	_register: function(element)
	{
		element.addEvent('change', this.validate.bind(this));
		element._validator = this;

		// Special values: -1 for unknown script source, 0 for server-side added class
		element.setRequired = function(isRequired, byElement)
		{
			if (null == isRequired)
				isRequired = true;

			byElement = (null != byElement) ? byElement.uid : -1;

			if (isRequired)
			{
				if (!this.requiredBy)
					this.requiredBy = [byElement];
				else if (!this.requiredBy.contains(byElement))
					this.requiredBy.push(byElement);
			} else
				this.requiredBy.erase(byElement);

			this.required = (this.requiredBy.length > 0);
			this._validator.validate({target: this});
		};
	},

	validate: function(e)
	{
		var element = (null != e.target) ? e.target : e, parent = element.getParent(this.rowSelector);

		// Initial run? Then store any server errors
		if (!this.registered && element.hasClass('invalid'))
			element.set('blacklisted', element.getAttribute('value'));

		if (!element._validator)
			this._register(element);

		if (this._validate(element))
			element.removeClass('invalid').addClass('valid');
		else
			element.removeClass('valid').addClass('invalid');

		// Now toggle the parent based on all the children		
		if (0 == parent.getElements('.invalid').length)
			parent.removeClass('invalid').addClass('valid');
		else
			parent.removeClass('valid').addClass('invalid');		
	},

	_validate: function(element)
	{
		if (typeof element.validate == 'function')
			return element.validate();
		else if ((null != element.getProperty('blacklisted')) && element.getValue() == element.get('blacklisted'))
			return false;
		else if (!element.required && element.getValue() == '')
			return true;
		else if ((null != element.getAttribute('validate')))
		{
			var regexp = element.getAttribute('validate');

			// Convert the regexp to javascript
			var flags = regexp.replace(/^~(.+)~(.*)$/, '$2');
			regexp = regexp.replace(/^~(.+)~(.*)$/, '$1');

			if ('string' != typeof element.getValue())
				return false;

			return element.getValue().test(new RegExp(regexp, flags));
		}
		else if (element.required)
			return element.getValue() != '';
		else
			return true;
	},	

	_onSubmit: function(e)
	{
		this.form.addClass('submit-attempted');
		this.fields().each(this.validate, this);

		var missing = this.form.getElements(this.rowSelector +'.invalid');

		if (missing.length > 0)
		{
			this.fireEvent('onError', ['invalid_input']);
			missing[0].getElements('input, textarea, select')[0].focus();
			e.stop();
		}
		else if (this.form.hasClass('ajax') && 0 == this.form.getElements('input[type=file]').length)
		{
			e.preventDefault();
			this.form.send();
		}
	}
});