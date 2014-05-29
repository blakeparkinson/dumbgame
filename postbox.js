/* @class modo.classes.PostBox.components.UI
 * @requires document, modo, jQuery
 *
 * @description controls general postbox interface
 */

modo.classes.PostBox.components.UI = function(rootNode, superClass){

	var instance = this,
	    mode     = modo.common.account.type,
	    m        = modo.system.Mediator,
	    t        = modo.common.translations,
	    getModel = modo.State.get,
	    setModel = modo.State.set,
	    options  = null,
	    panels   = null,
        string = 'numbers',
        match = [];

    /*var suggestions = rootNode.find('div.snapshot-suggest-container');
    //var standards =$.getJSON("js_v2/libraries/standards.json");
    //console.log(standards);
    $.getJSON("js_v2/libraries/standards.json", function(data) {
        for (var key in data){
            if (data.hasOwnProperty(key)){
                if (data[key].subcategory.indexOf(string) > -1){
                    match.push(data[key]);
                }
            }
            /* for (var i = 0; i < data.length;){
             console.log(data[i]);
             }
        }
        if (match){
            var ul = '<ul></ul>';
            suggestions.append(ul);
            console.log(match);

            console.log(match.length);
            ul = suggestions.find('ul');
           for( var i = 0, matchLen = match.length; i < matchLen; i++ ){
                suggestions.append('<li>sup</li>');
            }
        }

        // data is a JavaScript object now. Handle it as such

    });



}*/




    _$       = $;


    var initialize = function(){

		var model = modo.State.get();

		options  = rootNode.find('ul#postbox-options').find('li');
		panels   = rootNode.find('div.pb-content');




		bindEvents();

		if(mode == 'STUDENT' && model.groups.id > 0) instance.resetAll();
        var string = 'number';


	var bindEvents = function(){

		var model = getModel(),
		    view  = model.navigation.view;

        rootNode.on('focus', 'textarea, input:text', onFocus).on('blur', 'textarea,input:text', onBlur).on('keydown', '.postbox-send-input', onKeydown).on('blur', '.poll-answer-container textarea', onAnswerBlur).on('click', '.postbox-clear', clearPostBox);

        // fix to pt# 37269315 - try again for pt# 45084349 - Edmodo toolbar moves down page on iPad when creating a note
        rootNode.on('blur', '.postbox-send-input', function(){
            setTimeout(function(){scrollTo(0,0);},100);
        });

		if(mode == 'TEACHER') rootNode.on('click', '.post-message', openPostBox);
		
        rootNode.on('mouseenter', 'input:file', onMouseEnter).on('mouseleave', 'input:file', onMouseLeave);

        rootNode.on('keydown', '.pb-content', tabCheck); //temporary tab fix for accessibility, should be removed when we come up with a better tab fix

        rootNode.on('click', '.invite-others-feature i.invite-others', initiateInviteOthers)

	}

    var initiateInviteOthers = function(event) {
        $(document).trigger('invite-others', $(event.target));
    }

    var onMouseEnter = function(){

        _$(this).parent().find('a.postbox-file').addClass('hovered');

    }

    var onMouseLeave = function(){

        _$(this).parent().find('a.postbox-file').removeClass('hovered');

    }

	/**
	 * Capture keydown events
	 */
	var onKeydown = function(event) {
		var ele, button;
		switch (event.which) {
			// get the TAB key
			case 9:
				var ele = $(event.target).parents('.post-box-send');
				if (ele.length > 0) {
					button = ele.find('.btn')[0];
					if (button) {
						button.focus();
						event.preventDefault();
					}
					
				}
				break;	
		}
	};

	var onFocus = function(){

		var model = getModel();

		model.postbox.activeInstance = superClass.name;			
	
	    _$(this).parents('.postbox-selected-content').find('div.pb-content').addClass('hidden');
		
        _$(this).parents('.pb-content').attr('class', 'pb-content clearfix');

		setModel(model);

	}

	var onBlur = function(){

		if(_$(this).hasClass('postbox-send-input')){

			if(_$(this).parent().find('.suggest-receiver').length == 0 &&_$(this).val() == '') _$(this).val(_$(this).attr('dflt_text'));

		}else{

			if(_$(this).val() == '') _$(this).val(_$(this).attr('dflt_text'));

		}

	}

	this.hideAllErrors = function(){

		rootNode.find('div.multi-error').addClass('hidden');

	}

	this.resetAll = function(){
		
		var model = modo.State.get(),
            s     = superClass.components.Suggest;

		rootNode.find('textarea, input:text').val('').removeAttr('style');
        rootNode.find('div.placeholder-container').removeClass('active has-receivers error');
		
        rootNode.find('.postbox-selected-content').removeClass('is-expanded-for-invite-others');

        rootNode.find('div.suggest-receiver').remove();
		
		rootNode.find('ul.attachments').removeClass('visible').find('img.delete').click();
        rootNode.find('div.multi-error, div.multi-warning').addClass('hidden'); 

        rootNode.find('a.postbox-send').removeClass('disabled');

        if(mode != 'STUDENT'){

			rootNode.find('a.poll-answer-remove').click();

			rootNode.find('div#postbox-quiz-container').removeClass('hidden');

			rootNode.find('div#loaded-quiz-post-container').addClass('hidden');

			rootNode.find('a#add-quiz-to-gradebook').removeClass('selected');
			
			if(model.groups.id != 0 && model.groups.active[model.groups.id]){

				rootNode.find('div.postbox-send-input-container').prepend('<div id="suggest-receiver-'+model.groups.id+'" class="suggest-receiver" type="group" uid="'+model.groups.id+'" style="background-color: ' + model.groups.active[model.groups.id].hex + '"><span>' + model.groups.active[model.groups.id].title + '</span><img width="7" height="9" src="//assets.edmodo.com/images_v2/icons/recipient_x.png" alt="" /></div>');

			}
			
            rootNode.find('li.scheduled-time').text('').removeAttr('time');
	
		}else{

			rootNode.find('div.student-receiver').remove();

			if(model.groups.id != 0 && model.groups.active[model.groups.id]){

				rootNode.find('div.postbox-send-input-container').prepend('<div id="suggest-receiver-' + model.groups.id+'" class="student-receiver" type="group" hex="'+model.groups.active[model.groups.id].hex+'" uid="'+model.groups.id+'"><div class="swatch" style="background: '+model.groups.active[model.groups.id].hex+'"></div><span>'+model.groups.active[model.groups.id].title+'</span></div>');

				rootNode.find('span.postbox-send-input').addClass('hidden');
			
            }else{

				rootNode.find('span.postbox-send-input').show();

			}

			if(model.navigation.view != 'assignment'){ 
               
                var s = rootNode.find('div#postbox-note-content').hasClass('short') ? ' short' : '';
 
                rootNode.find('div#postbox-note-content').attr('class', 'pb-content clearfix' + s);

            }

			rootNode.find('div.suggestion-container').find('ul').addClass('hidden');

		}

        for(var n in s){ 

            if(s[n] && s[n].resetReceivers) s[n].resetReceivers();
            
        }

        _$('#am-id').remove();

	}

	this.hidePostBox = function(){
        
		rootNode.parent().addClass('hidden');

	}

	this.showPostBox = function(){

		rootNode.parent().removeClass('hidden');

	}

	this.adjustPostBoxToFilterType = function(){

		var model = getModel(),
		    k     = model.stream.filter;

		if(model.navigation.view == 'publisher' || model.navigation.view == 'school' || model.navigation.view == 'district' || modo.common.account.type == 'PUBLISHER') return;

		if(model.stream.groupSubFilter) k = model.stream.groupSubFilter;

		if(model.stream.assignmentSubFilter) k = model.stream.assignmentSubFilter;

		if(mode == 'STUDENT'){

            if(model.groups.active[model.groups.id] && model.groups.active[model.groups.id].read_only == '1'){

                _$('#postbox').addClass('readonly');                
                _$('#stream-content').addClass('readonly');

            }else{

			    instance.resetAll();
                _$('#postbox').removeClass('readonly');
                _$('#stream-content').removeClass('readonly');

                if(k == 'feeds' || k == 'by_teachers' || k == 'by_me' || k == 'direct' || k == 'recent_replies'){

                    disablePostBox();

                }else{

                    enablePostBox();

                }

            }

			return;

		}
	
        if(mode == 'ADMINISTRATOR'){

            k == 'everyone' ? instance.showPostBox() : instance.hidePostBox();

            return;

        }
	
		if(k == 'polls') k = 'poll';
		if(k == 'quizzes') k = 'quiz';
		if(k == 'assignments') k = 'assignment';
	
		rootNode.find('div.post-message').removeClass('visible');
        
        switch(k){

			case 'by_students': case 'by_me': case 'direct': case 'connections': case 'by_teachers': case 'feeds': case 'recent_replies': case 'due_assignment':
                disablePostBox();
				break;

			case 'assignment': case 'alert': case 'poll': case 'quiz':
				model.stream.searchActive ? instance.hidePostBox() : instance.showPostBox();

                options.parent().removeClass('disabled');

                _$('#postbox-' + k).click();

                enablePostBox();
                
                options.parent().addClass('disabled');
	
                if(model.postbox.activeInstance == 'home'){

					model.postbox.home.boxStatus = k;
					modo.State.set(model);
	
				}
				break;

			default:
				model.stream.searchActive ? instance.hidePostBox() : instance.showPostBox();
			
                panels.each(function(){

                    if(!_$(this).hasClass('sending-box')) _$(this).addClass('short');

                });

                rootNode.find('div#postbox-quiz-content').removeClass('short');				

                options.parent().removeClass('disabled');

                enablePostBox();

                rootNode.find('div.pb-content').addClass('hidden');   

				if(model.postbox.activeInstance == 'home') rootNode.find('div#postbox-' + model.postbox.home.boxStatus + '-content').removeClass('hidden');

		}		
		
		// load quiz in postbox when modo.State.get().postbox has quiz_id
		if(modo.classes.PostBox.instances.home && modo.classes.PostBox.instances.home.components.Quiz && modo.State.get().postbox.activeInstance == 'home'){
	        
            modo.classes.PostBox.instances.home.components.Quiz.loadSelectedQuiz();
		
        }

	}

	var openPostBox = function(){

		var type = _$(this).attr('id').replace('-post', '');

		_$(this).removeClass('visible');

		_$('#postbox-' + type + '-content').removeClass('short').addClass('visible');

	}
 
    var onAnswerBlur = function(){

        var val      = _$(this).val(),
            multi    = _$(this).parents('.pb-content').find('div.multi-error'),
            errShown =  !multi.hasClass('hidden'),
            answers  = _$(this).parents('#poll-questions').find('textarea'),
            ctr      = 0;
        
        if(val == '' && errShown){

            _$(this).parent().addClass('error');

        }

        if(errShown){

            answers.each(function(){

                if(_$(this).val() != '') ctr++;

            });

            if(ctr > 1){

                var visible = false;

                multi.find('p.poll-answer-error').addClass('hidden');
                multi.find('p').each(function(){

                    if(!_$(this).hasClass('hidden')) visible = true;

                });

                if(!visible) multi.addClass('hidden');

            }else{

                multi.find('p.poll-answer-error').removeClass('hidden');

            }

        }

    }

    var clearPostBox = function(){

        var par = _$(this).parents('.pb-content'),
            s   = superClass.components.Suggest;

        par.find('textarea, input').each(function(){

            _$(this).val('');
            _$(this).parent().removeClass('focused active has-receivers error');
            _$(this).parent().find('div.suggest-receiver').remove();

        });

        par.find('div.multi-error').addClass('hidden');

        for(var n in s) s[n].resetReceivers();

    }

    var disablePostBox = function(){

        rootNode.find('div.disabled-mask').remove();
        rootNode.append('<div class="disabled-mask"></div>');
        rootNode.find('div.disabled-mask').height(rootNode.height()).width(rootNode.width());

    }

    var enablePostBox = function(){

        rootNode.find('div.disabled-mask').remove();

    }

    var tabCheck = function(e){
       return; 
        if(e.which === 9 && _$(e.target).hasClass('postbox-send-input')){ 
            
            _$(e.target).parent().removeClass('busy active');
            _$(e.target).parents('.pb-content').find('a.postbox-send').focus();

        }

    }

	this.destroy = function(){

		rootNode.off();

		delete instance.hideAllErrors;
		delete instance.resetAll;
		delete instance.hidePostBox;
		delete instance.showPostBox;
		delete instance.adjustPostBoxToFilterType;

		initialize = _$ = bindEvents = onFocus = onBlur = clearPostBox = tabCheck = null;

		rootNode = openPostBox = instance = superClass = options = panels = m = t = mode = getModel = setModel = onMouseEnter = onMouseLeave = onAnswerBlur = disablePostBox = enablePostBox = null;

	}

	initialize();

}
