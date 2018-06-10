# The Basic_Framework

This is a framework I've used for most projects I've developed over the years. It focuses on providing validated user-input,
and a powerful yet simple ORM, combined with a simple template parser and router. The key focus is performance.

## Validated user-input

User-input is wrapped by the `Userinput` class which is exposed through `Basic::$userinput`. Your project should never reference
any globals like `$_GET` / `$_POST` / `$_SERVER` since they are all untrusted. Instead you configure global inputs in your `config.ini`:

```ini
[Userinput.action]
source[superglobal] = REQUEST
source[key] = 0
maxLength = 16
default = index
```

This would expose `Basic::$userinput['action']` which has a maximum length of 16 characters, defaulting to `'index'`.

For action-specific inputs you define your configuration in your controller (which is called an `Action` since this framework uses
action-based controllers): The controller is choosen based on the global *action* userinput you defined in your `config.ini`.

```php
class MySite_Action_ListThings extends Basic_Action
{
	public $userinputConfig = [
		'page' => [
			'valueType' => 'integer',
			'source' => ['superglobal' => 'REQUEST', 'key' => 1],
			'default' => 1,
			'minValue' => 1,
			'maxValue' => 9,
		],
	];
}
```
This would expose `Basic::$userinput['page']` which contains an integer between 1 and 9. If the user passes an invalid value,
the framework would simply block the request from ever getting to your Action. More specifically, your `init()` will be
executed, but your `run()` won't.

------------------

Currently, any `UserinputValue` can use the following configuration options:
* `valueType` - internal php type to require
* `required` (boolean) - Actions will not `run()` without all required Values being passed. Any specificied additional
Values must also pass validation
* `inputType` (string) - used when asking the user for this value through a form. Valid values are any template listed in `Userinput/Type`,
 from either the framework or application
* `source` - (array) defines where this value comes from. Can contain any superglobal, where `POST` values will be shown in forms
and `REQUEST` is filled with the URL parts for this request
* `default` - the default value, presented to the user in forms but also returned to the application when no value was
specified by the user
* `description` - used in forms, to provide additional text relevant to this value
* `regexp` - a PCRE this value should conform to, tested *after* `preCallback` and `preReplace` are processed
* `values` - an array of allowed values. When 2 levels deep, will be presented as optgroups by `select.html`
* `preCallback` - a callback to process this value. Could be `serialize` or any custom method. Applied *before* validation
* `postCallback` - a callback to process this value. Applied *after* validation
* `preReplace` - an array (pattern => replacement, passed to preg_replace) of PCREs to apply *before* validation
* `postReplace` - an array (pattern => replacement, passed to preg_replace) of PCREs to apply *after* validation
* `mimeTypes` - for uploaded files - allows filtering on specific mime-types, see `Basic_UserinputValue::_handleFile`
* `options` - unvalidated array of key/value pairs which you can use in your application logic or templates

## Basic ORM

A basic ORM is provided by the `Entity` and `EntitySet` classes. You use these by extending them:

```php
class MySite_Page extends Basic_Entity
{
	protected static $_relations = [
		'creator' => MySite_User::class,
	];
	protected static $_numerical = ['viewCount'];
}
```

You define your actual properties in your database and they will become available when you retrieve the Entity. For example,
you could retrieve the title of your page by doing `MySite_Page::get($pageId)->title`; or retrieve the name of it's creator
by running `MySite_Page::get($pageId)->creator->name`.

Additional features:
* `::create(array)` - stores a new Entity in the database
* `::save(array)` / `delete()` - update or delete an Entity
* `::find('title = ?', [Basic::$userinput['pageTitle']])` - find all Entities matching the specified sql query
* `::setUserinputDefault()` - useful for *CRUD* actions, specifies all properties of Entity as default for current Userinput
* `::getRelated(MySite_Page::class)` - find all specified Entities with a relation to current object, eg. all pages a User
 has created: `MySite_User::get(1)->getRelated(MySite_Page::class)`

The second ORM feature is provided by `EntitySet` which you can also extend. For example:

```php
class MySite_PageSet extends Basic_EntitySet
{
	public function includeHistory()
	{
		$this->addJoin(MySite_PageVersion::class, "PageVersion.page = Page.id AND !PageVersion.deleted");
	}
}
```

