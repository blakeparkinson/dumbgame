<?php
//Following constants are the DEBUG levels
define( 'DEBUG_OFF', 0 );
define( 'DEBUG_CATCHS', 1 ); //Only messages from try/catch blocks will log
define( 'DEBUG_FULL', 2 ); //all info messages will be logged


if (isset( $argv [0] )) {
    define( 'CONSOLE', 1 ); //constant indicates its running from console to index.php
    //--------------------------------------
    //Whith this we include the zend context
    require_once ('index.php');

    NotificationsWorker::getInstance();
}

class NotificationsWorker {
    const MAX_EXECUTION_TIME                 = 3660;
    const WORKER_FUNCTION_SEND_NOTIFICATIONS = 'sendNotifications';
    const MAX_JOBS                           = 3660;

    private $shutdown; // this variable is set by the signal handler when it recieves SIGTERM


    //maps the type of the incoming message with the DB types
    private static $typeMapping = array (
        'text'       => 'notes',
        'video'      => 'messages',
        'image'      => 'messages',
        'assignment' => 'assignments',
        'system'     => 'messages',
        'comment'    => 'replies',
        'alert'      => 'alerts',
        'grade'      => 'messages',
        'poll'       => 'messages',
        'quiz'       => 'quizzes',
    );

    private $worker;
    private static $starttime;
    private static $jobcount;
    private static $replyTo = NOTIFY_EMAIL;
    private static $log;
    private static $msgID = 0;
    private static $msgType = '';
    private static $expectedWorkersNumber;

    //self::logActions is incharge of logging according to DEBUG level


    /**
     * Setup the Notifications worker object
     *
     */
    private function __construct() {
        // install signal handler
        declare(ticks = 1)
            ;
        pcntl_signal( SIGTERM, array ($this, "sig_handler" ) );
        $this->shutdown = FALSE;

       self::$starttime = time();
       self::$jobcount=0;
        try {
            $this->worker = new GearmanWorker();
            $gearman_obj = new Zend_Config_Ini(APPLICATION_PATH . '/configs/application.ini', 'gearman');
            $gearman_job_server_conf = $gearman_obj->get('job_server')->toArray();
            $added= $this->worker->addServer($gearman_job_server_conf['host'], $gearman_job_server_conf['port']);
            $gearman_workers_conf = $gearman_obj->get('workers')->toArray();
            self::$expectedWorkersNumber = $gearman_workers_conf['expected_worker_count'];

            $this->worker->addFunction( 'sendNotifications', 'NotificationsWorker::send_notifications' );

        //$this->worker->setOptions(GEARMAN_WORKER_NON_BLOCKING,TRUE); // causes the function GearmanWorker::work() to not block the caller. However, it also causes the worker to not process any jobs! is this a bug?
        } catch ( exception $e ) {
            self::logActions( "\n" . '__construct:ERROR on setup ' . $e->getMessage(), DEBUG_CATCHS );
            self::writeLog( 'c_' );
        }

        try {
            do {
                // check to see if we have recieved a SIGTERM signal
                if ($this->shutdown) {
                    self::logActions( "Recieved kill command. Exiting gracefully", DEBUG_FULL );
                    self::writeLog();
                    exit( 0 );
                }

                // check to see if we have been running too long
                $runningtime = time() -self::$starttime;
                if ($runningtime > self::MAX_EXECUTION_TIME ) {
                    self::logActions( "exiting after $runningtime seconds", DEBUG_FULL );
                    self::writeLog();

                    // ensure that enough workers are running then exit gracefully
                    self::watchAndRaiseWorkers();
                    exit( 0 );
                }

                 // check to see if we have executed too many jobs
                if (self::$jobcount > self::MAX_JOBS ) {
                    self::logActions( "exiting after running ".self::$jobcount.' jobs ', DEBUG_FULL );
                    self::writeLog();

                    // ensure that enough workers are running then exit gracefully
                    self::watchAndRaiseWorkers();
                    exit( 0 );
                }

                $this->worker->work();

                if (! ($this->worker->returnCode() == GEARMAN_IO_WAIT || $this->worker->returnCode() == GEARMAN_NO_JOBS || $this->worker->returnCode() == 0)) {
                    self::logActions( "an error ocurred " . $this->worker->returnCode(), DEBUG_FULL );
                    self::writeLog();
                }

            } while ( TRUE );

        } catch ( exception $e ) {
            //self::sendWarningEmail();
            self::logActions( "\n" . '__contruct:**** I Finished Working **** ' . $e->getMessage(), DEBUG_CATCHS );
            self::writeLog( 'cw_' );
        }
    }

    function __destruct() {
        try {
           //self::sendWarningEmail();
            self::logActions( "\n ---- I Died!!! ----\n", DEBUG_CATCHS );
            self::writeLog( 'd_' );
        } catch ( exception $e ) {
            //do nothing
        }
    }

    /**
     *
     * @return object GearmanHelper instance
     */
    static function getInstance() {
        static $instance;
        $instance = new self();
        return $instance;
    }

    /**
     * Main function to process and send correct notifications for an action
     * @param array $job serialized object from Gearman call
     * @return
     */
    public static function send_notifications($job) {
        try {
            if (empty( self::$starttime )) {
                self::$starttime = time();
            }
            if(empty(self::$jobcount)){
                self::$jobcount=0;
            }
            self::$jobcount++;

            self::logActions( '-------------------------------' . "\n" . 'NotificationsWorker::send_notifications' . "\n", DEBUG_CATCHS );
            $myProccessId = getmypid(); //Get self proccess ID
            self::logActions( "\n" . 'send_notifications:: Proccess ID : ' . $myProccessId . "\n\n", DEBUG_CATCHS );

            try {
                $workerObject = $job->workload(); //will bring the desired message
                $workerArray = unserialize( $workerObject );
                $errors=error_get_last();

                if(isset($errors['type']) && $errors['type']==2 && isset($errors['message']) && strstr($errors['message'],'unserialize') ){
                    // fix the SimpleXML bug
                    $tokens=explode(';',$workerObject);
                    foreach($tokens as $i=>$token){
                        if($token=='O:16:"SimpleXMLElement":1:{i:0'){
                                $tokens[$i+2]=substr($tokens[$i+2],1);
                                unset($tokens[$i]);
                        }
                    }
                    $workerObject=implode(';',$tokens);
                    $workerArray = unserialize( $workerObject );
                }

                if(empty($workerArray)){
                    trigger_error('Unable to process job '.$job->handle());
                    trigger_error($workerObject);
                    return;
                }
                $selectedReceivers = $workerArray ['selected_receivers'];
                $accountInfo = $workerArray ['account_info'];
                $messageData = $workerArray ['message_data'];
                self::$msgID = isset($messageData['message_id']) ? $messageData['message_id']: null;
                self::$msgType = $messageData ['type'];
            } catch ( exception $e ) {
                self::logActions( "\n" . 'send_notifications: ERROR: Constructing Notification: ' . $e->getMessage() . "\n", DEBUG_CATCHS );
            }

            //--------------------------------
            // Log some Data
            $initialLogMsg = '$workerArray: ' . print_r( $workerArray, true ) . "\n";
            self::logActions( $initialLogMsg, DEBUG_FULL );

            //verify that a connection to the database is active
            self::ensureActiveDBConnection();

            try {
                //Call Main proccess
                self::process_notifications( $workerArray, $selectedReceivers, $accountInfo, $messageData );
            } catch ( exception $e ) {
                self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
                self::logActions( "\n" . 'send_notifications: ERROR processing notifications ' . $e->getMessage(), DEBUG_CATCHS );
                self::writeLog();
            }
        } catch ( exception $e ) {
            //--------------------------------
            // Log the Exception
            self::logActions( "\n" . 'send_notifications: ERROR: An Exception was thrown: ' . $e->getMessage(), DEBUG_CATCHS );
            self::writeLog();
        }
    }

