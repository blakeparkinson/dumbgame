/* @class modo.classes.PostBox.components.Snapshot
 * @requires document, modo, jQuery
 *
 * @description controls snapshot functionality
 */

modo.classes.PostBox.components.Snapshot = function(rootNode, superClass){

    var instance = this,
        mode     = modo.common.account.type,
        m        = modo.system.Mediator,
        t        = modo.common.translations,
        getModel = modo.State.get,
        options  = null,
        panels   = null,
        pbContent = null,
        _$       = $;

    var initialize = function(){

        var model = modo.State.get();

        options  = rootNode.find('ul#postbox-options').find('li'),
        pbContent = rootNode.find('#postbox-snapshot-content'),
        panels   = rootNode.find('div.pb-content');

        rootNode.find('.selectpicker').selectpicker();

        bindEvents();

        if(mode == 'STUDENT' && model.groups.id > 0) instance.resetAll();

        m.to('modo.classes.facebox', 'register', [{
            name: 'snapshotOptional',
            title: 'Add a Note',
            template: modo.Template.get('snapshot-optional')
        }]);


    }

    var bindEvents = function(){

        var model = getModel(),
            view  = model.navigation.view;


        rootNode.one('click', '#postbox-snapshot', populateStandardsDropdown);

        pbContent.on('keyup', '.postbox-send-input-container.snapshot', lengthenContainer);
        pbContent.on('blur', '.postbox-send-input-container.snapshot', checkContainer);

        pbContent.on('keyup', '.snapshot-dropdown', lengthenStandardContainer);


    }

    var gatherStandards = function(content){
        var standards = content.find('.statement-code'),
            l = standards.length,
            standard_ids = [];
        for(var i = 0; i < l ; i++){
            standard_ids.push(standards.eq(i).attr('id'));
        }
        return standard_ids
    }

    var gatherReceivers = function(content){
        var snap_content = content.find('.snapshot-content-input'),
            receivers = snap_content.find('div.suggest-receiver'),
            l = receivers.length;
        var groups = [];

        for(var i = 0; i < l ; i++){
            groups.push(receivers.eq(i).attr('uid'));
        }

        return groups
    }

    var gatherSubject = function(container){
        var active = container.find('.btn-group .btn-primary.active'),
            subject   = active. attr('subject');
        return subject
    }

    var gatherGradeLevel = function(container){
        var active = container.find("#grades option:selected"),
            grade   = active.attr('value');

        return grade
    }

    var lengthenStandardContainer = function(e){
        var content = _$(this).closest('.snapshot-content-input'),
            standards = content.find('.statement-code'),
            key = e.which,
            l = standards.length;
        console.log(standards);
        if (key != 13 && key != 9 && key !=48  && key != 8){
            content.removeClass('has-standards');
        }
        else if ((key == 48 || key == 8) && l < 1){
            content.removeClass('has-standards');
        }
    }

    var checkContainer = function(){
        var receivers = rootNode.find('div.suggest-receiver'),
            input =_$(this).closest('.snapshot-content-input'),
            l = receivers.length;
        if (l > 0 && !input.hasClass('has-receivers')){
            input.addClass('has-receivers');
        }
        else if (l == 0 && input.hasClass('has-receivers')){
            input.removeClass('has-receivers');
        }
    }

    var lengthenContainer = function(e){
        var content = _$(this).closest('.snapshot-content-input'),
            receivers = content.find('div.suggest-receiver'),
            l = receivers.length,
            key = e.which;

        if (key != 13 && key != 9 && key !=48  && key != 8){
           _$(this).closest('.snapshot-content-input').removeClass('has-receivers');
        }
        else if ((key == 48 || key == 8) && l < 1){
            _$(this).closest('.snapshot-content-input').removeClass('has-receivers');
        }
    }



    var populateStandardsDropdown = function (event){

         var  dropdown    = pbContent.find('.snapshot-dropdown');

        dropdown.select2({
            placeholder: "Select a Standard",
            minimumInputLength: 0,
            multiple: true,
            dropdownCssClass: "standards-dropdown",
            containerCssClass: "standards-container",
            //width: '600px',
            id: function(data){ return data.id; },
            ajax: {
                url: "home/ajax-filter-standards",
                data: function (term, page) {
                    var groups = gatherReceivers(pbContent),
                        grade = gatherGradeLevel(pbContent),
                        subject = gatherSubject(pbContent);

                    return {
                        q: term, // search term
                        groups : groups,
                        grade  : grade,
                        subject: subject,
                        user_id : modo.common.account.id,
                        page_limit: 10
                    };
                },
                results: function (data, page) { // parse the results into the format expected by Select2.
                    // since we are using custom formatting functions we do not need to alter remote JSON data
                    return {results: data};

                }
            },
            formatResult: formatResult,
            formatSelection: formatSelection,
            initSelection: function(element, callback) {
                var data = [];
                $(element.val().split(",")).each(function(i) {
                    var item = this.split(':');
                    data.push({
                        category: item[0],
                        statement_code: item[1]
                    });
                });
                callback(data);

            }
        });
    };



    function formatResult(data) {
        var render = '';
        if (data.statement_code_short !== undefined && data.statement_html !== undefined){

            render = '<div class="standard-selections"><div class="statement">' + data.statement_code_short + '</div><div class="long-statement"> ' + data.statement_html + '</div></div>';
        }

        if (data.title != undefined){
            render = '<strong class="standard-category"> '+ data.title + '</strong>';
        }
        return render
    };

    function formatSelection(data) {
        rootNode.find('.snapshot-bottom-content').removeClass('hidden');
        var render = '<p class="statement-code" id="'+data.id+'"> ' + data.statement_code_short + '</p>';
        pbContent.find('.snapshot-content-input').addClass('has-standards');

        return render;

    };

    this.destroy = function(){

        rootNode.find('#postbox-snapshot').unbind('click', populateStandardsDropdown);


        initialize = bindEvents = destroy = populateStandardsDropdown = gatherStandards = gatherGradeLevel = gatherStandards = null;

        rootNode = _$ = superClass = instance  = null;

    }
    initialize();

}