This would enable you to do `$history = MySite_Page::find("name = ?", [Basic::$userinput['action']])->includeHistory();`.

Additional features:
* `::getPage(offset, pageSize)` - retrieve specified page in a list of results
* `getSingle` - use this when you need to enforce a single Entity from a query
* `getSubset` - allows chaining multiple filters, eg. `$admins->getSubset("active = ?", [true])`
* `getSuperset` - similar to `Entity::getRelated` but for a set, eg. all pages created by a EntitySet of admins:
 `$admins->getSuperset(MySite_Page::class, "Page.creator = User.id")`

## Basic template parser

I believe a template-parser provides a healty barrier to prevent developers from including too much logic in their templates,
since most of any processing should be done in the Action. This is why the Basic Template parser has a small feature-set:

* Globally, any variable output is *untainted*; currently `htmlspecialchars` for html and `json_encode` for json are applied
* Comments: `{!-- fix this --}`
  * are removed server-side before outputting
* echo public variables from the `Basic` namespace: `Welcome to {userinput['action']}`
  * allows using variables from controller/config or userinput.
  * you are responsible for proper access notation, eg.
    * `{userinput['index']}` for arrays
    * `{userinput->action->description}` for objects
  * statements without source-prefix will be fetched from the Action
    * `{currentUser->name}` will return `Action->currentUser->name`
* to circumvent the tainting, eg to output HTML, prefix with `*`: `{*currentPage->getHtml()}`
* execute generic PHP statement (note the trailing semicolon): `{print phpversion();}`
* small inline if-statements: `{if ($this->input->options['multiple'])}multiple{/}`

For larger if/else-statements or while / foreach loops, a block syntax is supported. Please note using proper indenting
(using tabs) is paramount or the ending will not be detected properly:

```html
{foreach ($this->input->values as $this->value => $this->description)}
	{if ($this->value->inputType == 'radio')}
		<input type="radio" name="test" id="test" value="{value}" />
		<label for="test">{description}</label>
	{:}
		<input type="text" name="test" id="test" value="{value}" />
	{/}
{/}
```

For if-statements the *else* part `{:}stuff` can be omitted.

### Templates for Forms

Based on the userinputConfig, the framework can automatically generate a form whenever parameters are missing or invalid.
This should simplify CRUD actions as you can have a simple Action for editing Entities:

```php
class MySite_Action_UpdateUser extends Basic_Action
{
	public $userinputConfig = [
		'id' => [
			'valueType' => 'integer',
			'required' => true,
			'source' => ['superglobal' => 'REQUEST', 'key' => 1],
		],
		'name' => ['valueType' => 'string', 'required' => true],
		'contact' => ['valueType' => 'string', 'inputType' => 'email'],
	];
	protected $_user;

	public function init()
	{
		$this->_user = MySite_User::get(Basic::$userinput['id']);
		$this->_user->setUserinputDefault();

		parent::init();
	}

	public function run()
	{
		$this->_user->save(Basic::$userinput->toArray(false));

		parent::run();
	}
}
```

To customize the form displayed to the user you can use templates, eg. to customize the `contact` input, the following
paths are checked:
* `Userinput/UpdateUser/Name/contact.html`
* `Userinput/UpdateUser/Type/email.html`
* `Userinput/UpdateUser/Input.html`
* `Userinput/Name/contact.html`
* `Userinput/Type/email.html`
* `Userinput/Input.html`

This allows overloading per Action or globally and per *name* or *inputType*. Any template not found in the application
can also be retrieved from the framework `templates/` directory.

## Router

As an Action based framework, the `userinput.action` is required, to determine which Action (controller) to pass the request to.
However, this routing can be customized by using a base class for your Actions and overloading the `Basic_Action::resolve`
method. This allows you to override the action, for example to forward any unknown action to a *page* action for a cms:

```php
class MySite_Action {
	public static function resolve(string $action, bool $hasClass, bool $hasTemplate): ?string
	{
		// If an Action class is defined, it is not a CMS page - so do not overwrite action
		if ($hasClass)
			return null;

		// Otherwise, default to the MySite_Action_Page class
		return 'page';
	}
}
```

There is also `getRoute` which is used to specify the form.action in generated templates.