    private static function ensureActiveDBConnection(){
        $write_db_registry_name = 'zendmodo_db';
        $readonly_db_registry_name = 'zendmodo_readonly_db';

        $databases = Zend_Registry::get('databases');

        $db=NULL;
        if (Zend_Registry::isRegistered($write_db_registry_name)) {
            $db = Zend_Registry::get($write_db_registry_name);
        }
        if (empty($db)) {
            $db_obj = $databases->db->get('zendmodo');

            if(empty($db_obj)){
                throw new Exception("no configuration defined for db");
            }

            $db = Zend_Db::factory($db_obj->adapter, $db_obj->config->toArray());
            if (empty($db)) {
                throw new Exception("failed to connect to db using configuration");
            } else {
                Zend_Registry::set($write_db_registry_name, $db);
            }
        }

        try{
            $now=$db->fetchOne('select now()');
        }catch(Zend_Db_Statement_Exception $e){
            if($e->getMessage()=="SQLSTATE[HY000]: General error: 2006 MySQL server has gone away"){
                trigger_error('MySQL server has gone away. Reconnecting...',E_USER_WARNING);
                //try to reconnect
                $db->closeConnection();
                $db->getConnection();
                $now=$db->fetchOne('select now()');
                trigger_error('Reconnected to database',E_USER_NOTICE); //write this message to the log so that we know when successful reconnects happen.
            }else{
                throw $e; // some other exception happened that we arent going to handle here.
            }
        }

        // check and reconnect to readonly database
        $readonly_db=NULL;
        if (Zend_Registry::isRegistered($readonly_db_registry_name)) {
            $readonly_db = Zend_Registry::get($readonly_db_registry_name);
        }
        if (empty($readonly_db)) {
            $readonly_db_obj = $databases->db->get('zendmodo_readonly');

            if(empty($readonly_db_obj)){
                throw new Exception("no configuration defined for readonly db");
            }

            $readonly_db = Zend_Db::factory($readonly_db_obj->adapter, $readonly_db_obj->config->toArray());
            if(empty($readonly_db)){
                throw new Exception("failed to connect to readonly db using configuration");
            }else{
                Zend_Registry::set($readonly_db_registry_name, $readonly_db);
            }
        }

        try{
            $now=$readonly_db->fetchOne('select now()');
        }catch(Zend_Db_Statement_Exception $e){
            if($e->getMessage()=="SQLSTATE[HY000]: General error: 2006 MySQL server has gone away"){
                trigger_error('Readonly MySQL server has gone away. Reconnecting...',E_USER_WARNING);
                //try to reconnect
                $readonly_db->closeConnection();
                $readonly_db->getConnection();
                $now=$readonly_db->fetchOne('select now()');
                trigger_error('Reconnected to readonly database',E_USER_NOTICE); //write this message to the log so that we know when successful reconnects happen.
            }else{
                throw $e; // some other exception happened that we arent going to handle here.
            }
        }
    }

