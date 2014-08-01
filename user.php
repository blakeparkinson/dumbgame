    public function indexAction()
    {

        $account_info = AccountHandler::getInstance()->getAccountInfo();
        $user_id      = $this->_getParam('uid', $account_info['user_id']);
        $user_has_access = false;

        $db_profiles = Profiles::getInstance();
        $db_users    = Users::getInstance();

        $user_info    = $db_users->getUserInfo($user_id);
        $classmates   = $db_users->getClassmates($user_id);
        $teachermates = $db_users->getTeachermates($user_id);
        $teachers     = $db_users->getTeachers($user_id, $this->view->language);
        $viewer_type  = 'STUDENT';

        $messaging_helper = MessagingHelper::getInstance();

        if( $user_info['user_id'] == $account_info['user_id'] || $account_info['type'] == 'SUPER_USER' )
        {
            if( $user_info['user_id'] == $account_info['user_id'] )
            {
                $viewer_type = self::SELF;
            }
            $user_has_access = true;
            $display_name = $messaging_helper->formatName($user_info, $this->view->language, false);
        }

        if( $user_info['type'] == 'STUDENT' && $account_info['type'] == 'PARENT')
        {
            $student_row = ParentsStudents::getInstance()->getStudentsInfo($account_info['user_id'], $user_info['user_id']);
            if( $student_row )
            {
                $user_has_access = true;
            }
        }
        if( !$user_has_access )
        {
            foreach( $teachers as $user )
            {
                if ( $user['user_id'] == $account_info['user_id'] )
                {
                    $viewer_type = 'TEACHER';
                    $user_has_access = true;
                    break;
                }
            }
        }

        if( !$user_has_access )
        {
            foreach( $classmates as $user )
            {
                if( $user['user_id'] == $account_info['user_id'] )
                {
                    $user_has_access = true;
                    break;
                }
            }
        }

        if( !$user_has_access )
        {
            foreach( $teachermates as $user )
            {
                if ($user['user_id'] == $account_info['user_id'])
                {
                    $user_has_access = true;
                    break;
                }
            }
        }

        if ( !$user_has_access )
        {
            if (GroupJoinRequests::getInstance()->hasPendingGroupJoinRequest($user['user_id'], $account_info['user_id']))
            {
                $user_has_access = true;
            }
        }

        $parents = array();
        if( $user_info['type'] == 'STUDENT' )
        {
            $tmp_parents = ParentsStudents::getInstance()->getStudentParentsInfo($user_id);
            foreach( $tmp_parents as $parent )
            {
                $parents[] = array
                (
                    'name'    => $messaging_helper->formatParentName($parent, $user_info, $parent['relation'], $parent['other_relation'], $this->view->language, 'STUDENT'),
                    'thumb'   => Profiles::getAvatarUrlFromProfileArray($parent, 'THUMB'),
                    'user_id' => $parent['user_id']
                );
            }
        }

        foreach($teachers as $i => $teacher)
        {
            $teachers[$i]['name'] = $messaging_helper->formatName($teacher, $this->view->language);
        }
        foreach($classmates as $i => $classmate)
        {
            $classmates[$i]['name'] = $messaging_helper->formatName($classmate, $this->view->language);
        }

        $groups = GroupsHandler::getInstance()->getUsersGroupsInfo($user_id, false, true, $this->view->language);

        $receivers = array
        (
            'people' => array(),
            'location' => array()
        );
        if( $viewer_type == self::SELF)
        {
            foreach( $teachers as $teacher )
            {
                $receivers['people'][] = $teacher['user_id'];
            }
        }
        elseif( $viewer_type == 'TEACHER' )
        {
            $receivers['people'][] = $user_id;
        }

        $this->view->viewer_type     = $viewer_type;
        $this->view->viewer_thumb    = $db_profiles->getUserThumbnail($account_info['user_id'], $account_info['username']);
        $this->view->groups          = $groups['groups'];
        $this->view->parents         = $parents;
        $this->view->display_name    = $messaging_helper->formatName($user_info, $this->view->language);

        $this->view->my_tags         = TagHandler::getInstance()->getUserTags($account_info['user_id']);
        $this->view->account_info    = $account_info;
        $this->view->teachers        = $teachers;
        $this->view->classmates      = $classmates;
        $this->view->user_has_access = $user_has_access;
        $this->view->profile         = $db_profiles->getProfile( $user_info['user_id'], $user_info['username'] );
        $this->view->user_info       = $user_info;
        $this->view->user_name       = $messaging_helper->formatName($user_info, $this->view->language);
        $this->view->receivers       = Zend_Json::encode($receivers);
    }
