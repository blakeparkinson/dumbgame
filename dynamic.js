/**
 * @modo.classes.Suggest
 * @requires document, modo, jQuery
 *
 * @description a constructor function which takes an object with parameters and wraps suggestion functionality into the elements passed in
 *
 * The whole idea of this widget is to bind functionality to your own mark up provided you follow basic conventions.  Your suggest box should take this form:
 * <div id="myContainer">   <input type="text" /> 
 * <div class="mySuggestionContainer">   <p class="someDefaultMessage"></p> <ul class="mySuggestions"></ul> </div>
 * </div>
 *
 * This widget DOES NOT handle send button functionality, that's up to you to determine how to handle. This also DOES NOT handle css, again thats up to you to take care of.
 * This strictly binds the necessary functionality.
 *
 * the elements parameter should be an object that looks like this:
 * 
 * {
 *      container: _$(myContainer),
 *      input: _$(input:text),
 *      list: _$(mySuggestionContainer)
 * }
 *
 * Options should look like this:
 * {
 *      invalidTypes : ["all"], // Recipient types that should not be displayed.
 *      validGIDsStr : "" // Comma delimited string of group-IDs for valid group recipients.
 * }
 */

modo.widgets.Suggest = function( elements, options )
{
    options = options || {};

    var instance        = this,
        container       = elements.container,
        isSnapshot      = container.hasClass('snapshot'),
        input           = elements.input,
        list            = elements.list,
        listul          = list.find('ul'),
        invalidTypes    = options.invalidTypes,
        validGIDsStr    = options.validGIDsStr,
        curReceivers    = [],
        cachedList      = new SearchableList({ indexFields : ["id", "type"], searchField : "name" }),
        selectedIdx     = 0,
        type            = modo.common.account.type,
        searchStage     = 0,
        t               = modo.common.translations,
        lastSearch      = '',
        _$              = $,
        PRIMARY_CONNECTIONS = "1",
        SECONDARY_CONNECTIONS = "2",
        INITIAL_WIDTH_TEXT = 25,
        xhr, xhrRecipients, page, general, timeout;

    var initialize = function(){
        
        general = modo.common.utilities.general;
        
        modo.common.Client.isIE8 || modo.common.Client.isIE7 ? page = document : page = window;     
        
        bindEvents();
    
    }

    var bindEvents = function(){
       
        if(type != 'STUDENT') input.bind('keydown', handleKeyDownPause).bind('keyup', handleKeyUp).bind('focus', onInputFocus);

        container.bind('click', onContainerFocus).on('click', '.suggest-receiver img', removeReceiver);

        container.parents('.pb-content').bind('keydown', checkTab);
        
        list.on('click', 'li', selectReceiver).on('mouseenter', 'li', highlight);
    }

    var onContainerFocus = function(e){
            
        e.stopPropagation();

        type == 'STUDENT' ? onInputFocus() : input.focus();

    }

    var hidePrompt = function(){
        
        _$(this).addClass('hidden');

    }

    var highlight = function(){

        _$(this).parent().find('li').removeClass('selected');
        _$(this).addClass('selected');

    }

    /**
     * Stop fetcing receivers
     */
    var _abortSearch = function() {        
        xhr && xhr.abort && xhr.abort();
        xhrRecipients && xhrRecipients.abort && xhrRecipients.abort();
    }
    
    var clearSearchState = function(){

        searchStage = 0;

        _abortSearch();

        input.val('').css({
            'width' : INITIAL_WIDTH_TEXT
        });  

        if (listul.children('li').length > 2) {
            listul.empty();
        }

        list.hide();
        list.removeClass('actively-searching');
    }

    var onInputFocus = function(e){

        var val = _$.trim(input.val());

        general.removePageClick();
        general.addPageClick();
        
        container.parent().addClass('dd-visible');

        if(type == 'STUDENT'){

            _$(window).bind('keydown', disableWindowKeyDown);

            container.addClass('focused');

            modo.common.utilities.general.addPageClick();
            modo.common.utilities.general.clickQueue.push(function(){

                container.removeClass('focused');
                _$(window).unbind('keydown', disableWindowKeyDown);

            });

            listul.removeClass('hidden');
            list.find('p').addClass('hidden');

            getSuggestions();

            return;

        }
        // reset the input box
        if (val === '') {
            clearSearchState();
        }
        
    }

    var handleKeyUp = function(e){

        var target = $(e.target),
            key = e.which,
            val = _$.trim(input.val());

        target.css({
            'width' : INITIAL_WIDTH_TEXT + (val.length * 15)
        });   

        list.find('p').addClass('hidden');

        if (val === '') {
            clearSearchState();
            return;
        }

        if(val !== lastSearch){

            // While we wait for the server results,
            // filter first by cache
            cachedSearchResults = cachedList.search(val, true);

            if (cachedSearchResults.length) {
                listul.empty();
                injectIntoReceiversList(cachedSearchResults);
            }
            else {
                listul.addClass('loading').empty();
            }

            clearTimeout(timeout);
            timeout = setTimeout(function() {

                listul.removeClass('hidden');
                getSuggestions();

            }, 250);

        }

    }

    var handleKeyDownPause = function(e){

        // If we're moving up and down through the list then don't 
        // allow the pos of the cursor to change in the input field
        if (e.which === 38 || e.which === 40)
            e.preventDefault();

        // Handle backspace right away because we want to know if the value
        // _before_ the keypress is 0. If so, we delete the selected receiver
        // to the left of the cursor
        if (e.which === 8) {
            handleKeyDown(e);
        }
        else {
            // Some KD code depends on getting the latest value from the input
            // so we need to wait a tick for the value to populate
            setTimeout(function() {
                handleKeyDown(e);
            });
        }

    }

    var handleKeyDown = function(e){

        var key = e.which,
            val = _$.trim(input.val());
 
        if (val.length === 0) {
            //clearSearchState();
//            return;
        }

        _$(page).unbind('keyup', onEnter);
        _$(page).unbind('click', onClick);

        if(type == 'STUDENT'){

            switch(key){

                case 38:
                    moveUp();
                    break;

                case 40:
                    moveDown();
                    break;

                case 13: case 9:
                    selectReceiverByEnter();
                    break;

            }

            return;
        }
        
        container.parent().addClass('dd-visible');
 
        if(key == 46){

            removeNewestReceiver();
    
        } else if(val.length < 1 || key === 8 && val.length < 1){

            switch(key){

                case 38: 
                    moveUp();
                    break;

                case 40:
                    moveDown();
                    break;

                case 13: case 9:
                    selectReceiverByEnter();
                    break;
    
                case 8:
                    if(val.length === 0){ 

                        removeNewestReceiver();

                    }else{

                        listul.addClass('hidden').empty();
                        list.find('p').addClass('hidden');

                    }
                    input.focus();
                    break;

                default: 
                    listul.addClass('hidden').empty();
                    list.find('p').addClass('hidden');
                    break;

            }

        }else{

            switch(key){

                case 38:
                    moveUp();
                    break;

                case 40:
                    moveDown();
                    break;

                case 13: case 9:
                    selectReceiverByEnter();
                    break;

            }
        }   
    }

    var removeNewestReceiver = function(){

        container.find('div.suggest-receiver:last').find('img').click();

        adjustReceiverInput();
    }

    var moveUp = function(){

        var sel = list.find('li.selected');

        //no receivers have been manually selected

        if(sel.length == 0){

            list.find('li:first').addClass('selected').find('a').focus();
            selectedIdx = 0;

        }else{

            if(sel.prev().length > 0){

                selectedIdx--;

                sel.prev().addClass('selected').find('a').focus();

            }else{

                selectedIdx = list.find('li').length;

                list.find('li:last').addClass('selected').find('a').focus();
        
            }

            sel.removeClass('selected');

        }

        input.focus();

    }

    var moveDown = function(){

        var sel = list.find('li.selected');

        //no receivers have been manually selected

        if(sel.length == 0){

            list.find('li:first').addClass('selected');

            selectedIdx = 0;

        }else{

            if(sel.next().length > 0){

                selectedIdx++;

                sel.next().addClass('selected').find('a').focus();

            }else{

                selectedIdx = 0;

                list.find('li:first').addClass('selected').find('a').focus();

            }

            sel.removeClass('selected');

        }

        input.focus();

    }

    var adjustReceiverInput = function(){
        var left = input.position().left;
        if (left === 0) {
            if (container.hasClass("has-receivers") === false)
                input.css("padding-left", "10px");
            else
                input.css("padding-left", "5px");
        }
        else {
            input.css("padding-left", "0px");
        }

    }

    //selects receivers when the enter key is hit

    var selectReceiverByEnter = function(){

        var sel = list.find('li.selected');
    
        if(type == 'STUDENT') _$(window).unbind('keydown', disableWindowKeyDown);

        if(sel.length > 0) sel.click();

        adjustReceiverInput();
    }

    //gets the possible receivers based on whats been entered in the text box

    var getSuggestions = function(){

        var model      = modo.State.get(),
            recipients_type = '',
            isPlanner  = model.navigation.view === 'planner',
            searchStr  = '',
            cachedSearchResults,
            viewTypeParam;

        searchStage = 1;

        selectedIdx = 0;

        if (isSnapshot) recipients_type = 'owner_group';

        if(modo.common.account.receivers && isPlanner || modo.common.account.receivers && type == 'STUDENT'){

            if(type !== 'STUDENT'){ // Teacher.

                sortReceivers(modo.common.account.receivers, PRIMARY_CONNECTIONS);

            }else{

                injectIntoReceiversList(modo.common.account.receivers, PRIMARY_CONNECTIONS);    
                //sortReceivers(modo.common.account.receivers, PRIMARY_CONNECTIONS);

            }

        }else{

            if(type == 'STUDENT')
                modo.search_str = "";

            if(isPlanner)
                viewTypeParam = "CALENDAR";

            _abortSearch();

            searchStr = _$.trim(input.val());
            if (searchStr === '') {
                clearSearchState();
                return;
            }
            if (searchStr === lastSearch) {
                return;
            }

            lastSearch = searchStr;

            // Calls search without going into extended members of groups
            xhrRecipients = _$.ajax({

                url: '/sharebox/ajax-get-possible-receivers',
                type: 'post',
                data: {

                    filter: {},
                    search_str: searchStr,
                    view_type:viewTypeParam,
                    ftm : "0",
                    check_owner: true,
                    recipients_type : recipients_type

                },
                success: function(data){

                    data = JSON.parse(data);

                    var possible_receivers = data.possible_receivers,
                        user_owns_groups = data.owns_groups;

                    sortReceivers(possible_receivers, PRIMARY_CONNECTIONS, user_owns_groups);
                    if (isSnapshot){
                        console.log(data);
                    }

                }

            });

            // Calls extended search
            xhr = _$.ajax({

                url: '/sharebox/ajax-get-possible-receivers',
                type: 'post',
                data: {

                    filter: {},
                    search_str: searchStr,
                    view_type:viewTypeParam,
                    ftm : "1" ,// fetch teacher members
                    check_owner: true,
                    recipients_type : recipients_type

                },
                success: function(data){

                    data = JSON.parse(data);

                    var possible_receivers = data.possible_receivers,
                        user_owns_groups = data.owns_groups;

                    sortReceivers(possible_receivers, SECONDARY_CONNECTIONS, user_owns_groups);

                }

            });

        }

    }


    /*
     * @description sorts through and prepares receivers
     * -if the user has under 200 receivers this gets called instead of the ajax
     * -call then passes on the sorted data to injectIntoReceiversList
     * @param data {} receivers that will be inputted
     * @param container [int] specifying PRIMARY or SECONDARY Connections
     * @param is_group_owner [bool] specifies whether user owns a group
     */
    var sortReceivers = function(data, container, is_group_owner){

        var val = input.val().toLowerCase(),
            d   = [],
            l   = data.length,
            invalidTypesStr, item;

        //matches the name to the search string

        if(invalidTypes){
//            invalidTypesStr = invalidTypes.join(); // HACK:: To get around IE<9 lack of Array.indexOf().

            for(var i = 0; i < l; i++){ // Strip invalid types & add recipients with names containing search-string.

                item = data[i];
                var itemType = item.type;

                var isGroup = itemType==="group";
                var checkGroupAgainstValidGroups = (validGIDsStr && isGroup);
                var isInValidGroups = validGIDsStr.indexOf(item.id) !== -1;
                var notInvalidType = modo.common.utilities.array.indexOf( invalidTypes, itemType ) === -1;

                if(( checkGroupAgainstValidGroups && isInValidGroups ) // We have a list of group-IDs, it's a group & is in the IDs.
                    && notInvalidType )
                        d.push( item );

                else if( ! isGroup && notInvalidType  )
                    d.push( item );
            }
        }else{
            for(var i = 0; i < l; i++){ // Add recipients
                d.push(data[i]);
            }
        }

        cachedList.add(d, { container: container }, false);

        cachedSearchResults = cachedList.search(val, true);
        injectIntoReceiversList(cachedSearchResults, container, is_group_owner);

    }

    /*
     * @description injects fields "all connections" and "all my groups"
     * @param data {} receivers that will be inputted
     * @param is_group_owner [bool] specifies whether user owns a group
     */
    
    var _injectStaticReceivers = function(data, is_group_owner) {

        if (is_group_owner){
            data.unshift({"type":"all-groups","id":1,"name":t._('all-my-groups') });
        }
        if (!isSnapshot){
            data.unshift({"type":"connections","id":0,"name":t._('all-my-connections') });
        }
        return data;
    }
    /*
     * @description injects list of receivers
     * @param data {} receivers to inject
     * @param container [int]
     * @param is_group_owner [bool] specifies whether user owns a group
     * @param
     */

    var injectIntoReceiversList = function(data, container, is_group_owner){
        // if(input.val() == '' && type != 'STUDENT') return;

        var html   = '',
            hex    = '#3784d3',
            rec    = ' ' + curReceivers.join(' ') + ' ',
            img    = '',
            model  = modo.State.get(),
            activeInstance = model.postbox.activeInstance,
            firstTier2Li,
            collisionDetect,
            collisionDetectBool = false,

            // on planner view sometimes model.postbox[activeInstance] is undefined - this takes care of that
            boxStatus = model.postbox[activeInstance] ? model.postbox[activeInstance].boxStatus : '',

            classAttrs,
            t;

        if (type === 'TEACHER') {
            data = _injectStaticReceivers(data, is_group_owner);
        }

        for( var i = 0, dataLen = data.length; i < dataLen; i++ ){

            // In case we have cached results, prevent populating the search results
            // with duplicates
            collisionDetectBool = false;
            collisionDetect = listul.find(".suggest-" + data[i].id);

            collisionDetect.each(function() {
                if ($(this).attr('type') == data[i].type) {
                    collisionDetectBool = true;
                    return false;
                }
            });

            if (collisionDetectBool === true)
                continue;

            if(activeInstance == 'home'){

                if(boxStatus == 'assignment' && data[i].type == 'group_parents' && activeInstance == 'home')
                    continue;

                if(boxStatus == 'quiz'){

                    if(data[i].type == 'group_parents' || data[i].type == 'school_vip' || data[i].type == 'district_vip')
                        continue;

                }

            }

            if(rec.indexOf(' ' + data[i].id + '-' + data[i].type + ' ') != -1)
                continue;
            
            if (typeof data[i].extra === "undefined") {
                data[i].extra = {
                    container : container
                };
            }

            html = cachedList.renderView(data[i]);

            if (data[i].extra.container == PRIMARY_CONNECTIONS) {
                firstTier2Li = listul.find("li.tier2:eq(0)");
                if (firstTier2Li.length)
                    _$(html).insertBefore(firstTier2Li);
                else
                    listul.append(html);
            }
            else {
                listul.append(html); 
            }
        }

        _highlightreceiver();

        searchStage++;
        
        if(searchStage >= 2 && type !== 'STUDENT'){
            list.addClass('actively-searching');
        }

        if(searchStage === 3){

            list.removeClass('actively-searching');

            if (listul.children().length === 0){
                list.find('p.no-receivers').removeClass('hidden');
                listul.removeClass('loading').addClass('hidden');           
                return;
            }
            _highlightreceiver();
        }

        list.show();
        listul.removeClass('hidden').removeClass('loading');
    }

    /**
      * Highlight 
      * @return {void}
      */
    var _highlightreceiver = function() {
        var selected = listul.children("li.selected");
        if (selected.length > 0) {
            selected.removeClass("selected");
        }
        if (listul.children("li").length > 2) {
            listul.find("li:nth-child(3)").addClass("selected");
        } else {
            listul.find("li:first-child").addClass("selected");    
        }
    }
 
    //when a receiver is clicked create the receivers container in the suggestions container

    var selectReceiver = function(e){

        if(e && e.stopPropagation) e.stopPropagation();
        
        var id   = _$(this).attr('uid'),
            html = '';

        if(e && e.ctrlKey){

            _$(this).addClass('selected');
            _$(page).bind('keyup', onEnter);
            _$(page).bind('click', onClick);
            return;

        }

        if(container.hasClass('error')) container.removeClass('error');

        if(type != 'STUDENT'){

            curReceivers.push(id+'-'+_$(this).attr('type'));
            html = '<div class="suggest-receiver" id="suggest-receiver-'+id+'" style="background-color: '+_$(this).attr('hex')+';" uid="'+id+'" type="'+_$(this).attr('type')+'"><span>'+_$(this).find('span:last').text()+'</span><img width="7" height="9" src="/images_v2/icons/recipient_x.png" alt="" /></div>';

            container.find('div.suggest-receiver').length > 0 ? container.find('div.suggest-receiver:last').after(html).removeClass('focused') : container.prepend(html).removeClass('focused');


        }else{

            var img = "";

            container.find('div.student-receiver').remove();

            _$(this).find('img').length > 0 ? img = '<img width="20" height="20" src="' + _$(this).find('img').attr('src') + '" alt="" />' : img = '<div class="swatch" style="background-color: '+_$(this).attr('hex')+';"></div>';
            
            html = '<div class="student-receiver" id="suggest-receiver-'+id+'" uid="'+id+'" hex="' + _$(this).attr('hex')+ '" type="'+_$(this).attr('type')+'">'+img+'<span>'+_$(this).find('span').text()+'</span></div>';
            
            container.prepend(html).removeClass('focused').addClass('has-receiver active');

        }

        if(type != 'STUDENT') input.val('');
    
        if(e != 'proxy'){ 

            container.parent().removeClass('dd-visible');;
            input.parent().addClass('has-receivers');

        }

        if (isSnapshot){
            var grade   = parseInt(_$(this).attr('grade')),
                subject = _$(this).attr('subject'),
                pb      = container.closest('.snapshot-content-input');
                pb.addClass('has-receivers');
            if (grade !== undefined){
                console.log(grade);
                console.log(typeof grade);
                if (grade < 3) grade = 3;
                pb.find("#grades option:selected").attr('value', grade);
                switch (grade){
                    case 3:
                        var grade_text = '3rd';
                        break;
                    case 4:
                        grade_text = '4th';
                        break;
                    case 5:
                        grade_text = '5th'
                        break;
                    case 6:
                        grade_text = '6th';
                        break;
                    case 7:
                        grade_text = '7th';
                        break;
                    case 8:
                        grade_text = '8th';
                        break;
                    case 9:
                        grade_text = '9th';
                        break;
                    case 10:
                        grade_text = '10th';
                        break;
                    case 11:
                        grade_text = '11th';
                        break;
                    case 12:
                        grade_text = '12th';
                        break;
                    default:
                        grade_text = 'Grade...';
                }

                pb.find('.btn.dropdown-toggle span:first-child').text(grade_text);
            }

        }

        input.focus();

    }

    //remove the receiver from the suggestions container

    var removeReceiver = function(e){

        e.stopPropagation();

        var id  = this.parentNode.getAttribute('uid') + '-' + this.parentNode.getAttribute('type'),
            par = this.parentNode.parentNode;

        for(var i = 0; i<curReceivers.length; i++){

            if(curReceivers[i] == id){

                curReceivers.splice(i, 1);
                break;
            }

        }
        
        par.removeChild(this.parentNode);

        if(curReceivers.length == 0){ 

            _$(par).find('input.postbox-send-input').blur();
            input.parent().removeClass('has-receivers');

        }

        _$(window).click();

        adjustReceiverInput();
        input.focus();
        
    }

    instance.resetReceivers = function () {

        container.find('div.suggest-receiver, div.student-receiver').remove();
        curReceivers = [];
        
        if(type == 'STUDENT') container.find('input.postbox-send-input').removeClass('hidden');

    };

    /**
     * @return {jQuery} A jQuery object referencing elements representing the selected recipients
     * - the recipient elements have custom attributes containing data such as user-id & recipient type.
     *
     * NOTE: We can probably deprecate this if we can standardize the format for recipients data.
     */
    instance.getSelectionJq = function () {
        return container.find( ".suggest-receiver" );
        /*var recipients = [];
        container.find( ".suggest-receiver").each(function(){

            var sJq = $( this );
            recipients.push({type:sJq.attr("type"), id:sJq.attr("uid")});
        });
        return recipients;*/
    };

    var onEnter = function(e){

        if(e.which == 13) handleKeyDown({which: 13});

    }

    var onClick = function(e){

        if($(e.target).parents('.dd').length > 0) return;

        $(page).unbind('click', onClick);
        $(page).unbind('keyup', onEnter);       

    }

    var checkTab = function(e){
        
        if(e.which == 9 && !_$(e.target).hasClass('postbox-send-input')){

            container.find('p').addClass('hidden');
            container.find('ul').addClass('hidden');

        }

    }

    var disableWindowKeyDown = function(e){

        e.preventDefault();
        e.stopPropagation();

        handleKeyDown(e.which);

    }

    instance.addReceiver = function(receiver){

        var type = modo.common.account.type;

        if(!receiver.hex) receiver.hex = '#3784d3';

        if(type == 'TEACHER') curReceivers.push(receiver.id+'-'+receiver.type);
           
        if(type == 'STUDENT'){

            html = '<div class="student-receiver" id="suggest-receiver-'+receiver.id+'" uid="'+receiver.id+'" hex="' + receiver.hex + '" type="'+receiver.type+'"><div class="swatch" style="background-color: '+receiver.hex+';"></div><span>'+receiver.name+'</span></div>';            

        }else{

            html = '<div class="suggest-receiver" id="suggest-receiver-'+receiver.id+'" style="background-color: '+receiver.hex+';" uid="'+receiver.id+'" type="'+receiver.type+'"><span>'+receiver.name+'</span><img width="7" height="9" src="/images_v2/icons/recipient_x.png" alt="" /></div>';

        }

        container.find('div.suggest-receiver').length > 0 ? container.find('div.suggest-receiver:last').after(html) : container.prepend(html);
        container.removeClass('focused').addClass('active has-receivers');

    }

    instance.destroy = function(){

        if(type != 'STUDENT') input.unbind('keydown', handleKeyDownPause).unbind('keyup', handleKeyUp).unbind('focus', onInputFocus);

        container.unbind('click', onContainerFocus).off();

        container.parents('.pb-content').unbind('keydown', checkTab);

        list.off();

        delete instance.resetReceivers;
        delete instance.getSelectionJq;

        initialize = bindEvents = removeReceiver = onContainerFocus = selectReceiver = createReceiversList = sortReceivers = getSuggestions = onInputFocus = handleKeyDown = handleKeyDownPause = removeNewestReceiver = moveUp = moveDown = removeReceiver = selectReceiverByEnter = onEnter = highlight = disableWindowKeyDown = null;

        instance = _$ = container = input = list = curReceivers = selectedIdx = general = type = elements = page = xhr = timeout = handleKeyUp = null;
    
    }

    initialize();

    /*
     * A list that can be added to and searched
     *
     * @param options Object { searchField, indexFields }
     */
    function SearchableList(options) {

        var list = {},
            listLength = 0,
            objectKeys = modo.common.utilities.general.objectKeys;

        var sl = {
            init : function() {
                sl.clear();
            },

            clear : function() {
                list = {};
                listLength = 0;
            },

            getList : function() {
                return list;
            },

            getListItem : function(id) {
                return list[id];
            },

            /*
             * Adds or overwrites items to the list
             *
             * @param items Array
             * @param extra Object extra data we want to add to each item
             * @param overwrite Boolean unfortunate hack, but I need it for edmodo :-)
             */
            add : function(items, extra, overwrite) {
                
                var l = items.length;

                for(var i = 0; i<l; i++){

                    if (!list[items[i][options.indexFields[0]] + items[i][options.indexFields[1]]] || overwrite === true) {
                        items[i].extra = extra;
                        list[items[i][options.indexFields[0]] + items[i][options.indexFields[1]]] = items[i];
                    }

                }
                
                listLength = objectKeys(list).length; 
            },

            // @param term String search term
            search : function(term, sort) {
                var results = [];

                // escape the term for regex use
                term = term.replace(/[\-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|]/g, "\\$&");

                var regex = new RegExp(term + '|(.*)' + term + '|' + term + '(.*)|(.*)' + term + '(.*)', 'i'),
                    result;
                for (var item in list) {
                    result = regex.exec(list[item][options.searchField]);
                    if (result) {
                        // store the pos in the name where the result was found.
                        // note that result[1] is a very specific by-product of
                        // the formulation of the regex's parentheses
                        list[item].searchResultPos = result[1] ? result[1].length : 0;
                        results.push(list[item]);
                    }
                }

                if (sort === true)
                    results = this.sortResults(results);

                return results;
            },

            sortResults : function(results) {
                var sorted = results.sort(function(a, b) {
                    if (a.searchResultPos < b.searchResultPos)
                        return -1;
                    if (a.searchResultPos > b.searchResultPos)
                        return 1;

                    // From this point on, the search position is the same,
                    // so test by name
                    if (a.name < b.name)
                        return -1;
                    if (a.name > b.name)
                        return 1;
                    return 0;
                });
                return sorted;
            },

            renderView : function(data) {
                if (data.renderedView)
                    return data.renderedView;

                var hex = '#666', img = '', type,
                    no_avatar = false;

                if (data.hex) {
                    hex = data.hex;
                    img = '<span class="receiver-swatch" style="background-color: ' + data.hex + '"></span>';
                } else if (data.thumb) {
                    hex = '#3784d3';
                    img = '<img height="20" width="20" src="' + data.thumb + '" alt="" />';
                } else {
                    no_avatar = true;
                }

                type = data.type;

                var classAttrs = [
                    type+"Type", 
                    "tier" + data.extra.container, 
                    "highlight", 
                    "suggest-" + data.id
                ];

                if (no_avatar) {
                    classAttrs.push('has-no-avatar');
                }
                if (isSnapshot){
                    if (data.subject){
                        var subject_tag ='"subject="'+data.subject;
                    }
                    if (data.grade_level){
                        var grade_level ='"grade="'+data.grade_level;

                    }
                }

                data.renderedView = ['<li class="', classAttrs.join(" "), '" id="suggest-',
                        data.id, '" hex="', hex, '" type="', type, subject_tag, grade_level, '" uid="',
                        data.id, '"', '><a href="javascript:;">', img,
                        '<span>', data.name, '</span></a></li>'].join('');

                return data.renderedView;
            }
        };

        sl.init();
        return sl;
    }
}