    /**
     * Aux function to process and send correct notifications for an action
     * @param array $workerArray unserialized object from Gearman call
     * @param array $selectedReceivers the receivers of the message
     * @param array $accountInfo info from the sender
     * @param array $messageData the message data to be send
     * @return
     */
    private function process_notifications($workerArray, $selectedReceivers, $accountInfo, $messageData) {
        $allRecipients = array ();
        $membersOfGroup = array ();
        $parentsOfGroup = array ();
        $adminRecipients = array();
        $parentsGroups = array();

        try {
            //This case if for new restricted messages to only receivers that have commented
            if (isset( $selectedReceivers ['notification_receivers'] )) {
                self::logActions( '$selectedReceivers[\'notification_receivers\'] Getting through the first if', DEBUG_FULL );

                try {
                    foreach ( $selectedReceivers ['notification_receivers'] as $receiver => $receiverId ) {
                        array_push( $allRecipients, $receiverId );
                    }

                    //check if this user that answered is an admin
                    if (is_array( $selectedReceivers ['locations'] )) {
                        foreach ( $selectedReceivers ['locations'] as $location ) {
                            $inst_admins = array();
                            if ($location ['type'] == 'school' || $location ['type'] == 'school_vip') {
                                $inst_admins = Schools::getInstance()->getAllSchoolAdmins( $location ['id'] );
                                $messageData['posted_in_school'] = 1;
                            }
                            if ($location ['type'] == 'district' || $location ['type'] == 'district_vip') {
                                $inst_admins = Districts::getInstance()->getDistrictMembers( $location ['id'] ,false,'admins','',true);
                            }

                            foreach($inst_admins as $admin)
                            {
                                if(isset($admin['admin_notifications']) && !empty($admin['admin_notifications']) && in_array($admin ['user_id'],$selectedReceivers ['notification_receivers']))
                                {
                                    array_push( $adminRecipients, $admin ['user_id'] );
                                }
                            }
                            unset( $inst_admins );
                        }
                    }

                } catch ( exception $e ) {
                    self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
                    self::logActions( "\n" . 'process_notifications:ERROR creating receiver list ' . $e->getMessage(), DEBUG_CATCHS );
                }
            } //This is for direct messages to get notification when they answered my message,etc
            else if (is_array( $selectedReceivers ['people'] ) && count( $selectedReceivers ['people'] ) == 1 &&
                (! is_array( $selectedReceivers ['locations'] ) || count( $selectedReceivers ['locations'] ) == 0) && $messageData ['type'] == 'comment') {
                self::logActions( 'process_notifications: Going through direct reply', DEBUG_FULL );
                try {
                    //do direct reply answer
                    $originalSenderInfo = Messages::getInstance()->getSenderInfoByMessageId( $messageData ['comment_to'] );
                    if ( $originalSenderInfo['send_notifications'] != '0' ){
                        if ($originalSenderInfo ['user_id'] == $accountInfo ['user_id'])
                            $newReceiver = $selectedReceivers ['people'] [0];
                        else
                            $newReceiver = $originalSenderInfo ['user_id'];

                        array_push( $allRecipients, $newReceiver );
                    }
                } catch ( exception $e ) {
                    self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
                    self::logActions( "\n" . 'process_notifications:ERROR creating direct reply receiver ' . $e->getMessage(), DEBUG_CATCHS );
                }
            } //This is the normal case to fetch all receivers on groups, individuals, etc
            else { //THERE COULD BE A PROBLEM HERE IF notifications_receivers is empty, or other conditions default to "else" when its not expected
                try {
                    self::logActions( 'process_notifications: Going through normal case', DEBUG_FULL );
                    try {
                        //search recipients
                        $possible_students_tmp = array ();
                        foreach ( $selectedReceivers ['people'] as $recipient => $userId ) {
                            array_push( $allRecipients, $userId );
                            array_push( $possible_students_tmp, $userId );
                        }
                        //Parents notifications on assignments, find possible parents for this users
                        self::logActions( 'process_notifications: type ' . $messageData ['type'], DEBUG_FULL );
                        if ($messageData ['type'] == 'assignment' || $messageData ['type'] == 'alert') {
                            $parents_ids = ParentsStudents::getInstance()->getParentsForStudents( $possible_students_tmp, true );
                            self::logActions( 'process_notifications: parent ids ' . print_r( $parents_ids, true ), DEBUG_FULL );
                            $allRecipients = array_merge( $allRecipients, $parents_ids );
                        }
                    } catch ( exception $e ) {
                        self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
                        self::logActions( "\n" . 'process_notifications:ERROR with People array ' . $e->getMessage(), DEBUG_CATCHS );
                    }

                    try {
                        //adds recipients from groups
                        if (is_array( $selectedReceivers ['locations'] )) {
                            foreach ( $selectedReceivers ['locations'] as $location ) {
                                if ($location ['type'] == 'group') {
                                    $tmpUserIds = Groups::getInstance()->getGroupMembersToNotify( $location ['id'] );
                                    $tmpName = Groups::getInstance()->getGroup( $location ['id'], false, true );

                                    foreach ( $tmpUserIds as $user_id ) {
                                        array_push( $allRecipients, $user_id );
                                        array_push( $membersOfGroup, array ($user_id => $tmpName ['title'] ) );
                                        $messageData['parent_group_title'] = $tmpName['parent_group_title'];
                                    }

                                    //Parents notifications on assignments, find possible parents for this groups
                                    self::logActions( 'process_notifications: group type ' . $messageData ['type'], DEBUG_FULL );
                                    if ( $messageData ['type'] == 'assignment' || $messageData ['type'] == 'alert') {
                                        $parents_ids = ParentsStudents::getInstance()->getParents( $location ['id'], true );
                                        self::logActions( 'process_notifications: group parent ids ' . print_r( $parents_ids, true ), DEBUG_FULL );
                                        if (count( $parents_ids )) {
                                            foreach ( $parents_ids as $parent ) {
                                                array_push( $allRecipients, $parent ['parent_id'] );

                                                if (! array_key_exists( $parent ['parent_id'], $parentsOfGroup ))
                                                    $parentsOfGroup [$parent ['parent_id']] = $tmpName ['title'];
                                            }
                                        }
                                    }
                                }
                                if ($location ['type'] == 'group_parents')
                                {
                                    $tmpName = Groups::getInstance()->getGroup( $location ['id'] );

                                    //Parents notifications on assignments, find possible parents for this groups
                                    self::logActions( 'process_notifications: group type ' . $messageData ['type'], DEBUG_FULL );
                                    if ($messageData ['type'] == 'text' || $messageData ['type'] == 'comment' || $messageData ['type'] == 'assignment' || $messageData ['type'] == 'alert') {
                                        $parents_ids = ParentsStudents::getInstance()->getParents( $location ['id'], true );
                                        self::logActions( 'process_notifications: group parent ids ' . print_r( $parents_ids, true ), DEBUG_FULL );
                                        if (count( $parents_ids )) {
                                            foreach ( $parents_ids as $parent ) {
                                                array_push( $allRecipients, $parent ['parent_id'] );

                                                if (! array_key_exists( $parent ['parent_id'], $parentsOfGroup ))
                                                    $parentsOfGroup [$parent ['parent_id']] = $tmpName ['title'];
                                                if (! array_key_exists( $parent ['parent_id'], $parentsGroups ))
                                                    $parentsGroups [$parent ['parent_id']] = $location ['id'];
                                            }
                                        }
                                    }
                                }
                                if ($location ['type'] == 'all-groups')
                                {
                                   $groups = Groups::getInstance()->getUserGroups($accountInfo['user_id'],false,false);

                                    foreach( $groups as $group)
                                    {
                                        if ($group['read_only'] == 1) {
                                            // Do not send email notifications to a group that the user
                                            // cannot post to.
                                            continue;
                                        }
                                        
                                        $group_id = $group['group_id'];
                                        $tmpUserIds = Groups::getInstance()->getGroupMembersToNotify( $group_id );
                                        $tmpName = Groups::getInstance()->getGroup( $group_id, false, true );

                                        foreach ( $tmpUserIds as $user_id ) {
                                            array_push( $allRecipients, $user_id );
                                            array_push( $membersOfGroup, array ($user_id => $tmpName ['title']) );
                                            $messageData['parent_group_title'] = $tmpName['parent_group_title'];
                                        }

                                        //Parents notifications on assignments, find possible parents for this groups
                                        self::logActions( 'process_notifications: group type ' . $messageData ['type'], DEBUG_FULL );
                                        if ( $messageData ['type'] == 'assignment' || $messageData ['type'] == 'alert') {
                                            $parents_ids = ParentsStudents::getInstance()->getParents( $location ['id'], true );
                                            self::logActions( 'process_notifications: group parent ids ' . print_r( $parents_ids, true ), DEBUG_FULL );
                                            if (count( $parents_ids )) {
                                                foreach ( $parents_ids as $parent ) {
                                                    array_push( $allRecipients, $parent ['parent_id'] );

                                                    if (! array_key_exists( $parent ['parent_id'], $parentsOfGroup ))
                                                        $parentsOfGroup [$parent ['parent_id']] = $tmpName ['title'];
                                                }
                                            }
                                        }
                                    }
                                }

                                //Will add recipients from institution admins
                                $inst_admins = array();
                                if ($location ['type'] == 'school' || $location ['type'] == 'school_vip') {
                                    $inst_admins = Schools::getInstance()->getAllSchoolAdmins( $location ['id'] );
                                     $messageData['posted_in_school'] = 1;
                                }
                                if ($location ['type'] == 'district' || $location ['type'] == 'district_vip') {
                                    $inst_admins = Districts::getInstance()->getDistrictMembers( $location ['id'] ,false,'admins','',true);
                                }

                                foreach($inst_admins as $admin)
                                {
                                    if(isset($admin['admin_notifications']) && !empty($admin['admin_notifications']))
                                    {
                                        array_push( $allRecipients, $admin ['user_id'] );
                                        array_push( $adminRecipients, $admin ['user_id'] );
                                    }
                                }
                                unset( $inst_admins );
                            }
                            foreach ( $parentsOfGroup as $key => $parent ) {
                                self::logActions( 'process_notifications: group parents ' . print_r( array ($key => $parent ), true ), DEBUG_FULL );
                                //we insert the parent as a member of the group to hack the subject behaviour
                                array_push( $membersOfGroup, array ($key => $parent ) );
                            }
                            self::logActions( 'process_notifications: group members ' . print_r( $parentsOfGroup, true ), DEBUG_FULL );
                        } else {
                            self::logActions( '$selectedReceivers[\'locations\'] is not an array... Workflow ended unexpectedly', DEBUG_FULL );
                        }
                    } catch ( exception $e ) {
                        self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
                        self::logActions( "\n" . 'process_notifications:ERROR adding recipients from groups ' . $e->getMessage(), DEBUG_CATCHS );
                    }
                } catch ( exception $e ) {
                    self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
                    self::logActions( "\n" . 'process_notifications:ERROR on Normal Case ' . $e->getMessage(), DEBUG_CATCHS );
                }
            }
            //removes repeated recipients
            $uniqueRecipients = array_unique( $allRecipients );
        } catch ( exception $e ) {
            self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
            self::logActions( "\n" . 'process_notifications:ERROR general recipients fail ' . $e->getMessage(), DEBUG_CATCHS );
        }

        //--------------------------------
        // Log some Data
        self::logActions( '$uniqueRecipients: ' . print_r( $uniqueRecipients, true ), DEBUG_FULL );
        self::logActions( '$membersOfGroup: ' . print_r( $membersOfGroup, true ), DEBUG_FULL );

        try {
            //check settings for recipients
            //PREVENT ERROR ON EMPTY ARRAY
            if(isset($uniqueRecipients) && !empty($uniqueRecipients) && count($uniqueRecipients) > 0)
            {
                $notificationsSettings = Notifications::getInstance()->getNotificationsForUsers( $uniqueRecipients );
            }
            else
            {
                $notificationsSettings = array();
                self::logActions( "\n" . 'process_notifications:ERROR: empty uniqueRecipients: ' , DEBUG_FULL);
            }
        } catch ( exception $e ) {
            //--------------------------------
            // Log the Exception
            self::logActions( "\n" . 'process_notifications:ERROR: on getNotificationsForUsers: ' . $e->getMessage(), DEBUG_CATCHS );
            $notificationsSettings = array ();
        }

        try {
            //check if incoming type is correct in db
            $tmpType = self::$typeMapping [$workerArray['message_data']['type']];

            //avoid joing group type
            if (self::isJoinGruoup( $messageData ) == false) {
                //call functions for notifications
                foreach ( $notificationsSettings as $notification ) {
                    $recipientInfo = Users::getInstance()->getUserInfoWithSubdomain( $notification ['user_id'] );
                    if ($recipientInfo['type'] == 'PARENT')
                    {
                        $recipientInfo['parent_groups'] = $parentsGroups;
                    }
                    $contents = self::prepareSubject( $messageData, $accountInfo, $recipientInfo, $membersOfGroup );
                    $contents['group_id'] = $tmpName['group_id'];

                    self::logActions( "\n" . 'Attempt to send with IF params: ' . $notification [$tmpType] . ' | ' . $accountInfo ['user_id'] . ' | ' . $notification ['user_id'], DEBUG_FULL);
                    $contents['isDirect'] = self::isDirectMessage( $notification, $selectedReceivers );
                    $is_admin_notification = self::isAdminNotification( $notification, $messageData, $adminRecipients );
                    self::logActions( "\n" . 'adminRecipients ' . print_r($adminRecipients, true), DEBUG_FULL);

                    //Will send if notification is valid and not admin, or is direct message, or admin settings are correct
                    if ((($notification [$tmpType] == 1 && !in_array($notification['user_id'],$adminRecipients)) || $contents ['isDirect'] || $is_admin_notification) && ($accountInfo ['user_id'] != $notification ['user_id'])) {
                        //call service notification func
                        switch ($notification ['type']) {
                            case 'TWITTER':
                                self::postOnTwitter( $notification, $contents );
                                break;

                            case 'EMAIL':
                                $messageData['is_admin_notification'] = $is_admin_notification;
                                self::sendEmail( $contents, $recipientInfo, $messageData, $workerArray ['server_name'], $notification, $accountInfo );
                                break;

                            case 'SMS':
                                self::sendSms( $notification, $contents, $recipientInfo );
                                break;
                            default :
                                break;
                        }
                    }
                }
            } else {
                self::logActions( "\n" . 'Did not sent message - was join group', DEBUG_FULL );
            }
        } catch ( exception $e ) {
            self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
            self::logActions( "\n" . 'process_notifications:ERROR on for each notificationsSettings ' . $e->getMessage(), DEBUG_CATCHS );
        }

        self::logActions( "\n" . 'NotificationsWorker::Finished Logging' . "\n" . '-------------------------------' . "\n", DEBUG_CATCHS );

        self::writeLog();

        try {
            //Trying to free memory, cant do it outside here becuase reference is no good
            //unset seems to not force garbage collection according to the manual,
            $notificationsSettings = array ();
            $allRecipients = array ();
            $membersOfGroup = array ();
            self::$log = NULL;
            unset( $notificationsSettings );
            unset( $allRecipients );
            unset( $membersOfGroup );

        } catch ( exception $e ) {
            //EMPTY CATCH, CANT LOG ANYTHING HERE, LOGGING ERROR
        }

    }

