/* @class modo.common.Layout.Locale
 * @requires modo, doucment, jquery
 *
 * @description controls any functions related to switching or handling localization
 */

modo.define('common.Footer', function Footer(){

    var _$ = $;

    this.initialize = function(){

        bindEvents();

        bindEvents = null;

    };

    var bindEvents = function(){

        _$('#footer').find('a.translate').bind('click', switchLanguage);
        _$('.languages-menu-t5').find('a.translate').bind('click', switchLanguage);
        _$('li.languages-menu a').bind('click', toggleLanguagesMenu);
        _$('li.languages-menu-t5 a').bind('click', t5ToggleLanguages);



    };


    var toggleLanguagesMenu = function() {

        var g = modo.common.utilities.general;

        if (_$(this).parent().hasClass('dd-visible')) {

            g.hideAllMenus();

        } else {
            g.hideAllMenus();
            _$(this).parent().addClass('dd-visible');
            g.addPageClick();
        }


    };



    var switchLanguage = function(e){

        e.preventDefault();
        console.log(e);

        var url      = location.href,
            language = _$(this).attr('language');

        url = url.substr(0, url.indexOf('#'));

        if ( url.indexOf("language=") != -1 ){

            url = url.replace(/language=..(-..)*/, 'language='+language);

        }else if ( url.indexOf("?") != -1 ){

            url += '&language='+language;

        }else{

            url += '?language='+language;

        }

        location.href = url;

        url = language = null;

    };

    var t5ToggleLanguages = function(e){

        //modal footer links management
        if (_$('.languages-t5').hasClass('hidden')){
            _$('.languages-t5').removeClass('hidden');
        }
        else{
            _$('.languages-t5').addClass('hidden');

        }


    }

});
