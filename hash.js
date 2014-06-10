/* @class modo.system.ViewManager
 * @requires modo, document, jQuery
 *
 * @description controls handling view changes
 */

modo.define('system.ViewManager', function ViewManager(){

	this.PAGE_TITLE_PREFIX = 'Edmodo | ';

	var instance = this,
	    _$       = $,
	    m        = modo.system.Mediator,
	    parser, views, router, renderer;

	this.initialize = function(){

        if(modo.system.Location.module != 'home') return;

		parser   = instance.URLParser;
		views    = instance.Views;
		router   = instance.Router;
		renderer = instance.Renderer;

		bindEvents();
        
        if(instance.onload){ 
            
            instance.onload();
            delete instance.onload;
        
        }

        if(modo.NewUser) router.newUser = true;

	}

	var bindEvents = function(){

		$(window).hashchange(parser.parse);

		delete instance.checkView;

	}

	this.checkView = function(){

        if(!instance.enabled) return;

		var hash = modo.system.Location.hash;

		views = instance.Views;

		if(hash.length > 0){

			var n = location.hash.split('/')[0],
			    v = instance.fetchView(n);

			if(!modo.common.account.id){

				location.href = location.hash.replace(/#/g, '');
				return;

			}

			modo.State.navigation.view = n;
			modo.State.stream.target = n;

			if (v && v.type == 1 && 'PARENT' != modo.common.account.type) return;

			modo.common.Layout.TopBar.showLoadingMessage();
			modo.system.ViewManager.URLParser.parse();

		}

	}

	this.fetchView = function(view){

		var l = views.length;

		for (var i = 0; i < l; i++) {

			if (views[i].name == view) return views[i];

		}

		return false;

	}

	/**
	 * Get the action corresponding
	 * @return {String}
	 */
	this.getController = function() {

		var hash = modo.system.Location.hash;

		if (hash) {
			if (hash.indexOf != -1) {
				hash = hash.split('?')[0];
			}
			return (hash.split('/')[0] || null);
		}
	}

	/**
	 * Get the action corresponding
	 * @return {String}
	 */
	this.getActions = function() {

		var hash = modo.system.Location.hash;

		if (hash) {
			if (hash.indexOf != -1) {
				hash = hash.split('?')[0];
			}
			return hash.split('/').slice(1);
		}
	}


	this.setPageTitle = function(title){

		document.title = instance.PAGE_TITLE_PREFIX + title;

	}

	this.onHashChange = function(hardRefresh){

        if(!instance.enabled) return;

        if(hardRefresh === true) instance.hardRefresh = true;

        _$(this).trigger('route:start')
        console.log(_$(this));
		parser.parse();

	}

	this.hashChanging     = false;
	this.params           = {};
	this.type             = 'POST';
	this.modules          = [];
	this.XHR              = [];
	this.oldView          = false;
	this.newView          = '';
	this.cache            = {};
	this.managerInstance  = false;
    this.enabled          = true;
    this.hardRefresh      = false;

});