    private function getSalutationName($recipient_language, $first_name, $last_name, $user_type, $title = 'NONE')
    {
        $registryKey = 'th-' . $recipient_language;

        if (Zend_Registry::isRegistered($registryKey)) {
            $translator = Zend_Registry::get($registryKey);
        } else {
            $translator = TranslationHelper::getInstance(PATH2_LANGUAGES . 'email-subjects.tmx', $recipient_language);
            Zend_Registry::set($registryKey, $translator);
        }

        $salutation_name = ucwords($first_name);

        switch($user_type)
        {
            case 'STUDENT':
                // if mbstring extension is installed, use it to handle multibyte strings
                if (function_exists('mb_substr')) {
                    $last_initial = mb_substr($last_name, 0, 1, 'UTF-8');
                } else {
                    $last_initial = substr($last_name, 0, 1);
                }

                $salutation_name = ucwords($first_name) . ' ' . strtoupper($last_initial) . '.';
                break;

            case 'TEACHER':
                $new_title = ($title == 'NONE') ? $first_name : $translator->_($title) . '.';

                $salutation_name = ucwords(strtolower($new_title)) . ' ' . ucwords($last_name);
                break;
        }

        return $salutation_name;
    }

    private function getSimpleSentTo($language, $recipient_id, $message_id, $message_recipients = null)
    {
        $to = null;

        if (!$message_recipients)
        {
            $message_recipients = Messages::getInstance()->getMessageRecipients($message_id);
        }

        foreach ($message_recipients as $recipient)
        {
            if ('group' == trim(ArrayHelper::elem($recipient, 'posted_in')))
            {
                if (UsersGroups::getInstance()->userIsMemberOfGroup($recipient_id, $recipient['posted_in_id']))
                {
                    $group = Groups::getInstance()->find($recipient['posted_in_id'])->current()->toArray();
                    $to = ArrayHelper::elem($group, 'title');
                }
            }

            if ($to)
            {
                break;
            }
        }

        $registryKey = 'th-' . $recipient_language;

        if (Zend_Registry::isRegistered($registryKey)) {
            $translator = Zend_Registry::get($registryKey);
        } else {
            $translator = TranslationHelper::getInstance(PATH2_LANGUAGES . 'email-subjects.tmx', $recipient_language);
            Zend_Registry::set($registryKey, $translator);
        }

        if ($to)
        {
            $to = $translator->_('reply-to-group') . ' ' . $to;
        }
        else
        {
            $to = $translator->_('reply-to-you');
        }

        return $to;
    }

    /**
     * Prepares the content of the message with correct title, subject, and language
     * @param array $messageData the info of the original sent message
     * @param array $senderInfo information from the sender
     * @param array $destinationInfo information for this destination
     * @return array the subject, first line, and content of the message
     */
    private function prepareSubject($messageData, $senderInfo, $destinationInfo, $membersOfGroup) {
        $retSubject             = '';
        $senderTitle            = '';
        $replied                = '';
        $bodyText               = '';
        $links_text             = '';
        $replied_to             = '';
        $replied_to_type        = '';
        $other_reply_count      = 0;
        $other_reply_count_text = '';


        try {
            $registryKey = 'th-' . $destinationInfo['language'];

            if (Zend_Registry::isRegistered($registryKey)) {
                $translator = Zend_Registry::get($registryKey);
            } else {
                $translator = TranslationHelper::getInstance(PATH2_LANGUAGES . 'email-subjects.tmx', $destinationInfo['language']);
                Zend_Registry::set($registryKey, $translator);
            }

            $senderTitle = ucwords($senderInfo['first_name']);

            //Sender Title
            if ($senderInfo['type'] == 'STUDENT') {
                // if mbstring extension is installed, use it to handle multibyte strings
                if (function_exists('mb_substr')) {
                    $last_initial = mb_substr($senderInfo['last_name'], 0, 1, 'UTF-8');
                } else {
                    $last_initial = substr($senderInfo['last_name'], 0, 1);
                }

                $firstLine = ucwords( $senderInfo ['first_name'] ) . ' ' . strtoupper( $last_initial );
                $senderTitle = $firstLine . '.';
            }

            if ($senderInfo ['type'] == 'TEACHER') {
                $displayTitle = (!$senderInfo ['title'] || $senderInfo ['title'] == 'NONE') ? $senderInfo ['first_name'] . ' ' : $translator->_( $senderInfo ['title'] ) . '. ';
                $firstLine = ucwords( strtolower( $displayTitle ) ) . ucwords( $senderInfo ['last_name'] );
                $senderTitle = $firstLine;
            }

            $possibleGroupName = self::checkGroupArray($destinationInfo, $membersOfGroup);

            //subject
            if ($messageData['comment_to'] != NULL) {
                $other_reply_count = ArrayHelper::elem($messageData, 'other_reply_count', 0);

                if ($other_reply_count > 0)
                {
                    if ($other_reply_count == 1)
                    {
                        $other_reply_count_text = $translator->_('one-more-reply');
                    }
                    else
                    {
                        $other_reply_count_text = $other_reply_count . ' ' . $translator->_('multiple-more-replies');
                    }
                }

                $commentToInfo   = Messages::getInstance()->getMessageInfo($messageData['comment_to']);
                $commentToType   = $commentToInfo['type'];
                $replied_to_a    = $translator->_('replied-to-a');
                $replied_to_type = $translator->_('direct-' . $commentToType);
                $retSubject      = $senderTitle . " " . $replied_to_a . $translator->_('direct-' . $commentToType);
                self::logActions('prepareSubject: The type is ' . $commentToType . ' subject: ' . $retSubject . "\n", DEBUG_FULL);

            } else if ($possibleGroupName != false) {
                $groupName = $possibleGroupName;

                //modifyng subject on assigments for parents, they come as members of the group
                if ($destinationInfo ['type'] == 'PARENT' && ($messageData ['type'] == 'assignment' || $messageData ['type'] == 'alert' || $messageData ['type'] == 'text' || $messageData ['type'] == 'comment')) {
                    if(isset($destinationInfo['parent_groups']) && count($destinationInfo['parent_groups']) > 0)
                    {
                        $group_id = $destinationInfo['parent_groups'][$destinationInfo['user_id']];
                        $student_ids = ParentsStudents::getInstance()->getParentStudentsByGroup($destinationInfo['user_id'],$group_id);
                    }
                    else
                    {
                        $students_info = ParentsHandler::getInstance()->getParentStudentsInfo( $destinationInfo );
                        $student_ids = $students_info ['student_ids'];
                    }

                    $groupName = MessagingHelper::getInstance()->addChildrensNames( $groupName, $student_ids); //$destinationInfo['language']
                    self::logActions('prepareSubject: group Name ' . $groupName . "\n", DEBUG_FULL);
                }

                $retSubject = $senderTitle . " " . $translator->_('sent-a') . $translator->_('direct-' . $messageData['type']) . " " . $translator->_('to') . " " . $groupName;
            } else {
                $retSubject = $senderTitle . " " . $translator->_( 'sent-you-a' ) . $translator->_( 'direct-' . $messageData ['type'] );
            }

            self::logActions( 'prepareSubject: subject: ' . $retSubject, DEBUG_FULL );

            $replied = ($messageData ['comment_to'] == NULL) ? '' : ' ' . $translator->_('replied');

            //body
            switch($messageData ['type'])
            {
                case 'assignment' :
                    $bodyText = $messageData ['assignment'];
                    break;
                case 'quiz' :
                    $bodyText = $messageData ['quiz_title'];
                    break;
                case 'poll':
                    $bodyText = $messageData ['poll_question'];
                    break;
                default:
                    $msg = $messageData ['message'];
                    if (strpos( $msg, 'new-group:' ) !== false) {
                        $grp_id = str_replace( 'new-group:', '', $msg );
                        $grp_info = Groups::getInstance()->getGroup( $grp_id );
                        $msg = $translator->_( 'you-created-the-group' ) . " " . $grp_info ['title'] . "\n" . $translator->_( 'the-group-code-is' ) . " " . $grp_info ['code'];
                    }

                    $bodyText = MessagingHelper::formatDBString($msg,true,true,false,true);
                    //self::logActions( "\n" . 'TEXT: ' . $bodyText, DEBUG_FULL );
                    break;
            }

            if ($messageData['type'] == 'text' && isset( $messageData['links']) && is_array($messageData['links'])) {
                $links_text = '';

                foreach ( $messageData['links'] as $link ) {
                    if (!isset($link['type']) || $link['type'] != 'embed') {
                        $link_title = trim($link['desc']);
                        $link_title  = ($link_title && !filter_var($link_title, FILTER_VALIDATE_URL) ? $link_title : 'Link');
                        if(LinkHandler::isEdmodoPostUrl($link['url']))
                        {
                            if(strpos($link_title,'Edmodo | Where Learning Happens') === false)
                            {
                                $links_text .=   $link_title ;
                            }
                            else
                            {
                                $link_title = $translator->_( 'edmodo-post' );
                                $links_text .= "<a href='{$link['url']}'>{$link_title}</a>\n";
                            }
                        }
                        else
                        {
                            $links_text .= "<a href='{$link['url']}'>{$link_title}</a>\n";
                        }
                    }
                }
            }
        } catch ( exception $e ) {
            self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
            self::logActions( "\n" . 'prepareSubject: processing subject ' . $e->getMessage(), DEBUG_CATCHS );
        }

        $emailContents = array ('subject' => $retSubject, 'sender_title' => $senderTitle, 'first-line' => $senderTitle . $replied . ':', 'content' => $bodyText, 'links_text' => $links_text, 'replied_to_a' => $replied_to_a, 'replied_to_type' => $replied_to_type, 'other_reply_count' => $other_reply_count, 'other_reply_count_text' => $other_reply_count_text, 'group_name' => $groupName);

        return $emailContents;
    }

