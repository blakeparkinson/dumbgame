            'click .personalize-invite'                  : function(e){this.editName(e);this.selectSchool(e)}
selectSchool: function(e){
            var $row       = $(e.currentTarget).parents('tr');
            $container.addClass('edit').append(modo.Template.get('school-search-input', {name: schoolName}));


        }
