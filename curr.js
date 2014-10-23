if (id === 'postbox-snapshot' && (_$(this).hasClass('welcome-flow')))
        {
            currentContent = rootNode.find('#' + id + '-content');
            if(!superClass.components.Snapshot.getShowedWelcome())
            {
                var welcome_tpl = modo.Template.get('snapshot-welcome', {});
                if(currentContent.find('.snapshot-welcome-container').length == 0)
                    currentContent.append(welcome_tpl);
            }
        }

        options.removeClass('selected');
        _$(this).addClass('selected');

		if(model.navigation.view == 'home' && modo.common.account.type != 'PUBLISHER') model.postbox.activeInstance = 'home';

        panels.addClass('hidden');

		currentContent.removeClass('hidden');