    private function formatMessageLink($contents, $messageData, $language, $recipientInfo, $serverName) {
        try {
            $formattedLink = MailHandler::getInstance()->formatEmailUrl($serverName, $recipientInfo, $messageData);
            self::logActions( "\n" . 'formatMessageLink: recipientInfo ' . print_r( $recipientInfo, true ), DEBUG_FULL );
        } catch ( exception $e ) {
            self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
            self::logActions( "\n" . 'formatMessageLink: formatting server name ' . $e->getMessage(), DEBUG_CATCHS );
        }

        $spotlightType = '';
        $registryKey='th-'.$language;
        if(false && Zend_Registry::isRegistered($registryKey)){
            $translator=Zend_Registry::get($registryKey);
        }else{
            // $translator = new TranslationHelper( new Zend_Translate( 'tmx', PATH2_LANGUAGES . 'email-subjects.tmx', $language ) );
            $translator  = TranslationHelper::getInstance(PATH2_LANGUAGES . 'email-subjects.tmx', 'en');
            $englishType = $translator->_('link-' . $messageData['type']);
            $translator  = TranslationHelper::getInstance(PATH2_LANGUAGES . 'email-subjects.tmx', $language);

            Zend_Registry::set($registryKey, $translator);
        }


        $translatedType = $translator->_( 'link-' . $messageData ['type'] );

        try {
            self::logActions( "\n" . 'formatMessageLink: Will format: ' . $contents ['isDirect'] . ' type: ' . $messageData ['type'] . ' comment-to: ' . $messageData ['comment_to'], DEBUG_FULL );
            if ($messageData ['comment_to'] != NULL) {
                $formattedLink .= '/post/' . $messageData ['comment_to'];
                $translatedType = $translator->_( 'comment-to' );
            } else if (!empty($messageData ['message_id'])) {
                $formattedLink .= '/post/' . $messageData ['message_id'];
            }
            else {
                //No spotlight type, just go to home
                $formattedLink .= '';
            }
        } catch ( exception $e ) {
            self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
            self::logActions( "\n" . 'formatMessageLink: formatting link ' . $e->getMessage(), DEBUG_CATCHS );
        }

        $formattedArray = array(
            'formattedLink'  => $formattedLink,
            'translatedType' => $translatedType,
            'type'           => $englishType,
        );

        self::logActions( "\n Formated Link: \n" . $formattedLink, DEBUG_FULL );
        return $formattedArray;
    }

    /**
     * Searches for the group name for a user that belongs to that group
     * @param array $destinationInfo the info for the user to search
     * @param array $membersOfGroup ids from all users members to this group
     * @return string group Name or false
     */
    private function checkGroupArray($destinationInfo, $membersOfGroup) {
        $answer = false;
        try {
            foreach ( $membersOfGroup as $key => $member ) {
                if (array_key_exists( $destinationInfo ['user_id'], $member )) {
                    $answer = $member [$destinationInfo ['user_id']];
                    break;
                }
            }
        } catch ( exception $e ) {
            self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
            self::logActions( "\n" . 'checkGroupArray:ERROR on for each membersOfGroup ' . $e->getMessage(), DEBUG_CATCHS );
        }
        return $answer;
    }

    /**
     * current way of knowing if a message is from "joining a group"
     * @param array $messageData the info for the user to search
     * @return boolean if message is of type "join group"
     */
    private function isJoinGruoup($messageData) {
        $answer = false;
        if ($messageData ['type'] == 'system') {
            if (stristr( $messageData ['message'], 'joined' ) && stristr( $messageData ['message'], 'group' ))
                $answer = true;
        }

        return $answer;
    }

    //Checks if a user is an admin and has the right settings to receive notifications
    private function isAdminNotification($notification, $message_data, $admin_recipients)
    {
        $is_admin = false;

        if(!empty($notification['admin_notifications']))
        {
            if(!empty($notification['admin_notes']) && $message_data['type'] == 'text' && in_array($notification['user_id'],$admin_recipients))
            {
                $is_admin = true;
            }
            if(!empty($notification['admin_replies']) && $message_data['type'] == 'comment' && in_array($notification['user_id'],$admin_recipients))
            {
                $is_admin = true;
            }
            self::logActions( "\n isAdminNotification::$is_admin ,admin_notes: " . $notification['admin_notes'] ." admin_replies: " . $notification['admin_replies'] . " type: " . $message_data['type'] . " \n"  , DEBUG_FULL );
        }

        return $is_admin;
    }

    /**
     * Determines if a user needs to receive a direct message
     * @param array $notification the users notifications settings
     * @param array $selectedReceivers receivers for the message
     * @return boolean if should receive the direct message
     */
    private function isDirectMessage($notification, $selectedReceivers) {
        $sendDirect = false;
        try {
            if ($notification ['messages'] == 1) {
                if (isset( $selectedReceivers ['people'] ) && is_array( $selectedReceivers ['people'] )) {
                    if (in_array( $notification ['user_id'], $selectedReceivers ['people'] )) {
                        //if messages flag is on, and user is in the "people" list
                        $sendDirect = true;
                        self::logActions( "\n" . 'Is Direct Message, should deliver message: ' . self::$msgID . ' to user: ' . $notification ['user_id'] . "\n", DEBUG_FULL );
                    } else
                        self::logActions( "\n" . 'isDirectMessage:Not in receivers ', DEBUG_FULL );

                } else
                    self::logActions( "\n" . 'isDirectMessage:selectedReceivers[people] failed ', DEBUG_FULL );
            } else
                self::logActions( "\n" . 'isDirectMessage:Notification off ' . $notification ['messages'] . "\n", DEBUG_FULL );
        } catch ( exception $e ) {
            self::logActions( "\n" . 'FAILURE: MSGID: ' . self::$msgID . ', MSGTYPE: ' . self::$msgType, DEBUG_CATCHS );
            self::logActions( "\n" . 'isDirectMessage:ERROR checking for direct notification ' . $e->getMessage(), DEBUG_CATCHS );
        }
        return $sendDirect;
    }

    /**
     * Sends a message on twitter, truncates it to appropiate length
     * @param array $usrSettings info for the receiver
     * @param array $contents the message contents
     * @return
     */
    private function postOnTwitter($usrSettings, $contents) {
        try {
            $tw = TwitterHelper::getInstance();
            $message = $tw->truncate( $contents ['first-line'] . ' ' . $contents ['content'], '' );
            $response = $tw->sendDirectMessage( $usrSettings ['via'], $message );

            self::logActions( 'Sending a Twitter notification to <' . $usrSettings ['via'] . '> status: ' . ($response ? 'SUCCESS' : 'FAILURE'), DEBUG_FULL );
        } catch ( exception $e ) {
            self::logActions( "\n" . 'postOnTwitter: ERROR: An Exception was thrown: ' . $e->getMessage(), DEBUG_CATCHS );
        }
    }

    private static function simpleTruncate($string, $min_length, $max_length)
    {
        if (strlen($string) <= $max_length)
        {
            return $string;
        }

        $limiter = "CUTHERECUTHERECUTHERE";
        $string  = wordwrap($string, $min_length, $limiter, true);
        $strings = explode($limiter, $string);
        return $strings[0] . '...';
    }

