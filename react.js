/**
 * @class modo.classes.stream.UI.Reactions
 *
 * @description controls reactions functionality in stream
 */

modo.define('classes.stream.UI.Reactions', function Reactions(){

	var instance     = this,
	    opened       = false,
        _$           = $,
        m, general, container, type, translations;

	this.initialize = function(){

		container    = '<div class="stream-reactions-container"><div class="panelarrow"><div></div></div><div class="inner submenu loading"></div></div>';
        general      = modo.common.utilities.general;
        type         = modo.common.account.type;
        m            = modo.system.Mediator;
		translations = modo.common.translations;

		m.to('modo.classes.facebox', 'register', [{

            name: 'showAllReactions',
            title: translations['reactions'],
            template: ' '

        }]);

		bindEvents();

	}

	var bindEvents = function(){

        _$('body').on('click', '.message-reaction', toggleReactionsMenu).on('click', '.view-all-reactions-btn', showAllReactions).on('click', '.reaction-selector', selectReaction).on('click', '.message-reaction-single-icon', oneClickReaction);

	}

    /*
     * @description toggles reactions menu into view
     * @param e [event] the event
     */

	var toggleReactionsMenu = function(e){

		e.stopPropagation();

        var t               = _$(this),
            id              = modo.classes.stream.getMessageData(t.parents('.message')).message_id,
            has_reactions   = t.data('hasreact');
            container       = ['<div class="stream-reactions-container">',
                                    '<div class="panelarrow '+ (has_reactions ? "wide" : "narrow") + '" >',
                                        '<div>',
                                            '</div>',
                                                '</div>',
                                                    '<div class="inner submenu loading">',
                                                        '</div>',
                                                            '</div>'].join('');

        if(!id) id = t.parents('.message').attr('id').replace('fmsg-', '');

        if(!t.hasClass('dd-visible')){

            general.hideAllMenus();
            t.addClass('dd-visible').append(container);

            general.addPageClick();

        }else{

            general.hideAllMenus();
            return;
                
        }

        modo.Analytics.pixel(null, 'reactions', 'toggle-reactions', {});

        dispatchGetReactions(id);

        modo.common.utilities.general.clickQueue.push(function(){

            t.find('div.stream-reactions-container').remove();

        });

	}

    /*
     * @description populates the reaction container with the content returned from the server
     * @param data [JSONString] the response from the server including markup
     */

	var populateReactions = function(data, id){
            
        var container = _$('div.stream-reactions-container');
        
        container.find('div.inner').removeClass('loading').html(JSON.parse(data).html);

	}

    /*
     * @description intializes the one click reaction
     * grabs the message_id and sets the reaction code and sends it off
     */
    var oneClickReaction = function(){
        var post     = _$(this).parents('.message'),
            id     = modo.classes.stream.getMessageData(post).message_id,
            code   = 1;

        dispatchOneClickReaction(id, code, post);

    }
    /*
     * @description selects reaction and dispatches xhr
     */

	var selectReaction = function(){

        var code   = parseInt(_$(this).attr('code')),
            id     = modo.classes.stream.getMessageData(_$(this).parents('.message')).message_id,
            cls    = '',
		    update = false,
		    c      = '',
            par    = _$(this).parents('.message-reaction');

		_$('#eTooltip').removeAttr('style');

		if(!id) id = _$(this).parents('.message').attr('id').replace('fmsg-', '');

		if(_$(this).parents('ul').hasClass('disabled')) update = true;

        type == 'TEACHER' || type == 'PUBLISHER' ? cls = getTeacherCls(code) : cls = getStudentCls(code);
        modo.Analytics.pixel(null, 'reactions', 'select-reaction-from-options', {reaction_code: code});

        dispatchSetReaction(id, code, update);

        /*par.removeClass('default').find('a:first').attr('class', type.toLowerCase() + '-reaction reaction ' + cls);*/
        /* temporary change */
        par.find('a:first').attr('class', 'has-react');

	}

    /*
     * @description shows facebox with all reactions to the message
     */

	var showAllReactions = function(e){

        e.stopPropagation();

		var d = m.to('modo.classes.facebox', 'modify', ['showAllReactions']),
	   	    r,
	   	    h = 0;
        
        d.template = _$('#tpl-all-reactions').html();
	
        _$('#stream-reactions-container').removeClass('visible');

        m.to(

            {name: 'modo.classes.facebox', fn: 'register', params: [d]},
            {name: 'modo.classes.facebox', fn: 'show',     params: ['showAllReactions']}

        );

        var fb = _$('#facebox');

        // 20 is the left-right margin total
        var ulWidth = ($("#all-reactions li:first-child").outerWidth()+20) * fb.find("#all-reactions > ul > li").length;
        fb.find("#all-reactions > ul").width(ulWidth);
		
	}

    /*
     * @description gets reaction class for teacher to be used in post footer
     * @param code [int] the reaction type
     */

	var getTeacherCls = function(code){

		switch(code){

            case 1:
                return 'outstanding-react';

            case 2:
                return 'striking-react';

            case 3:
                return 'admirable-react';

            case 4:
                return 'youcandoit-react';

            case 5:
                return 'goodguess-react';

            case 6:
                return 'betterluck-react';

            case 7:
                return 'failing-react';

            case 8:
                return 'poor-react';

            case 9:
                return 'lazy-react';

        }

	}

    /*
     * @description get student reaction class to be used in the post footer
     * @param code [int] the reaction code
     */

	var getStudentCls = function(code){

		switch(code){

            case 10:
                return 'awesome-react';

            case 11:
                return 'likeit-react';

            case 12:
                return 'interesting-react';

            case 13:
                return 'tough-react';

            case 14:
                return 'nottaught-react';

            case 15:
                return 'moretime-react';

            case 16:
                return 'bored-react';

            case 17:
                return 'needhelp-react';

            case 18:
                return 'lostinterest-react';

        }

	}

    /*
     * @description dispatches xhr to get reaction data and markup for a post
     * @param id [int/string] the message id
     */

	var dispatchGetReactions = function(id){

        _$.ajax({

            url: '/insights/ajax-get-reactions',
            type: 'post',
            data: {

                message_id: id

            },
            success: function(data){

                populateReactions(data, id);

            }

        });

    }

    /*
     * @description sets reaction for a message
     * @param id [int/string] the message id
     * @param reaction [int] the reaction code
     * @param update [boolean] dont' update the current shown reaction
     */

    var dispatchSetReaction = function(id, reaction, update){

        _$.ajax({

            url: '/insights/ajax-set-reaction',
            type: 'post',
            data: {

                message_id: id,
                reaction: reaction

            }

        });

		var node = _$('#fmsg-' + id).find('li.message-reaction'),
            span = node.find('span:first'),
		    val  = 0;
        console.log(node);

        if(node.length > 0){

            val = parseInt(node.find('span').text()) + 1;
            console.log(val);

            if(node.hasClass('hidden')){

                var t = translations['reaction'];

                if(!t) t = 'Reaction';

                node.removeClass('hidden').html('<span>' + val + '</span> ' + t);

            }else{

                var t = translations['reactions'];

                if(!t) t = 'Reactions';

                if(!update) span.html( val  + ' ' + t);

            }

        }else{

            _$('#fmsg-' + id).find('div.message-footer-shadow').append(instance.template.get('reactions-total', {total: 1}));

        }

    }

    /*
     * @description sets default reaction for a message
     * @param id [int/string] the message id
     * @param reaction [int] the reaction code
     * @param post [object] the post container surrounding the message id
     */
    var dispatchOneClickReaction = function(id, reaction, post){

        var node = post.find('li.message-reaction-single-icon'),
            has_reacted = node.data('has-reacted');

        if (has_reacted){

            //flag used to determine whether the user has already reacted, set back to 0
            node.data('has-reacted',0);
            substractOneClickReaction(post)
        }

        else {

            //flag used to determine whether the user has already reacted, set back to 1
            node.data('has-reacted',1);
            addOneClickReaction(post)

        }
        _$.ajax({

            url: '/insights/ajax-set-reaction',
            type: 'post',
            data: {

                message_id: id,
                reaction: reaction,
                can_unreact: true //special param to let server know that it may need to delete reaction in case of unreacting

            }

        });

    }

    /*
     * @description subtracts a reaction from the current reaction count
     * @param post [object] the post container surrounding the message id
     */
    var substractOneClickReaction = function(post){
        var span =  post.find('li.message-reaction-single-icon').find('span'),
            react = post.find('li.message-reaction-single-icon').find('a'),
            count = parseInt(span.data('count')) -1;
        span.data('count',count);
        if (count < 1){

            //no longer show the reaction count or the blue Ed icon
            span.addClass('hidden');
            react.removeClass('has-react').addClass('default-react');
        }
        else{

            //decrease the count
            span.html(' (' + count + ')');
            react.removeClass('has-react').addClass('default-react');
        }
        modo.Analytics.pixel(null, 'reactions', 'one-click-reaction-subtract', {});

    }

    /*
     * @description adds a reaction from the current reaction count
     * @param post [object] the post container surrounding the message id
     */
    var addOneClickReaction = function (post){
        var span =  post.find('li.message-reaction-single-icon').find('span'),
            react = post.find('li.message-reaction-single-icon').find('a'),
            count = parseInt(span.data('count')) +1;
        span.data('count',count);
        if(span.hasClass('hidden')){

            //HACK to manually set the count to 1 if there are no reactions yet
            count = 1;
            span.removeClass('hidden');
        }
        //render the updated count
        span.html(' (' + count + ')');
        if (react.hasClass('default-react')){

            //render Ed icon in blue
            react.removeClass('default-react').addClass('has-react');
        }
        modo.Analytics.pixel(null, 'reactions', 'one-click-reaction-add', {});

    }



 });