    /**
     * Sends the email message
     * @param array $contents the message contents
     * @param array $recipientInfo information for this destination
     * @param array $messageData - some data/information concerning the message
     * @param string $serverName - the box from which the email was trigger
     * @param array $user_settings - optional - email settings of the user
     * @param array $account_info - optional - account info of user sending the note
     * @return
     */
    private function sendEmail($contents, $recipientInfo, $messageData, $serverName, $user_settings = array(), $account_info = array()) {
        try {
            //rollout for the notifications fix to "send all" expected emails, hopefully we can remove this one very soon
            $notifications_fix = AbHandler::idInTest($recipientInfo['user_id'], 'notifications_fix_rollout');
            if($user_settings['email_verified'] == 1 || $notifications_fix != AbHandler::CONTROL)
            {
                $should_use_phtml = false;
                $misc_data_tracking = array();
                $language    = $recipientInfo['language'];
                $messageLink = self::formatMessageLink($contents, $messageData, $language, $recipientInfo, $serverName);

                $use_new_replies = (NOTIFICATIONS_NEW_REPLIES_ALL_ON == 1);

                if (!$use_new_replies && NOTIFICATIONS_NEW_REPLIES_GATED_ON == 1)
                {
                    $tests = AbTestingHandler::getTestsByUserId($recipientInfo['user_id']);

                    foreach ($tests as $test)
                    {
                        if (StringHelper::beginsWith($test, NOTIFICATIONS_NEW_REPLIES_GATE_PREFIX))
                        {
                            $use_new_replies = true;
                            break;
                        }
                    }
                }

                if (NOTIFICATIONS_NEW_REPLIES_FORCE_OFF == 1)
                {
                    $use_new_replies = false;
                }
                if ($use_new_replies)
                {
                    $data = array(
                        'message_id'              => null,
                        'message_info'            => null,
                        'message_sender_info'     => null,
                        'message_recipients'      => null,
                        'message_to'              => null,
                        'message_content'         => null,
                        'message_from_salutation' => null,

                        'reply_id'              => null,
                        'reply_sender_info'     => null,
                        'reply_from_salutation' => null,
                    );

                    $is_reply = (trim($messageLink['translatedType']) == 'reply');

                    if ($is_reply)
                    {

                        $template_name = TrackingHelper::EMAILTYPE_NOTIFICATIONS_3;
                        $data['message_id'] = $messageData['comment_to'];
                        $data['reply_id']   = $messageData['comment_id'];
                    }
                    else
                    {
                        $template_name = TrackingHelper::EMAILTYPE_NOTIFICATIONS_2;
                        $data['message_id'] = $messageData['message_id'];
                    }

                    $data['message_info']            = Messages::getInstance()->getMessageInfo($data['message_id']);
                    $data['message_sender_info']     = Messages::getInstance()->getSenderInfoByMessageId($data['message_id']);
                    $data['message_recipients']      = Messages::getInstance()->getMessageRecipients($data['message_id']);
                    $data['message_from_salutation'] = self::getSalutationName($recipientInfo['language'], $data['message_sender_info']['first_name'], $data['message_sender_info']['last_name'], $data['message_sender_info']['type'], $data['message_sender_info']['title']);
                    $data['message_to'] = self::getSimpleSentTo($recipientInfo['language'], $recipientInfo['user_id'], $data['message_id'], $data['message_recipients']);

                    // reply to message
                    if ($is_reply)
                    {
                        $data['message_content']       = Messages::getInstance()->getMessageContent($data['message_id'], $data['message_info']['type']);
                        $data['reply_content']         = $contents['content'];
                        $data['reply_from_salutation'] = $contents['sender_title'];

                        $values2insert = array (
                            '{MESSAGE_FULLNAME}'     => $data['message_from_salutation'],
                            '{MESSAGE_SENT_TO}'      => $data['message_to'],
                            '{MESSAGE_NOTIFICATION}' => self::simpleTruncate($data['message_content'], 95, 100),
                            '{MESSAGE_TYPE}'         => $contents['replied_to_type'],
                            '{MESSAGE_LINK}'         => $messageLink['formattedLink'],

                            '{COMMENT_FULLNAME}'     => $data['reply_from_salutation'],
                            '{COMMENT_NOTIFICATION}' => self::simpleTruncate($contents['content'], 495, 500),
                            '{COMMENT_TYPE}'         => ucfirst(trim($messageLink['translatedType'])),
                            '{COMMENT_COUNT_TEXT}'   => $contents['other_reply_count_text'],
                        );

                        $contents['subject'] = $data['reply_from_salutation'] . ' ' . $contents['replied_to_a'] . $contents['replied_to_type'] . ' ' . $data['message_to'];
                    }
                    else // original message
                    {
                        $data['message_content']         = $contents['content'];
                        $data['message_from_salutation'] = $contents['sender_title'];

                        $values2insert = array (
                            '{MESSAGE_FULLNAME}'     => $data['message_from_salutation'],
                            '{MESSAGE_SENT_TO}'      => $data['message_to'],
                            '{MESSAGE_NOTIFICATION}' => $contents['content'],
                            '{MESSAGE_LINKS_TEXT}'   => $contents['links_text'],
                            '{MESSAGE_TYPE}'         => $messageLink['translatedType'],
                            '{MESSAGE_LINK}'         => $messageLink['formattedLink'],
                        );
                    }
                }
                else{
                    if ($messageData['type'] == 'text'){
                    //TODO if ab test is successful, these checks can be moved to a helper method
                        $should_use_phtml = true;
                        //get the profile url of the person sending the note
                        $profile_url = $recipientInfo['type'] == 'TEACHER' ? ENVIROMENT_DOMAIN.'/home#/profile/'.$account_info['user_id'] : ENVIROMENT_DOMAIN.'/home#/user?uid='.$account_info['user_id'];
                        //set template to the new ab email template
                        $template_name = TrackingHelper::EMAILTYPE_NOTIFICATIONS_5;
                        //build out email subject line depending on whether or not the email was sent to a group or not
                        $group_name = $contents['group_name'];
                        if (!empty($group_name)){
                            if (!empty($messageData['parent_group_title'])){
                                $group_name = $messageData['parent_group_title'];
                            }
                            $title_html = '<a href="'.$profile_url.'" style="color: #2ba6cb; text-decoration: none;">'.$contents['sender_title'].'</a> sent a<strong>'.$messageLink['translatedType'].'</strong> to <a href="'.ENVIROMENT_DOMAIN.'/home#/group?id='.$contents['group_id'].'" style="color: #2ba6cb; text-decoration: none;">'.$contents['group_name'].'</a>';
                            $footer_html = '<p style="padding:10px 0 10px 0;">You are receiving this email because you are subscribed to posts on <a href="'.ENVIROMENT_DOMAIN.'/home#/group?id='.$contents['group_id'].'" style="color: #2ba6cb; text-decoration: none;">'.$group_name.'</a>.</p>';
                        }
                        else{
                            $title_html = '<a href="'.$profile_url.'" style="color: #2ba6cb; text-decoration: none;">'.$contents['sender_title'].'</a> sent you a <strong>'.$messageLink['translatedType'].'</strong>';
                            $footer_html = '<p style="padding:10px 0 10px 0;">You are receiving this email because you are subscribed to direct posts.</p>';
                        }
                        $avatar_url = Profiles::getInstance()->getAvatarUrl($account_info['user_id'], $account_info['username'], 'AVATAR');

                        //grab the url of the first link and let's try to render a preview image of of that url
                        if (!empty($messageData['links'][0]['url'])){
                            $links_thumb = array();
                            $count_links = count($messageData['links']);
                            if ($count_links == 2){
                                $attachment_text = "<b>" .$count_links . '</b> attachments';
                            }
                            elseif ($count_links == 1){
                                $attachment_text = "<b>" .$count_links . '</b> attachment';
                            }

                            else{
                                $additional_attachments = $count_links - 2;
                                if ($additional_attachments == 1){
                                    $word = 'attachment';
                                }
                                else{
                                    $word = 'attachments';
                                }
                                $attachment_text = '<a href="'.$messageLink['formattedLink'].'"> Show '.$additional_attachments .' more ' .$word;
                            }
                            $i = 0;
                            $attachment_html = array();
                            foreach ($messageData['links'] as $link){
                                $parsed_url = trim($link['url'], '/');
                                if (!preg_match('#^http(s)?://#', $parsed_url)) {
                                    $parsed_url = 'http://' . $parsed_url;
                                }
                                $urlParts = parse_url($parsed_url);

                                // remove www
                                $domain = preg_replace('/^www\./', '', $urlParts['host']);

                                if ($i < 2){
                                    //first let's check to see if the webpage has open graph tags and grab it
                                    $open_graph_info = OpenGraphHelper::fetch($link['url']);
                                    $links_thumb[$i] = $open_graph_info->image;
                                    //we couldn't get the open graph image, let's try to grab it from ShrinkTheWeb
                                    if (empty($open_graph_info->image)){
                                        //sleep and give ShrinkTheWeb some time to generate the preview link thumb
                                        sleep(30);
                                        $link_info = Links::getInstance()->getMessagesLinks($messageData['message_id']);
                                        $stw_generated_thumb = $link_info[$i]['thumb_url'];
                                        if (!empty($stw_generated_thumb)){
                                            $links_thumb[$i] = THUMBS_SERVER.$stw_generated_thumb;
                                        }
                                        else{
                                            //set a default image if we still don't have one. Worse case scenario. oh well
                                            $links_thumb[$i] = PIC_SERVER.'default_155_2.jpg';
                                        }
                                    }
                                    $attachment_html[] = '<table width="540" cellpadding="0" cellspacing="0" border="0" align="center" class="devicewidthinner" style="border-top:1px solid rgb(194, 198, 197); border-bottom: 1px solid rgb(194, 198, 197);">
                                                                  <tbody>
                                                                     <tr>
                                                                        <td valign="middle" width="82" style="padding: 0px 20px 0px 20px;" class="link_tmb">
                                                                        <br />
                                                                           <div class="imgpop">
                                                                              <a href="'.$link['url'].'"><img src="'.$links_thumb[$i].'" alt="'.$link['desc'].'" border="0" style="border-radius:3px;display:block; border:none; outline:none; text-decoration:none;" st-image="edit" class="link_tmb" width="82" height="62"></a>
                                                                           </div>
                                                                           <br />
                                                                        </td>
                                                                        <td width="458" valign="middle" style="font-family: Helvetica, Arial, sans-serif;font-size: 15px; color: #2b2e38;line-height: 24px; padding: 0px 20px 0px 0px;" align="left" class="txtpad">

                                                                           <a href="'.$link['url'].'" style="text-align: left;font-family: Helvetica, Arial, sans-serif; font-size: 15px;line-height: 24px; font-weight:bold; text-decoration: none;" class="primaryLink">
                                                                            '.$link['desc'].'</a><br />
                                                                           <a href="#" class="secondaryLink">'.$domain.'</a>

                                                                        </td>

                                                                     </tr>



                                                                  </tbody>
                                                               </table>';

                                }
                                else{

                                    break;
                                }
                            $i++;
                            }
                        }

                        //build out an additional tracking array to so that we can track the ab test results. There are two running tests which can potentially collide so let's just put them both in the json string
                        $misc_data_tracking = array(TrackingHelper::MISCELLANEOUS_DATA_AB_TEST => array(TrackingHelper::MISCELLANEOUS_DATA_AB_TEST_NAME => 'notifications_link_email,  ', TrackingHelper::MISCELLANEOUS_DATA_AB_TEST_VARIANT => $notifications_test_name));

                        $attachment_html = implode('', $attachment_html);

                    }

                    //set default subject
                    $subject = $contents['subject'];

                   	//use default format of sender
                    $sender_str = "Edmodo";

                    if ($messageData['type'] == 'text' || $messageData['type'] == 'alert'){
                        $test_subject_line_gate = AbHandler::idInTest($recipientInfo['district_id'], 'notifications_subject_line_gate');
                        //make sure all eDistrict users are in the test, other users will take test if he/she is in the randomized bucket.
                        if ($test_subject_line_gate !== 'CONTROL'){
                            $test_subject_line = $test_subject_line_gate;
                        }else{
                            $test_subject_line = AbHandler::idInTest($recipientInfo['user_id'], 'notifications_subject_line');	
                        }
                        
                        $misc_data_tracking[TrackingHelper::MISCELLANEOUS_DATA_AB_TEST][TrackingHelper::MISCELLANEOUS_DATA_AB_TEST2_NAME] = 'notifications_subject_line';
                        $misc_data_tracking[TrackingHelper::MISCELLANEOUS_DATA_AB_TEST][TrackingHelper::MISCELLANEOUS_DATA_AB_TEST2_VARIANT] = $test_subject_line;
                        
                        

                        switch ($test_subject_line){
                            case 'sub1'://do the test if it is Alert
                                if ($messageData['type'] == 'alert'){
	                                $subject = self::getTestSubject('alert',$contents,$recipientInfo,$account_info);
	                                $sender_str = self::prepareEmailSender($account_info);
                                }
                                break;
                            case 'sub5'://do the test if it is Note
                                if ($messageData['type'] == 'text'){
                                    $subject = self::getTestSubject('text',$contents,$recipientInfo,$account_info);
                                    $sender_str = self::prepareEmailSender($account_info);
                                }
                                break;
                            case 'sub1_sub5'://do the test
	                            $subject = self::getTestSubject($messageData['type'],$contents,$recipientInfo,$account_info);
	                            $sender_str = self::prepareEmailSender($account_info);
	                            break;
                        }
                    }

                    $values2insert = array (
                        '{LINK}'                => $messageLink['formattedLink'],
                        '{TYPE}'                => $messageLink['translatedType'],
                        '{SENDER}'              => $contents['first-line'],
                        '{NOTIFICATION}'        => $contents['content'],
                        '{LINKS_TEXT}'          => $contents['links_text'],
                        '{SPACER}'              => '',
                        '{GROUP_NAME}'          => $contents['group_name'],
                        '{GROUP_URL}'           => ENVIROMENT_DOMAIN.'/home#/group?id='.$contents['group_id'],
                        '{SENDER_PIC}'          => isset($avatar_url) ? $avatar_url : null,
                        '{LINKS_THUMB}'         => isset($links_thumb) ? $links_thumb : null,
                        '{LINKS_HEIGHT}'        => isset($links_thumb) ? 62 : 0,
                        '{LINKS_TOP_SPACING}'   => isset($links_thumb) ? 20 : 0,
                        '{LINKS_BOTTOM_SPACING}'=> isset($links_thumb) ? 15 : 0,
                        '{LINKS_WIDTH}'         => isset($links_thumb) ? 82 : 0,
                        '{TITLE}'               => isset($title_html) ? $title_html : null,
                        '{FOOTER}'              => isset($footer_html) ? $footer_html : null,
                        '{SENDER_PROFILE_URL}'  => isset($profile_url) ? $profile_url : null,
                        '{CONTENT_LINK}'        => isset($messageData['links'][0]['url']) ? $messageData['links'][0]['url'] : null,
                        '{CTA_BUTTON}'          => isset($cta_btn) ? $cta_btn : null,
                        '{SUBJECT}'             => $subject,
                        '{ATTACHMENT_TEXT}'     => isset($attachment_text) ? $attachment_text : '',
                        '{ATTACHMENT_HTML}'     => isset($attachment_html) ? $attachment_html : null,
                        '{SEND_DATE}'           => $currentDate = date("F j, Y"),



                    );
                }
                $body_text = MailHandler::getEmailTemplate($template_name, $language, '', $should_use_phtml);
                $recipients = array (array ('email' => $recipientInfo['email'], 'username' => $recipientInfo ['username'] ) );

                $emailValidator = new Zend_Validate_EmailAddress();
                if ($emailValidator->isValid($recipientInfo['email'])) {
                    $mail_handler  = new MailHandler($body_text);
                    $tracking_data = TrackingHelper::makeTrackingData($template_name, $messageLink['type'], null, TrackingHelper::TYPE_SYSTEM, $recipientInfo['user_id'], TrackingHelper::TYPE_USER, $recipientInfo['email'], self::$msgID, TrackingHelper::TYPE_MESSAGE, null, $messageData[TrackingHelper::DATA_DATE_REQUESTED], $misc_data_tracking);
                    $response      = $mail_handler->setTrackingData($tracking_data)->sendEmailWrapper( $recipients, $subject, $values2insert, self::$replyTo, $sender_str, self::$replyTo, 'UTF-8',true );
                    unset( $mail_handler );
                    self::logActions( 'Sending an Email notification to <' . $recipientInfo ['email'] . '> status: ' . ($response ? 'SUCCESS' : 'FAILURE'), 2 );
                } else {
                    self::logActions( 'Unable to send an Email notification to <' . $recipientInfo ['email'] . '> The email is invalid', DEBUG_FULL );
                }
            }
            else
            {
                ActionsTracking::getInstance()->insert(array(
                    ActionsTrackingConstants::USER_AGENT => getenv('HTTP_USER_AGENT'),
                    ActionsTrackingConstants::EVENT_TYPE => 'notification-email',
                    ActionsTrackingConstants::EVENT_NAME => 'not-sent',
                    'recipient_uid' => $recipientInfo ['user_id'],
                    'recipient_email' => $recipientInfo ['email'],
                    'template_name' => 'Notifications',
                    'notification_type' => $messageData ['type']
                ));
            }
        } catch ( exception $e ) {
            self::logActions( "\n" . 'sendEmail: ERROR: An Exception was thrown: ' . $e->getMessage(), DEBUG_CATCHS);
        }
    }
    
    /**
     *  format email sender info
     *  @param account_info array
     *  @return string
     */
    private function prepareEmailSender($account_info)
    {
	    $sender = ucfirst($account_info['first_name']).' '.ucfirst($account_info['last_name']).' via Edmodo';
	    return $sender;
    }

   /**
    * Truncated subject line
    * @param content as string
    * @param old_subject as string
    * @return string
    */
   private function prepareTruncatedSubjectLine($content, $old_subject)
   {
	 	//wipe out any wacky characters in the subject line
	    $content = str_replace("<br />"," ",$content);
        $subject = strip_tags(html_entity_decode($content, ENT_QUOTES));
	    if (strlen($subject) > 84){
	        $spliced_subject = substr($subject, 0, 82);
	        $subject = $spliced_subject . '...';
	    }
	    return $subject;
   }

    /**
     * Prepare subject based on different abtest variants
     * @param array $contents the message contents
     * @param array $recipientInfo information for this destination
     * @param array $account_info - optional - account info of user sending the note
     * @return subject as string
     */
	private function getTestSubject($msg_type,$contents,$recipientInfo,$account_info)
	{
		$subject = $contents['subject'];
		$subject_body = self::prepareTruncatedSubjectLine($contents['content'],$subject);
		if (!empty($contents['group_name']) && $recipientInfo['type'] == 'PARENT'){
	        $student_id = ParentsStudents::getInstance()->getParentStudentsByGroup($recipientInfo['user_id'], $contents['group_id']);
	        if (count($student_id) == 1){
	            $student_info = Users::getInstance()->getUserInfo($student_id[0]);
	        }
	        if (!empty($student_info['first_name'])){
	            $group_first_name_position = strpos($contents['group_name'], '(' . $student_info['first_name'] . ')');
	            $spliced_parent_group_name = substr($contents['group_name'], 0, $group_first_name_position);
	            switch ($msg_type){
		            case 'alert':
		                $subject = '[' . $student_info['first_name'] .' - Alert to ' . $spliced_parent_group_name . '] ' . $subject_body;
		                break;
		            case 'text':
		                $subject = '[' . $student_info['first_name'] .' - ' . $spliced_parent_group_name . '] ' . $subject_body;
		                break;               
	            }
	        }
	        else {
		        switch ($msg_type){
			        case 'alert':
			            $subject = 'Alert to ' . $contents['group_name'] . '] ' . $subject_body;
			            break;
			        case 'text':
			            $subject = '[' . $contents['group_name'] . '] ' . $subject_body;
			            break;
		        }
	        }
	    }
	    //alert through group with non-parent recipient
	    elseif (!empty($contents['group_name']) && $recipientInfo['type'] != 'PARENT'){
            switch ($msg_type){
                case 'alert':
                    $subject = '[Alert to ' . $contents['group_name'] . '] ' . $subject_body;
                    break;
                case 'text':
                    $subject = '[' . $contents['group_name'] . '] ' . $subject_body;
                    break;
            }
	    }
	    else{
		    switch ($msg_type){
			    case 'alert':
			        $subject = '[Alert]' . ' ' .$subject_body;
			        break;
			    case 'text':
			        $subject = $subject_body;
			        break;
			}
	    }
	    return $subject;
	}

    /**
     * Sends the SMS message, this is really an email using the phone carrier
     * @param array $usrSettings the notification settings
     * @param array $contents the message contents
     * @param array $recipientInfo information for this destination
     * @return array the subject, first line, and content of the message
     */
    private function sendSms($usrSettings, $contents, $recipientInfo) {
        try {
            if ($usrSettings ['phone_verified'] == 1) {
                $message = $contents ['first-line'] . ' ' . $contents ['content'];
                $carrier = PhoneCarriers::getInstance()->getCarrierInfo( $usrSettings ['phone_carrier_id'] );
                $email = $usrSettings ['via'] . '@' . $carrier ['domain'];

                $mail_handler = new MailHandler( $message );
                $response = $mail_handler->sendSMS( $message, $email, $recipientInfo ['username'], self::$replyTo );
                unset( $mail_handler );
                if ($response) {
                    self::logActions( 'Sending an SMS notification to <' . $email . '> status: ' . ($response ? 'SUCCESS' : 'FAILURE'), DEBUG_FULL );
                } else {
                    self::logActions( 'Unable to send an SMS notification to <' . $email . '> The email is invalid', DEBUG_FULL );
                }
            } else {
                self::logActions( 'Unable to send an SMS notification to <' . $usrSettings ['via'] . '> The phone is not verified', DEBUG_FULL );
            }
        } catch ( exception $e ) {
            self::logActions( "\n" . 'sendSMS: ERROR: An Exception was thrown: ' . $e->getMessage(), DEBUG_CATCHS );
        }
    }

    /**
     * Checks what level of DEBUG is on, and adds messages according to that
     * @param string $entryLog the message to log
     * @param int $level the Level to log: 0=No log, 1=Catch messages, 2=all info
     * @return
     */
    private static function logActions($entryLog, $level = 0) {
        if (GEARMAN_DEBUG && GEARMAN_DEBUG >= $level) {
            self::$log .= '[' . date( 'd/M/Y:G:i:s O' ) . '] ' . $entryLog . "\n";
        }
    }

    /**
     * actually writes the log to a file
     * @param string $nameAppend optional, to dinstinct the caller to the log: c = construct, d=destruct, e=email warning
     * @return
     */
    private static function writeLog($nameAppend = '') {

        //-------------------------
        // Log the actions into a specific log file
        try {
            if (GEARMAN_DEBUG && GEARMAN_DEBUG > 0) {
                $log_dir = '/tmp/gearman-logs';
                if (! is_dir( $log_dir )) {
                    mkdir( $log_dir, 0777, true );
                }
                $logfile = $log_dir . '/send_notifications_' . $nameAppend . getmypid() . '.txt';
                $handle = fopen( $logfile, 'a' );
                if ($handle == FALSE) {
                    trigger_error( "unable to open file '$logfile'", E_USER_WARNING );
                    return FALSE;
                }
                fwrite( $handle, self::$log );
                fclose( $handle );
                self::$log = NULL;
                //Should comment this? its ok, output is being sent to /dev/null
                echo self::$log . "\n\n\n";
            }

        } catch ( exception $e ) {
            trigger_error( $e->getMessage(), E_USER_WARNING );
        }
    }

    /**
     * Sends a warning email if code ever reaches where it sould not
     * @return
     */
    private function sendWarningEmail() {

        try {
            $body_text = ' One of the Workers is down at http://www.' . ENVIROMENT_DOMAIN; //At the server name

            /**
             * this check is deprecated because groundwork can monitor processes and false alarms result when testing configurations- for now just email webops and jack
             * @return
             */
            $recipients = array(
                array (
                    'email'    => WEBOPS_EMAIL,
                    'username' => 'WebOps',
                ),
            );

            $mail_handler  = new MailHandler( $body_text );
            $tracking_data = TrackingHelper::makeTrackingData(TrackingHelper::EMAILTYPE_REPORT_A_BUG, null, null, TrackingHelper::TYPE_SYSTEM, null, TrackingHelper::TYPE_INTERNAL, WEBOPS_EMAIL);
            $response      = $mail_handler->setTrackingData($tracking_data)->sendEmailWrapper( $recipients, 'Urgent!', array (), SUPPORT_EMAIL, 'Gearman Worker', SUPPORT_EMAIL);
            unset( $mail_handler );

        } catch ( exception $e ) {
            self::logActions( "\n" . ' ERROR: Sending Warning Email ' . $e->getMessage() . "\n", DEBUG_CATCHS );
            self::writeLog( 'e_' );
        }
    }

    /**
     * Ensures that a minumum number of workers are always running.
     * The amount of workers is controlled by the expected workers variable on appplication.ini
     */
    public static function watchAndRaiseWorkers() {
        //count how many workers are running
        $cmd = "ps -C php -F| grep " . basename( __FILE__ );
        exec( $cmd, $output, $return_var );
        $runningworkers = count( $output );
        if ($runningworkers < self::$expectedWorkersNumber) { // if not enough workers are running then start more
            // start N workers
            for($i = 0; $i < self::$expectedWorkersNumber - $runningworkers; $i ++) {
                self::logActions( "starting worker", DEBUG_FULL );
                exec( 'nohup php NotificationsWorker.php > /dev/null 2>&1 &' );
            }
        }
    }

    function sig_handler($signo) {
        switch ($signo) {
            case SIGTERM :
                // do any cleanup
                $this->shutdown = TRUE;
                self::logActions( "Recieved kill command", DEBUG_FULL );
                self::writeLog();
                exit( 0 );
        }

    }

}
