<?php
/**
 *
 * @category   Edmodo
 * @package    Edmodo_Helper
 * @copyright  Copyright (c) 2012 Edmodo USA Inc. (http://www.edmodo.com)
 *
 */
/**
 *
 * @category   Edmodo
 * @package    Edmodo_Helper
 *
 */
class MessagingHelper
{
    const MESSAGE='message';
    const MESSAGE_ID='message_id';
    const CREATOR_ID='creator_id';
    const TYPE='type';
    const CREATION_DATE='creation_date';
    const COLUMN_PUBLIC='public';

    const MESSAGE_TYPE_TEXT='text';
    const MESSAGE_TYPE_LINK='link';
    const MESSAGE_TYPE_VIDEO='video';
    const MESSAGE_TYPE_FILE='file';
    const MESSAGE_TYPE_ASSIGNMENT='assignment';
    const MESSAGE_TYPE_SYSTEM='system';
    const MESSAGE_TYPE_COMMENT='comment';
    const MESSAGE_TYPE_ALERT='alert';
    const MESSAGE_TYPE_GRADE='grade';
    const MESSAGE_TYPE_POLL='poll';
    const MESSAGE_TYPE_FEED='feed';
    const MESSAGE_TYPE_EMBED='embed';
    const MESSAGE_TYPE_QUIZ='quiz';
    const MESSAGE_TYPE_APP_MESSAGE='app_message';
    
    public static $VALID_MESSAGE_TYPES = array(
        MessagingHelper::MESSAGE_TYPE_TEXT,
        MessagingHelper::MESSAGE_TYPE_LINK,
        MessagingHelper::MESSAGE_TYPE_VIDEO,
        MessagingHelper::MESSAGE_TYPE_FILE,
        MessagingHelper::MESSAGE_TYPE_ASSIGNMENT,
        MessagingHelper::MESSAGE_TYPE_SYSTEM,
        MessagingHelper::MESSAGE_TYPE_COMMENT,
        MessagingHelper::MESSAGE_TYPE_ALERT,
        MessagingHelper::MESSAGE_TYPE_GRADE,
        MessagingHelper::MESSAGE_TYPE_POLL,
        MessagingHelper::MESSAGE_TYPE_FEED,
        MessagingHelper::MESSAGE_TYPE_EMBED,
        MessagingHelper::MESSAGE_TYPE_QUIZ,
        MessagingHelper::MESSAGE_TYPE_APP_MESSAGE,
    );

    private $use_gearman = NOTIFICATIONS_USE_GEARMAN;
    private $last_direct_msg_receivers;
    private $last_indirect_msg_receivers;
    private $spotted_replies;
    private $feed_type;
    private $user_id;
    private $user_groups;
    private $community_groups;
    private $parent_info;
    private $institutional_info;
    private static $layout_translator;
    private static $system_msgs_translator;

    public $post_limit_reached = false;


    public static $is_api_call = false;
    public static $limit_comments_query = true;

    /**
     *
     * @var MessagingHelper
     */
    private static $_instance;


    /**
     * Implements the "singleton" pattern for this class
     *
     * @param string $feed_type
     * @return MessagingHelper
     */
    static function getInstance ($feed_type = 'HOME_FEED')
    {
        if (! isset(self::$_instance)) {
            self::$_instance = new self($feed_type);
        } else {
            self::$_instance->setFeedType($feed_type);
        }
        return self::$_instance;
    }

    /**
     * Set class instance
     * Use only to mock/stub instance for tests
     *
     * @param MessagingHelper $instance
     * @throws Exception
     */
    static function setInstance ($instance)
    {
        if (! $instance instanceof MessagingHelper) {
            throw new Exception('Invalid instance class');
        }
        self::$_instance = $instance;
    }

    /**
     */
    static function clearInstance ()
    {
        self::$_instance = null;
    }

    private function __construct($feed_type = 'HOME_FEED')
    {
        //----------------------------
        // Stores the user_ids of the users who received the msg
        // directly (people typed their names in the sharebox)
        $this->last_direct_msg_receivers = array();

        //----------------------------
        // Stores the user_ids of the users who received the msg
        // indirectly (they are part of a group who received a post)
        $this->last_indirect_msg_receivers = array();

        //----------------------------
        // Stores a boolean map of the comments that have been already spotted by the user
        $this->spotted_replies = array();

        //----------------------------
        // Stores a boolean map of the comments that have been already spotted by the user
        $this->feed_type = $feed_type;

        //----------------------------
        // Stores id of the user of the feed we are looking at
        $this->viewed_user_id = 0;

        //----------------------------
        // Stores the status of the user with respect to the community stream being viewed
        $this->community_user_status = null;

        //----------------------------
        // Stores the groups that the user whose feed we are looking at is part of
        $this->viewed_user_groups = array();

        //----------------------------
        // Stores the groups that are being viewed as a receiver on the community page
        $this->community_groups = array();

        //----------------------------
        // Stores the schools/districts that the user whose feed we are looking at is part of
        $this->institutional_info = array();

        //Stores the parent's info about his students
        $this->parent_info = array();

        //----------------------------
        // Layout translator
        self::$layout_translator = null;

        //----------------------------
        // System messages translator
        self::$system_msgs_translator = null;
    }

    public function setFeedType($feed_type = 'HOME_FEED')
    {
        $this->feed_type = $feed_type;
    }

    public static function initializeTranslators($language){
        if(!isset(self::$layout_translator)){
//            self::$layout_translator = new TranslationHelper(new Zend_Translate('tmx', PATH2_LANGUAGES . 'layout.tmx', $language));
            self::$layout_translator = TranslationHelper::getInstance(PATH2_LANGUAGES . 'layout.tmx', $language);

        }
        if(!isset(self::$system_msgs_translator)){
//            self::$system_msgs_translator = new TranslationHelper(new Zend_Translate('tmx', PATH2_LANGUAGES . 'system.tmx', $language));
            self::$system_msgs_translator = TranslationHelper::getInstance(PATH2_LANGUAGES . 'system.tmx', $language);
        }
    }

    /**
     * This sends a message from the system to a user
     * @param string $message_identifier is the system message identifier
     * @param array $receiver_id is the id of the intended message recipient
     */
    public function sendSystemMessage($message_identifier, $receiver_ids = array()){
        $message_data = array(
            'type'    => 'system',
            'message' => $message_identifier
        );

        $this->sendMessage(
            $message_data,
            array('people' => $receiver_ids),
            Users::getInstance()->getUserInfo(EDMODO_SYSTEM_USER_ID)
        );
    }

    /**
     * This sends a message to one or more peers
     * @param array   $message_data        contains the message's content plus additional info about the message
     * @param array   $receivers           contains the intended message recipients (people or locations or both)
     * @param array   $sender_info         is the sender's account info.
     * @param Boolean $resource_resending  weather this message is being sent to resend a resource (eg from the Library).
     * @param $attached_resources
     * @param bool $check_send_rights, flag to indicate if a check should be performed to verify that the sender has rights to send the message
     * @param bool $use_response_obj_return, flag to indicate if a HandlerResponseObj should be returned instead of the normal returns
     * @param bool $bypass_moderation, flag to indicate if the message should be sent even if the poster is a student and one of the recipients a moderated group
     * @return message_id
     */
    public function sendMessage($message_data, $receivers, $sender_info, $resource_resending = FALSE, $attached_resources = '', $check_send_rights = true, $use_response_obj_return = false, $bypass_moderation = false )
    {
        $response_obj = new HandlerResponseObj();

        if($this->isSpammer($sender_info, $message_data)){
            if($use_response_obj_return){
                $response_obj->setUnsuccessful();
                $response_obj->post_limit_reached = true;
                $response_obj->addError('User has reached maximum limit of posts for the last hour');
                return $response_obj;
            }else{
                return false;
            }
        }

        if($check_send_rights){
            if ( $sender_info['user_id'] != EDMODO_SYSTEM_USER_ID && !$this->userHasRightsToSendMessage( $sender_info, $receivers, $message_data ))
            {
                if ($use_response_obj_return) {
                    $response_obj->setUnsuccessful();
                    $response_obj->addError('User does not have rights to send message to the specified recipients');
                    return $response_obj;
                } else {
                    return false;
                }
            }
        }
        $community_id=null;

        // Messages are only sent immediately if none of the recipients is a moderated group
        if ( !$bypass_moderation && !empty($receivers['locations']) ){
            $groups_db = Groups::getInstance();
            foreach ( $receivers['locations'] as $location ){
                if ( $location['type'] == 'group' ){
                    $group = $groups_db->find($location['id'])->current();

                    // For small groups, the parent group is checked for moderation
                    $main_group_info = SmallGroups::getInstance()->getGroupsMainGroup($location['id']);
                    if ( empty($main_group_info) ){
                        $main_group_info = $group;
                    }

                    if ( $main_group_info['moderated'] && ( $sender_info['type'] == 'STUDENT' || !$groups_db->userOwnsGroup( $sender_info['user_id'], $main_group_info['group_id'], true )) ){
                        // Post moderated message here
                        ModeratedMessagesHandler::sendModeratedMessage($message_data, $attached_resources, $group, $sender_info);

                        if ($use_response_obj_return) {
                            $response_obj->setSuccessful();
                            $response_obj->is_moderated = true;
                            $response_obj->message_id = 0;
                            $response_obj->scheduled_message_id = 0;
                            return $response_obj;
                        } else {
                            return false;
                        }
                    }
                }
            }
        }

        // Scheduled message
        if ( $sender_info['type'] == 'TEACHER' && isset($message_data['scheduled']) && $message_data['scheduled'] ){
            $scheduled_message_id = ScheduledMessagesHandler::sendScheduledMessage($message_data, $attached_resources, $receivers, $sender_info);
            if ($use_response_obj_return) {
                $response_obj->setSuccessful();
                $response_obj->is_scheduled = true;
                $response_obj->message_id = 0;
                $response_obj->scheduled_message_id = $scheduled_message_id;
                return $response_obj;
            } else {
                return $scheduled_message_id;
            }
        }

        if(empty($message_data['posted_in']))    $message_data['posted_in'] = null;
        if(empty($message_data['posted_in_id'])) $message_data['posted_in_id'] = null;
        if(empty($message_data['comment_to']))   $message_data['comment_to'] = null;

        $message_data['other_reply_count'] = 0;
        if ($message_data['comment_to']) {
            $message_data['other_reply_count'] = ArrayHelper::elem(NewComments::getInstance()->getCommentCount(array($message_data['comment_to'])), $message_data['comment_to'], 1) - 1;
        }

        //--------------------------------
        // Clean up the Message Receiver arrays
        $this->last_direct_msg_receivers= array();
        $this->last_indirect_msg_receivers= array();

        // STORE MESSAGE
        $message = array(
            'creator_id' => $sender_info['user_id'],
            'type'       => $message_data['type'],
            'comment_to' => $message_data['comment_to']
        );
        if (isset($message_data['creation_date'])) {
            $message['creation_date'] = $message_data['creation_date'];
        }

        $message_id = Messages::getInstance()->store( $message );
        // send a 'community post' message to the activity stream
        // add to the user's share score
        if(isset($receivers['locations'])){
            foreach ( $receivers['locations'] as $location ){
                if($location['type']=='community'){
					//ActivityHandler::triggerCommunityPost($location['id'],$message_id,ActivityCommunityPost::TYPE_SUBJECT);
                    ShareScore::getInstance()->incrementShareScoreByValue($sender_info['user_id'], 10);
                }
            }
        }

		/*
		if(isset($receivers['people'])){
            foreach($receivers['people'] as $user_id){
                $user=Users::getInstance()->find($user_id);
                if(isset($user[Users::TYPE]) && $user[Users::TYPE]==Users::TYPE_PUBLISHER){
                    ActivityHandler::triggerCommunityPost($user_id,$message_id,ActivityCommunityPost::TYPE_PUBLISHER);
                }
            }
        }
		*/

        // STORE THINGS ASSOCIATED WITH MESSAGE
        $store_message_data_response = $this->storeMessageData($message_id, $message_data, $sender_info, $receivers, $resource_resending, $attached_resources);

        if (isset($store_message_data_response['assignment_id'])) {
            $response_obj->assignment_id = $store_message_data_response['assignment_id'];
        }

        if (isset($store_message_data_response['quiz_run_id'])) {
            $response_obj->quiz_run_id = $store_message_data_response['quiz_run_id'];
        }

        $has_link_or_embed = $store_message_data_response['has_link_or_embed'];
        $message_data['has_community_relevance'] = $has_link_or_embed ? $this->hasCommunityRelevance($message_data) : false;

        $this->storeMessageReceiversNotification($sender_info, $message_id, $receivers, $message_data);

        // System messages for which a notification won't be sent
        $silent_system_messages = array('added-to-small-group', 'twitter-off-no-email', 'twitter-off-with-email');
        $send_system_message = true;
        foreach ( $silent_system_messages as $silent_system_message ){
            if ( strpos($message_data['message'], $silent_system_message) == false ){
                $send_system_message = false;
                break;
            }
        }

        // Notifications are sent
        if( ENABLE_EMAIL_NOTIFICATIONS && $message_data['type'] != MessagingHelper::MESSAGE_TYPE_FEED && $message_data['type'] != MessagingHelper::MESSAGE_TYPE_APP_MESSAGE && ($message_data['type'] != MessagingHelper::MESSAGE_TYPE_SYSTEM || $send_system_message ) ) {
            $message_data['message_id'] = $message_id;

            if ($message_data['type'] == 'quiz') {
                $quiz_info                        = Quiz::getInstance()->search(array('quiz_id' => $message_data['quiz_id']));
                $message_data['quiz_title']       = $quiz_info[0]['title'];
                $message_data['quiz_description'] = $quiz_info[0]['description'];
            }

            $message_data[TrackingHelper::DATA_DATE_REQUESTED] = time();
            $workerArray = array(
                'message_data'       => $message_data,
                'selected_receivers' => $receivers,
                'account_info'       => $sender_info,
                'server_name'        => ENVIROMENT_DOMAIN //will now use this .ini variable that should be: "edmodo.com", "edmodoqa.com", or "clubmodo.com" for dev env
            );

            $serialized_data = serialize($workerArray);

            if ($this->use_gearman)
            {
                GearmanHelper::getInstance()->doBackgroundJob('sendNotifications', $serialized_data);
            }
            else
            {
                NotificationsWorker::send_notifications(new NotificationTestJob($serialized_data));
            }
        }

        $this->setMaxMessageIdForReceivers($sender_info['user_id'], $receivers, $message_id);

        // Add this post to the publisher's cached messages
        if ( $sender_info['type'] == 'PUBLISHER' ){
            Messages::getInstance()->cachePublisherMessageId( $sender_info['user_id'], $message_id );
        }

        $this->preProcessPossibleGdocs($message_data, $attached_resources);

        //Processing google docs
        if(isset($message_data['google_docs']) && !empty($message_data['google_docs']))
        {
            $google_handler = GoogleApiHandler::getInstance();
            if(isset($message_data['google_docs']['docs_info'])){

            }
            $docs_info = isset($message_data['google_docs']['docs_info']) ? $message_data['google_docs']['docs_info']: NULL;
            $role = isset($message_data['google_docs']['role']) ? $message_data['google_docs']['role']: NULL;
            $scope = isset($message_data['google_docs']['scope']) ? $message_data['google_docs']['scope']: NULL;

            $google_info = UsersGoogleInfo::getInstance()->getUserGoogleInfo( $sender_info["user_id"] );

            if(is_array($google_info))
                $full_info = array_merge($sender_info,$google_info);
            else
                $full_info = $sender_info;

            $message_data_tmp = $message_data;
            $message_data_tmp['message_id'] = $message_id;
            $message_data_tmp['obj_type'] = 'MESSAGE';
            $gdocs_links = $google_handler->setPermissionsForReceivers($docs_info,$receivers,$full_info,$role,$scope, $message_data_tmp);
        }

        if ($use_response_obj_return) {
            $response_obj->setSuccessful();
            $response_obj->is_moderated = false;
            $response_obj->message_id = $message_id;
            $response_obj->scheduled_message_id = 0;

            return $response_obj;
        } else {
            return $message_id;
        }
    }

    private function isSpammer($sender_info, $message_data){
        $is_spammer = false;
        $user_id = $sender_info['user_id'];

        if($message_data['type'] != 'feed' && $user_id != EDMODO_SYSTEM_USER_ID){
            $rate_limiter = RateLimitHandler::getInstance();
            $rate_limiter->increment('posts_per_user_per_hour', $user_id);
            if ($rate_limiter->isLimited('posts_per_user_per_hour', $user_id)) {
                $is_spammer = true;
            }
        }
        return $is_spammer;
    }

    public function sendComment($comment_data, $receivers, $sender_info, $check_send_rights = true, $bypass_moderation = false, $use_response_obj_return = false)
    {
        $response_obj = new HandlerResponseObj();
        if($check_send_rights){
            if ( $sender_info['user_id'] != EDMODO_SYSTEM_USER_ID && !$this->userHasRightsToSendMessage( $sender_info, $receivers, $comment_data, true )){
                if ($use_response_obj_return) {
                    $response_obj->setUnsuccessful();
                    $response_obj->addError('User does not have rights to reply to this message.');
                    return $response_obj;
                } else {
                    return false;
                }
            }
        }
        // check that the number of replies to this post has not exceeded the limit
        if(NewComments::getInstance()->postHasExceededReplyCount($comment_data['comment_to'])){
            // update the message's last updated timestamp to indicate that it should be re-fetched by clients that cache the data (e.g. Android App)
            if (!MessageData::getInstance()->updateMessageLastUpdatedTime($comment_data['comment_to'])) {
                throw new Exception('Failed to update message last updated timestamp');
            }

            if ($use_response_obj_return) {
                $response_obj->setUnsuccessful();
                $response_obj->reply_count_exceeded = true;
                $response_obj->addError('Reply count exceeded for this post.');
                return $response_obj;
            } else {
                return false;
            }
        }

        // Comments sent by students are only sent immediately if none of the recipients of the original message is a moderated group
        if ( !$bypass_moderation && !empty($receivers['locations']) ){
            $groups_db = Groups::getInstance();
            foreach ( $receivers['locations'] as $location ){
                if ( $location['type'] == 'group' ){
                    $group = $groups_db->find($location['id'])->current();
                    if ( $group['moderated'] && ( $sender_info['type'] == 'STUDENT' || !$groups_db->userOwnsGroup( $sender_info['user_id'], $group['group_id'], true )) ){
                        // Post moderated message here
                        $comment_data['group_id'] = $group['group_id'];
                        ModeratedMessagesHandler::sendModeratedComment($comment_data, $receivers['locations'], $sender_info);

                        if ($use_response_obj_return) {
                            $response_obj->setSuccessful();
                            $response_obj->is_moderated = true;
                            $response_obj->comment_id = 0;
                            return $response_obj;
                        } else {
                            return false;
                        }
                    }
                }
            }
        }

        if(empty($comment_data['posted_in']))    $comment_data['posted_in'] = null;
        if(empty($comment_data['posted_in_id'])) $comment_data['posted_in_id'] = null;
        $comment_data['type'] = 'comment';

        //--------------------------------
        // Clean up the Message Receiver arrays
        $this->last_direct_msg_receivers= array();
        $this->last_indirect_msg_receivers= array();

        // STORE MESSAGE
        $comment = array(
            'creator_id' => $sender_info['user_id'],
            'comment_to' => $comment_data['comment_to'],
            'content'    => $comment_data['message']
        );
        if (isset($comment_data['creation_date'])) {
            $comment['creation_date'] = $comment_data['creation_date'];
        }

        $comment_id = NewComments::getInstance()->store( $comment );

        $comment_data['other_reply_count'] = 0;
        if ($comment_data['comment_to']) {
            $comment_data['other_reply_count'] = ArrayHelper::elem(NewComments::getInstance()->getCommentCount(array($comment_data['comment_to'])), $comment_data['comment_to'], 1) - 1;
        }

        // Parse the assignment's description for latex math expressions and generate images
        LatexHandler::getInstance()->extractMathExpressions($comment_data['message']);

        $this->storeMessageReceiversNotification($sender_info, $comment_id, $receivers, $comment_data, true);

        if( ENABLE_EMAIL_NOTIFICATIONS ){
            $comment_data['comment_id'] = $comment_id;
            $comment_data[TrackingHelper::DATA_DATE_REQUESTED] = time();
            $workerArray = array(
                'message_data' => $comment_data,
                'selected_receivers'=> $receivers,
                'account_info' => $sender_info,
                'server_name' => $_SERVER['HTTP_HOST']
            );

            $serialized_data = serialize($workerArray);
            if ($this->use_gearman)
            {
                GearmanHelper::getInstance()->doBackgroundJob('sendNotifications', $serialized_data);
            }
            else
            {
                NotificationsWorker::send_notifications(new NotificationTestJob($serialized_data));
            }
        }

        $this->setMaxMessageIdForReceivers($sender_info['user_id'], $receivers, $comment_id, true);

        if ($use_response_obj_return) {
            $response_obj->setSuccessful();
            $response_obj->is_moderated = false;
            $response_obj->comment_id = $comment_id;

            return $response_obj;
        } else {
            return $comment_id;
        }
    }

    private function hasCommunityRelevance( $message_data ){
        $has_community_relevance = true;

        $unwanted_urls = array(
            'spreadsheets.google.com',
            'docs.google.com',
            'chalk.edmodo.com',
            'surveymonkey.com',
            'pbworks.com',
            'sites.google.com',
            'coveritlive.com'
        );

        if (isset( $message_data['links'] )){
            foreach ( $message_data['links'] as $link ){
                foreach ( $unwanted_urls as $unwanted_url ){
                    if ( strpos($link['url'], $unwanted_url) !== false ){
                        $has_community_relevance = false;
                        break;
                    }
                }
            }
        }
        if ($has_community_relevance && isset( $message_data['embeds'] )){
            foreach ( $message_data['embeds'] as $embed ){
                foreach ( $unwanted_urls as $unwanted_url ){
                    if ( strpos($embed['url'], $unwanted_url) !== false ){
                        $has_community_relevance = false;
                        break;
                    }
                }
            }
        }
        return $has_community_relevance;
    }

    private function setMaxMessageIdForReceivers($sender_id, $receivers, $message_id, $is_comment = false){
        $cache_handler = MemcacheHandler::getInstance();
        if ( $cache_handler->cachingAvailable() ){
            $user_ids = array();
            $user_ids[] = $sender_id;

            if(isset($receivers['people']))
            {
                foreach( $receivers['people'] as $person ){
                    $user_ids[] = $person;
                }
            }

            if(!empty($receivers['locations'])){
                foreach( $receivers['locations'] as $location ){
                    switch( $location['type'] ){
                        case 'all-groups':
                            $groups_db = Groups::getInstance();
                            $groups    = $groups_db->getUserGroups($sender_id);
                            foreach( $groups as $group ){
                                $members = $groups_db->getGroupMembers($group['group_id'], true);
                                if(count($members) < 100){
                                    //if the group is too big, don't do this
                                    foreach( $members as $member ){
                                        $user_ids[] = $member['user_id'];
                                    }
                                }

                            }
                            break;
                        case 'connections':
                            $connections = Connections::getInstance()->getConnectedUserIds($sender_id);
                            foreach( $connections as $connection_id ){
                                $user_ids[] = $connection_id;
                            }
                            break;
                        case 'all':
                            $groups_db = Groups::getInstance();
                            $groups    = $groups_db->getUserGroups($sender_id);
                            foreach( $groups as $group ){
                                $members = $groups_db->getGroupMembers($group['group_id'], true);
                                foreach( $members as $member ){
                                    $user_ids[] = $member['user_id'];
                                }
                            }
                            $connections = Connections::getInstance()->getConnectedUserIds($sender_id);
                            foreach( $connections as $connection_id){
                                $user_ids[] = $connection_id;
                            }
                            break;
                        case 'group':
                            $members = Groups::getInstance()->getGroupMembers($location['id'], true);
                            if(count($members) < 100){
                                foreach( $members as $member ){
                                    $user_ids[] = $member['user_id'];
                                }
                            }
                            break;
                        case 'group-parents':
                            $parents = ParentsStudents::getParents($location['id'], true);
                            foreach( $parents as $parent ){
                                $user_ids[] = $parent['parent_id'];
                            }
                            break;
                        case 'school':
                            $member_ids = Schools::getInstance()->getSchoolMembers($location['id']);
                            if(count($member_ids) < 1000){
                                $user_ids = array_merge($user_ids, $member_ids);
                            }
                            break;
                        case 'school_vip':
                            $member_ids = Schools::getInstance()->getSchoolMembers($location['id'], true, 'teachers-admins');
                            $user_ids = array_merge($user_ids, $member_ids);
                            break;
                        case 'district':
                            $member_ids = Districts::getInstance()->getDistrictMembers($location['id']);
                            if(count($member_ids) < 2000){
                                $user_ids = array_merge($user_ids, $member_ids);
                            }
                            break;
                        case 'district_vip':
                            $member_ids = Districts::getInstance()->getDistrictMembers($location['id'], true, 'teachers-admins');
                            if(count($member_ids) < 1000){
                                $user_ids = array_merge($user_ids, $member_ids);
                            }
                            break;
                    }
                }
            }
            $max_identifier = 'max_message_id';
            if ( $is_comment ){
                $max_identifier = 'max_comment_id';
            }
            foreach( $user_ids as $user_id ){
                $cache_handler->save($message_id, $user_id.$max_identifier);
            }
        }
    }


    /**
     * Stores the message's data
     * @return array An array containing two keys: has_link_or_embed and assignment_id
     */
    private function storeMessageData
    ($message_id, $message_data, $sender_info, $receivers = null, $resource_resending = FALSE, $attached_resources = '')
    {
        $response = array();
        $has_link_or_embed = false;

        $content = $message_data['message'];

        // handle special post types
        switch ($message_data['type']){
            case ASSIGNMENT:
                // For some reason, message_data sometimes does not have this optional field.
                //   Setting to NULL, because Assignments->store() checks to see if this field = NULL.
                if (empty($message_data['default_total'])) {
                    $message_data['default_total'] = null;
                }
                
                // if lock_after_due is not specified, set it to 0 by default
                $lock_after_due = isset($message_data['lock_after_due']) ? $message_data['lock_after_due'] : 0;
                
                $assignment_id = Assignments::getInstance()->store($message_id, $message_data['assignment-description'], $message_data['due-date'], $message_data['default_total'], $lock_after_due);
                $response['assignment_id'] = $assignment_id;
                $content = $message_data[ASSIGNMENT];
                // Parse the assignment's description for latex math expressions and generate images
                LatexHandler::getInstance()->extractMathExpressions($message_data['assignment-description']);
                break;
            case 'quiz':
                $quiz_info = Quiz::getInstance()->search(array('quiz_id' => $message_data['quiz_id']));
                $record = array
                (
                    QuizRun::QUIZ_ID=>$message_data['quiz_id'],
                    QuizRun::DUE_DATE=>$message_data['quiz-due-date'],
                    QuizRun::AUTO_GRADE=>'1',
                    QuizRun::SAVE_TO_GRADEBOOK=>$message_data['auto-grade'],
                    QuizRun::MESSAGE_ID=>$message_id,
                    QuizRun::SHOW_RESULTS=>$quiz_info[0]['show_results'],
                    QuizRun::TIME_LIMIT=>$quiz_info[0]['time_limit'],
                    QuizRun::STATUS=>QuizRun::STATUS_ASSIGNED,
                );

                $quizRunModel=QuizRun::getInstance();
                $quiz_run_id=$quizRunModel->store($record);

                //delete any 'preview' quiz_runs for this $quiz_id
                $quiz_runs=$quizRunModel->search(array(QuizRun::QUIZ_ID=>$message_data['quiz_id'],QuizRun::PREVIEW=>'1'));
                foreach($quiz_runs as $quiz_run){
                    $quizRunModel->delete($quiz_run[QuizRun::QUIZ_RUN_ID]);
                }
                $response['quiz_run_id'] = $quiz_run_id;
                $content = '';
                break;
            case 'poll':
                // insert the answers as well...
                PollAnswers::getInstance()->storeAnswers($message_id, $message_data['poll_answers']);
                $content = $message_data['poll_question'];
                break;
            default:
                break;
        }

        // Parse the message's content for latex math expressions and generate images
        LatexHandler::getInstance()->extractMathExpressions($content);

        // Message data is stored in the database
        $data = array
        (
            'message_id' => $message_id,
            'content'    => $content //if it's a link, it's the same as the link's title, for now
        );
        MessageData::getInstance()->store( $data );

        if( ! empty($message_data['links']) )
        {
            $has_link_or_embed = true;
            // Store links
            LinkHandler::getInstance()->processAttachedLinks($message_data['links'],$message_id,$sender_info,$receivers, 'MESSAGE', $message_data['type']);
        }

        if( ! empty($message_data['files']) ){
            // Store files
            FileHandler::getInstance()->processAttachedFiles($message_data['files'],$message_id,$sender_info,$receivers);
        }

        if (is_array($attached_resources)) {
            foreach ($attached_resources as $item_id) {
                $item_info = LibraryItems::getInstance()->getLibraryItem($item_id);
                if ($sender_info['type'] != 'PUBLISHER') {
                    LibraryItems::getInstance()->updateRelations($item_id, $receivers);
                }
                if ($item_info['library_item_type'] == 'FILE') {
                    MessagesFiles::getInstance()->store(array('message_id' => $message_id, 'file_id' => $item_info['library_item_resource_id']));
                } else if ($item_info['library_item_type'] == 'LINK') {
                    MessagesLinks::getInstance()->store(array('message_id' => $message_id, 'link_id' => $item_info['library_item_resource_id']));
                    $has_link_or_embed = true;
                } else if ($item_info['library_item_type'] == 'EMBED') {
                    MessagesEmbeds::getInstance()->store(array('message_id' => $message_id, 'embed_id' => $item_info['library_item_resource_id']));
                    $has_link_or_embed = true;
                }
            }
        }
        $response['has_link_or_embed'] = $has_link_or_embed;
        return $response;
    }

    /**
     * Stores the corresponding notifications for a message's receivers
     * @param $sender_info is the message's sender info
     * @param $message_origin is the location where the message was posted
     * @param $receivers is an array with all the message's receivers
     */
    private function storeMessageReceiversNotification($sender_info, $message_id, $receivers, $message_data, $is_comment = false){
        $locations = null;
        $message_sent_to_group = false;
        if(!empty($receivers['locations'])){
            $message_sent_to_group = true;
            $locations = $receivers['locations'];
        }

        $message_data[TrackingHelper::DATA_DATE_REQUESTED] = time();

        $db_recipients_array = array();

        if($message_sent_to_group) {
            $this->checkIfMessageSentToEveryone($sender_info, $locations);

            $already_cached_in_community = array();
            $communities_model           = SubjectCommunities::getInstance();
            $all_community_ids           = $communities_model->getSubjectCommunityIds();
            $is_verified_teacher         = false;

            if($sender_info['type'] == 'TEACHER'){
                $is_verified_teacher = CoppaHandler::userIsCoppaVerified($sender_info['user_id']);
            }

            foreach($locations as $location) {
                //store this message location...
                if ( $location['type'] == 'connections' ){
                    // Messages sent to connections have the sender's user id in the posted_in_id field of message_recipients_connections
                    $location['id'] = $sender_info['user_id'];
                }

                $recipient = array(
                    'type'         => $location['type'],
                    'message_id'   => $message_id,
                    'posted_in_id' => $location['id'],
                );
                $db_recipients_array[] = $recipient;

                switch( $location['type'] )
                {
                    case 'group':
                    case 'GROUP':
                        $this->setGroupLastMessageReceivers($location['id']);
                        if ( !$is_comment ){
                            Messages::getInstance()->cacheGroupMessageId($location['id'], $message_id);
                        }
                        break;

                    case 'group_parents':
                    case 'GROUP_PARENTS':
                        if ( !$is_comment ){
                            Messages::getInstance()->cacheGroupMessageId($location['id'], $message_id);

                            // If the message was sent to a group's parents receiver (and not to the group itself), its message_id
                            // is stored in a cached array later used to avoid displaying it to students viewing the group's stream
                            $message_sent_to_group_parents_only = true;
                            foreach($locations as $other_location){
                                if ( ($other_location['type'] == 'group' || $other_location['type'] == 'GROUP') && $other_location['id'] == $location['id'] ){
                                    $message_sent_to_group_parents_only = false;
                                    break;
                                }
                            }
                            if ( $message_sent_to_group_parents_only ){
                                Messages::getInstance()->cacheGroupParentsMessageId($location['id'], $message_id);
                            }

                        }
                        break;

                    case 'connections':
                        $this->setConnectionsLastMessageReceivers($sender_info['user_id']);
                        break;

                    case 'community':
                        if( $is_verified_teacher &&
                            $message_data['type'] != 'comment' &&
                            !isset($already_cached_in_community[$location['id']])){
                            Messages::getInstance()->cacheCommunityMessageId($location['id'], $message_id);
                            $already_cached_in_community[$location['id']] = true;
                        }

                        if($location['id'] == SUPPORT_COMMUNITY_ID && !$is_comment){
                            //Send an email to the support address
                            $title     = 'Post from ' . $sender_info['first_name'] . ' ' . $sender_info['last_name'] . ' (' . $sender_info['username'] . ')';
                            $body_text = $message_data['message'];
                            $recipients = array(
                                array(
                                    'email' => SUPPORT_COMMUNITY_EMAIL,
                                    'username' => 'Support',
                                ),
                            );
                            $tracking_data = TrackingHelper::makeTrackingData(TrackingHelper::EMAILTYPE_SUPPORT_COMMUNITY__NOTIFICATION, null, null, TrackingHelper::TYPE_SYSTEM, null, TrackingHelper::TYPE_INTERNAL, SUPPORT_COMMUNITY_EMAIL, $message_id, TrackingHelper::TYPE_MESSAGE);
                            MailHandler::getInstance($body_text)->setTrackingData($tracking_data)->sendEmailWrapper($recipients, $title);
                        }

                        break;
                }
            }
        }
        $people_count = 0;
        if (isset($receivers['people'])){
            $people_count = count($receivers['people']);
        }

        if($people_count > 0){

            //add direct notifications for people
            if($people_count == 1){
                $receiver_info = Users::getInstance()->find($receivers['people'][0]);

                if ( $receiver_info['type'] == 'PUBLISHER' ){
                    Messages::getInstance()->cachePublisherMessageId( $receiver_info['user_id'], $message_id );
                }
            }
            foreach($receivers['people'] as $user_id){
                $recipient = array(
                    'type'         => 'user',
                    'message_id'   => $message_id,
                    'posted_in_id' => $user_id,
                );
                $db_recipients_array[] = $recipient;
                $this->last_direct_msg_receivers[] = $user_id;
                $this->last_message_receivers[] = $user_id;
            }
        }

        // this is what actually writes to the message_recipients_* tables for the given message_id
        if ( !$is_comment ){
            Messages::getInstance()->bulkStore($db_recipients_array);
        }
    }

    /**
     * Check if a message was sent to Everyone (all the user's groups)
     */
    private function checkIfMessageSentToEveryone($sender_info, &$locations){
        $all_groups = $all_connections = false;
        $list = $locations;

        foreach ($list as $i => $location) {
            switch ($location['type']) {
                case 'all-groups':
                    $all_groups = true;
                    $locations = array();
                    break;
                case 'connections':
                    $all_connections = true;
                    unset($locations[$i]);
                    break;
                case 'all':
                    // the message was sent to everyone
                    $all_groups = true;
                    $all_connections = true;
                    unset($locations[$i]);
                    break;
                default:
            }
        }

        if($all_groups){
            //send to all the user's groups
            $all_user_groups = Groups::getInstance()->getUserGroups($sender_info['user_id'], false, false);
            foreach($all_user_groups as $group){
                //new rule - the user must own the group in order to send the message
                if ($group['read_only'] != 1 && !empty($group['user_owns_group'])) {
                $locations[] = array('type' => GROUP, 'id' => $group['group_id']);
            }
        }
        }

        if($all_connections && ($sender_info['type'] == 'TEACHER' || $sender_info['type'] == 'PUBLISHER')){
            //send to all the teacher's connections
            $locations[] = array('type' => 'connections', 'id' => 0);
        }
    }

    /*
     * Adds the corresponding user_ids to the last_indirect_msg_receivers array (for spotlight purposes)
     */
    private function setConnectionsLastMessageReceivers($sender_id)
    {
        $user_ids = Connections::getInstance()->getConnectedUserIds($sender_id);
        foreach($user_ids as $user_id){
            $this->last_indirect_msg_receivers[] = $user_id;
            $this->last_message_receivers[] = $user_id;
        }
    }

    /*
     * Adds the corresponding user_ids to the last_indirect_msg_receivers array (for spotlight purposes)
     */
    private function setGroupLastMessageReceivers($group_id){
        $user_ids = Groups::getInstance()->getUserIds($group_id);
        foreach($user_ids as $user_id){
            $this->last_indirect_msg_receivers[] = $user_id;
            $this->last_message_receivers[] = $user_id;
        }
    }

    /**
     * Returns a list of user_ids who received the last message
     * @return array
     */
    public function getLastMessageReceivers()
    {
        $last_msg_receivers = array_merge($this->last_direct_msg_receivers,$this->last_indirect_msg_receivers);
        return $last_msg_receivers;
    }

    /**
     * Returns a list of direct user_ids who received the last message
     * @return array
     */
    public function getLastMessageDirectReceivers()
    {
        return $this->last_direct_msg_receivers;
    }

    /*
    * Modifies the contents of a mesage
    * $params string message_id is the id of the message to edit
    */
    public function editMessage($message_id, $content, $link_id = null, $new_url = null){
        if(!empty($link_id)){
            Links::getInstance()->editLink($link_id, $new_url);
        }
        MessageData::getInstance()->editMessage($message_id, $content);

        // Parse the message's content for latex math expressions and generate images
        LatexHandler::getInstance()->extractMathExpressions($content);
    }

    public function checkFilteredMessages($language, &$filters, $library_button = false, $get_community_counters = false, $get_html = false, $view = null)
    {
        $result = array();
        $the_filters = array
        (
            'get_all_messages' => (!isset($filters['all_msgs']) || $filters['all_msgs'] == 'true') ? true : false,
            'get_comments_only' => (!isset($filters['comments_only']) || $filters['comments_only'] == 'false') ? false : true,
            'search_str'       => isset($filters['search_str']) ? $filters['search_str'] : '',
            'people_filter'    => (!isset($filters['people_filter']) || $filters['people_filter'] == 'null') ? 'EVERYONE' : $filters['people_filter'],
            'group_id'         => (!isset($filters['group_id']) || $filters['group_id'] == 'null') ? null : $filters['group_id'],
            'direct_user_id'   => (!isset($filters['direct_user_id']) || $filters['direct_user_id'] == 'null') ? null : $filters['direct_user_id'],
            'tag_id'           => (!isset($filters['tag_id']) || $filters['tag_id'] == 'null') ? null : $filters['tag_id'],
            'community_id'     => (!isset($filters['community_id']) || $filters['community_id'] == 'null') ? null : $filters['community_id'],
            'include_communities' => (!isset($filters['include_communities']) || $filters['include_communities'] == 'null') ? null : $filters['include_communities'],
            'connections'      => (!isset($filters['connections']) || $filters['connections'] == 'null') ? null : $filters['connections'],
            'publishers'       => (!isset($filters['publishers']) || $filters['publishers'] == 'null') ? null : $filters['publishers'],
            'current_last_msg_id' => isset($filters['current_last_msg_id']) ? $filters['current_last_msg_id'] : 0,
            'max_messages'     => isset($filters['max_messages']) ? $filters['max_messages'] : 20,
            'page'     => isset($filters['page']) ? $filters['page'] : false,
            'sqllimit'     => isset($filters['sqllimit']) ? true : false,
            'max_message_id'   => (!isset($filters['max_message_id']) || $filters['max_message_id'] == 'null') ? null : $filters['max_message_id'],
            'max_comment_id'   => (!isset($filters['max_comment_id']) || $filters['max_comment_id'] == 'null') ? null : $filters['max_comment_id'],
            'public_only'      => (!isset($filters['public_only']) || $filters['public_only'] == 'false' || !$filters['public_only']) ? false : true,
            'sender_id'        => isset($filters['sender_id']) ? $filters['sender_id'] : false,
            'user_feed_id'     => (!isset($filters['user_feed_id']) || $filters['user_feed_id'] == 'null') ? null : $filters['user_feed_id'],
            'students_info_for_parent' => isset($filters['students_info_for_parent'])? $filters['students_info_for_parent'] : null,
            'parents'          => isset($filters['parents'])? $filters['parents'] : null,
            'is_inst_subdomain' => isset($filters['is_inst_subdomain'])? $filters['is_inst_subdomain'] : false,
            'message_ids_in_stream' => isset($filters['message_ids_in_stream']) && !empty($filters['message_ids_in_stream'])? $filters['message_ids_in_stream'] : null,
            'get_school_vip_msgs' => isset($filters['get_school_vip_msgs'])? $filters['get_school_vip_msgs'] : false,
            'trending_posts'   => isset($filters['trending_posts'])? $filters['trending_posts'] : false,
            'max_spotlight_id' => (!isset($filters['max_spotlight_id']) || $filters['max_spotlight_id'] == 'null') ? null : $filters['max_spotlight_id']
        );

        //Set the message feed type to HOME_FEED if it's the case:
        if(empty($the_filters['people_filter']) || $the_filters['people_filter'] == 'EVERYONE'){
            $this->setFeedType('HOME_FEED');
        }

        $account_info = AccountHandler::getInstance()->getAccountInfo();
        $user_community_ids = null;
        $publisher_ids = null;
        $user_hidden_homestream_communities = array();
        $user_hidden_homestream_publishers = array();

        if( !$the_filters['public_only'] ){
            if ( empty($account_info) ){
                $account_info = null;
            }else{
                if($account_info['type'] == 'PARENT' && !isset($the_filters['students_info_for_parent'])){
                    //Need to retrieve the parent's children's groups
                    $students_groups = array();
                    $students_info = ParentsHandler::getInstance()->getParentStudentsInfo($account_info, $the_filters['user_feed_id']);
                    if(count($students_info['student_ids'])){
                        $the_filters['students_info_for_parent'] = ParentsHandler::getInstance()->getStudentsGroupsForParent($students_info);
                    }
                }else if ($account_info['type'] == 'TEACHER'){
                    if($this->feed_type == 'HOME_FEED'){
                        $user_hidden_homestream_communities = UsersHomestreamHiddenCommunities::getInstance()->getUserHomestreamHiddenCommunities($account_info['user_id']);
                        $user_hidden_homestream_publishers = UsersHomestreamHiddenConnections::getInstance()->getUserHomestreamHiddenConnections($account_info['user_id']);
                        $is_admin = false;
                        $support_team = SubjectCommunityHandler::getInstance($language)->getSupportTeam($language);

                        if($account_info['admin_rights'] == 'SCHOOL' || $account_info['admin_rights'] == 'DISTRICT')
                        {
                            $is_admin = true;
                        }
                        foreach($support_team as $member){
                            if($member['user_id'] == $account_info['user_id'])
                            {
                                $is_admin = true;
                                break;
                            }
                        }

                        $user_community_ids = SubjectCommunities::getInstance()->getUsersSubjectComms($account_info['user_id'], true, $is_admin, true);
                        $publishers_info = ConnectionsHandler::getInstance()->getPublisherConnectionsInfo($account_info['user_id']);
                        $publisher_ids = $publishers_info['publisher_ids'];

                        if(!isset($the_filters['include_communities'])){
                            $the_filters['include_communities'] = array_diff($user_community_ids, $user_hidden_homestream_communities);
                        }
                        if(!isset($the_filters['connections'])){
                            $the_filters['connections'] = Connections::getInstance()->getConnectedUserIds($account_info['user_id']);
                            $the_filters['connections'] = array_diff($the_filters['connections'],          UsersHomestreamHiddenConnections::getInstance()->getUserHomestreamHiddenConnections($account_info['user_id']));
                        }
                        if(!isset($the_filters['publishers'])){
                            $the_filters['publishers'] = array_diff( $publisher_ids, $user_hidden_homestream_publishers);
                        }
                    }
                }
                elseif( $account_info['type'] == 'ADMINISTRATOR' && !isset($user_community_ids) ){
                    $subject_communities_info =                 SubjectCommunityHandler::getInstance($language)->getUsersSubjectCommunities($account_info['user_id'],true);
                    $user_community_ids = $subject_communities_info['subject_community_ids'];
                }
            }
        }

        $the_filters['viewer_and_viewed_info'] = $this->getInfoAboutViewerAndViewed($account_info, $the_filters);
        if (isset($the_filters['user_feed_id'])) {
            $feed_user = Users::getInstance()->getUserInfo($the_filters['user_feed_id']);
            if ($feed_user['type'] == 'PUBLISHER')
            {
                $library_button = true;
            }
        }

        $msg_filter = (!isset($filters['msgs_type']) || $filters['msgs_type'] == 'null') ? null : $filters['msgs_type'];

        if( $msg_filter == 'link' ){
            $msg_filter = array( 'link', 'video', 'embed' );
        }

        $the_filters['msgs_type'] = $msg_filter;

        $memcache_handler = MemcacheHandler::getInstance();
        $get_new_messages = !$memcache_handler->cachingAvailable()
            || $the_filters['get_all_messages']
            || $the_filters['max_message_id'] == null
            || ($the_filters['people_filter'] == 'COMMUNITY' && $the_filters['community_id'] == SUPPORT_COMMUNITY_ID && !$the_filters['get_comments_only'])
            || ($the_filters['people_filter'] == 'COMMUNITY' && $the_filters['community_id'] == ADMIN_SUPPORT_COMMUNITY_ID && !$the_filters['get_comments_only'])
            || ($account_info != null && $the_filters['people_filter'] == 'COMMUNITY' && SubjectCommunityHandler::getInstance()->newMessagesInCommunity($account_info['user_id'], $the_filters['community_id']))
            || ($account_info != null && !$the_filters['trending_posts'] && !$the_filters['get_comments_only'] && MemcacheHandler::getInstance()->load($account_info['user_id'].'max_message_id') > $the_filters['max_message_id']);

        if($get_community_counters && !empty($account_info) && $account_info['type'] == 'TEACHER' && $this->feed_type == 'HOME_FEED' && !$the_filters['get_comments_only']){
            //check if new messages were posted to a community/publisher that this user follows
            $community_counters = SubjectCommunityHandler::getInstance()->getCommunityCounters( $account_info['user_id'], $user_community_ids, $user_hidden_homestream_communities );
            $publisher_counters = PublisherNewMessages::getInstance()->getPublisherCounters( $account_info['user_id'], $publisher_ids, $user_hidden_homestream_publishers );
            if($community_counters['count_total'] > 0 || $publisher_counters['count_total'] > 0){
                $get_new_messages = true;
            }
        }

        if ( $get_new_messages && $this->userHasRightsToCheckMessages($account_info, $the_filters) ){
            $result = $this->checkMessages($language, $account_info, $the_filters, $library_button);
        }

        if(!$the_filters['get_all_messages'] && !empty($the_filters['message_ids_in_stream'])){
            if(  !$memcache_handler->cachingAvailable()
                || $the_filters['max_comment_id'] == null
                || ($the_filters['people_filter'] == 'COMMUNITY' &&  $the_filters['community_id'] == SUPPORT_COMMUNITY_ID)
                || ($the_filters['people_filter'] == 'COMMUNITY' &&  $the_filters['community_id'] == ADMIN_SUPPORT_COMMUNITY_ID)
                || MemcacheHandler::getInstance()->load($account_info['user_id'].'max_comment_id') > $the_filters['max_comment_id'])
            {
                //Get comments too
                self::initializeTranslators($language);
                $comments = CommentsHandler::getInstance()->checkComments($this, $language, $the_filters, $this->institutional_info);
                $result = array_merge($result, $comments);
            }
        }

        // The html of the message list is added to the response object
        if ( $get_html ){
            $thumb_url = '';
            if (!empty($the_filters['viewer_and_viewed_info'])){
                $profile   = Profiles::getInstance()->find($the_filters['viewer_and_viewed_info']['viewer_id'])->current();
                $thumb_url = Profiles::getInstance()->getAvatarUrlFromProfile($profile, null, 'THUMB');
                $full_avatar_url = Profiles::getInstance()->getAvatarUrlFromProfile($profile, null, '');
            }

            $translator = TranslationHelper::getInstance(PATH2_LANGUAGES . 'message-feed.tmx', $language);

            if ( SECONDARY_THEME_ENABLED ){
                $view->setScriptPath('../application/views/scripts_v2');
            }
            $html = $view->partial(
                PATH2_PARTIALS.'message_feed/message-list.phtml',
                array(
                     'account_info' => AccountHandler::getInstance()->getAccountInfo(),
                     'activities' => $result,
                    'activity_count' => count($result),
                     'max_activities' => $the_filters['max_messages'],
                     'translator' => $translator,
                     'new_comment_ids' => array(),
                     'is_a_template' => false,
                     'is_spotlight' => false,
                     'can_create_tags' => ($this->feed_type == 'COMMUNITY_FEED' && $this->community_user_status == ADMINS),
                     'is_community' => ($this->feed_type == 'COMMUNITY_FEED'),
                     'public_only' => $the_filters['public_only'],
                     'thumb_url' => $thumb_url,
                     'full_avatar_url' => $full_avatar_url,
                     'is_ipad' => (bool) strpos($_SERVER['HTTP_USER_AGENT'],'iPad'),
                     'language' => $language
                )
            );
            //$html = utf8_encode($html);
            $result = array_merge($result, array(array('object_type'=>'message_list_html', 'message_list_html'=>$html)));
            $full_message_partial = 'full-message';
            // We'll now also add individual html for each message
            foreach ( $result as $index => $activity ){
                if ( $activity['object_type'] == 'message' ){
                    $result[$index]['html'] = $view->partial(
                        PATH2_PARTIALS.'message_feed/'.$full_message_partial.'.phtml',
                        array(
                             'account_info' => $account_info,
                             'activity' => $activity,
                             'activities' => $result,
                             'index' => $index,
                             'translator' => $translator,
                             'new_comment_ids' => array(),
                             'is_a_template' => false,
                             'is_spotlight' => false,
                             'can_create_tags' => ($this->feed_type == 'COMMUNITY_FEED' && $this->community_user_status == ADMINS),
                             'is_community' => ($this->feed_type == 'COMMUNITY_FEED'),
                             'public_only' => $the_filters['public_only'],
                             'thumb_url' => $thumb_url,
                             'is_ipad' => (bool) strpos($_SERVER['HTTP_USER_AGENT'],'iPad'),
                             'language' => $language
                        )
                    );
                }
                elseif ( $activity['object_type'] == 'comment' ){
                    $result[$index]['html'] = $view->partial(
                        PATH2_PARTIALS."message_feed/comment.phtml",
                        array(
                             'activity' => $activity,
                             'avatar_url'=>$thumb_url,
                             'translator' => $translator,
                             'is_a_template' => false,
                             'public_only' => $the_filters['public_only'],
                             'new_comment_ids' => array(),
                             'language'=>$language
                        )
                    );
                }
            }
        }

        // Community counters are added to the result
        if($get_new_messages && isset($community_counters['counters'], $publisher_counters['counters'])){
            $result = array_merge($community_counters['counters'], $publisher_counters['counters'], $result);
        }

        // Spotlight counters are added to the result
        if ( $get_community_counters && !empty($account_info) && $account_info['type'] != 'ADMINISTRATOR' && !empty($the_filters['max_spotlight_id']) && (!$memcache_handler->cachingAvailable() || !$memcache_handler->load($account_info['user_id'].'spotlights_up_to_date')) ){
            $new_spotlight_counters = Spotlights::getInstance()->getNewSpotlightCounters($account_info['user_id'], $the_filters['max_spotlight_id']);
            $result = array_merge($new_spotlight_counters, $result);
        }

        //------------------------
        // Transform some of the filters for the FeedHandler.js
        // turn the msg type to a single type
        if( isset($filters['msgs_type']) && $filters['msgs_type'] == 'link')
        {
            $the_filters['msgs_type'] = 'link';
        }

        if( $the_filters['group_id'] != null )
        {
            $the_filters['people_filter_id'] = $the_filters['group_id'];
        }
        elseif ( $the_filters['sender_id'] != null )
        {
            $the_filters['people_filter_id'] = $the_filters['sender_id'];
        }
        else
        {
            $the_filters['people_filter_id'] = null;
        }
        if( isset($filters['header_title']) && isset($filters['header_color']) )
        {
            $the_filters['header_title'] = $filters['header_title'];
            $the_filters['header_color'] = $filters['header_color'];
        }

        $filters = $the_filters;
        return $result;
    }

    // Checks if the user represented by $account_info has rights to check messages with $filters
    public function userHasRightsToCheckMessages($account_info, $filters){
        $user_has_rights_to_check_messages = true;
        switch ( $filters['people_filter'] ) {
            case 'GROUP':
                // Check if the user belongs to the group
                if (!Groups::getInstance()->userBelongsToGroupById($account_info['user_id'], $filters['group_id']))
                    $user_has_rights_to_check_messages = false;

                // Check if the group is enabled
                if ($user_has_rights_to_check_messages) {
                    $group = Groups::getInstance()->find($filters['group_id'])->current();
                    if ( !$group['enabled'] )
                        $user_has_rights_to_check_messages = false;
                }
                break;
        }
        return $user_has_rights_to_check_messages;
    }

    /**
     * Checks all messages sent to / received by this user
     * @param string $language defines the viewing user's language
     * @param array $account_info is the base user for message retrieval. Might be null (ie public feed)
     * @param array $filters contains all the info about the query's filters:
     * boolean 'get_all_messages' if false, function retrieves only the messages which have not been previously retrieved by this user. If true, function retrieves all messages.
     * string 'search_str' a string to search in the messages
     * string 'msgs_type' tells what to fetch: Assignments, Alerts, Polls, etc...
     * string 'people_filter' tells whose msgs to fetch: Everything, Direct msgs, group messages, etc..
     * string 'direct_user_id' used when $people_filter == 'DIRECT' to specify the user to which the direct messages have been sent or received
     * int 'group_id' id for the group whose messages should be fetched
     * int 'tag_id id' for the tag whose messages should be fetched
     * boolean $public_only if true, function retrieves only the messages which have been set as public
     * int 'message_id' id(s) for the message that has to be retrieved (along with its comments)
     * int 'sender_id' id of the author of the messages that have to be retrieved
     * int 'max_messages' max pagination result count for the retrieved messages
     * int 'max_message_id' is the highest current message_id displayed in the user's feed
     * int 'current_last_msg_id' when viewing More messages, the last message id currently shown in the stream (starting message id for additional messages)
     * array 'include_communities' includes the community ids the user is part of, whose posts need to be retrieved for the stream
     * array 'connections' includes the user ids the user is connected to, whose posts need to be retrieved for the stream
     * array 'students_info_for_parent' for parent feeds, includes the info of the parent's students
     * array 'parents' mainly for teachers' feeds, includes the info of the students' parents
     * boolean 'edmodo_messages_only' will return back the plain message records without additional message information, recievers, or other message processing
     * EXPERIMENTAL: int 'message_id_upper_bound' used to restrict the messages by message_id, this upper bound message id will restrict the query to fetch messages older than this id
     * @return array with each message info grouped by message_id
     */
    public function checkMessages($language, $account_info = null, $filters = array(), $library_button = false){
        //Set the default filters if they aren't set
        $filters['get_all_messages']           = isset($filters['get_all_messages'])? $filters['get_all_messages'] : false;
        $filters['search_str']                 = isset($filters['search_str'])? addslashes($filters['search_str']) : '';
        $filters['msgs_type']                  = isset($filters['msgs_type'])? $filters['msgs_type'] : null;
        $filters['people_filter']              = isset($filters['people_filter'])? $filters['people_filter'] : 'EVERYONE';
        $filters['direct_user_id']             = isset($filters['direct_user_id'])? $filters['direct_user_id'] : null;
        $filters['group_id']                   = isset($filters['group_id'])? $filters['group_id'] : null;
        $filters['community_id']               = isset($filters['community_id'])? $filters['community_id'] : null;
        $filters['tag_id']                     = isset($filters['tag_id'])? $filters['tag_id'] : null;
        $filters['public_only']                = isset($filters['public_only'])? $filters['public_only'] : false;
        $filters['by_teachers']                = isset($filters['by_teachers'])? $filters['by_teachers'] : false;
        $filters['by_students']                = isset($filters['by_students'])? $filters['by_students'] : false;
        $filters['by_me']                      = isset($filters['by_me'])? $filters['by_me'] : false;
        $filters['message_id']                 = isset($filters['message_id'])? $filters['message_id'] : null;
        $filters['sender_id']                  = isset($filters['sender_id'])? $filters['sender_id'] : null;
        $filters['current_last_msg_id']        = isset($filters['current_last_msg_id']) ? $filters['current_last_msg_id'] : 0;
        $filters['max_messages']               = isset($filters['max_messages'])? $filters['max_messages'] : 20;
        $filters['max_message_id']             = isset($filters['max_message_id'])? $filters['max_message_id'] : 0;
        $filters['include_comments']           = isset($filters['include_comments'])? $filters['include_comments'] : true;
        $filters['feed_type']                  = $this->feed_type;
        $filters['user_feed_id']               = isset($filters['user_feed_id'])? $filters['user_feed_id'] : false;
        $filters['include_communities']        = isset($filters['include_communities'])? $filters['include_communities'] : null;
        $filters['connections']                = isset($filters['connections'])? $filters['connections'] : null;
        $filters['publishers']                 = isset($filters['publishers'])? $filters['publishers'] : null;
        $filters['students_info_for_parent']   = isset($filters['students_info_for_parent'])? $filters['students_info_for_parent'] : null;
        $filters['archived_group_ids']         = isset($filters['archived_group_ids'])? $filters['archived_group_ids'] : array();
        $filters['parents']                    = isset($filters['parents'])? $filters['parents'] : null;
        $filters['is_inst_subdomain']          = isset($filters['is_inst_subdomain'])? $filters['is_inst_subdomain'] : false;
        $filters['get_school_vip_msgs']        = isset($filters['get_school_vip_msgs'])? $filters['get_school_vip_msgs'] : false;
        $filters['trending_posts']             = isset($filters['trending_posts'])? $filters['trending_posts'] : false;
        $filters['viewer_and_viewed_info']     = isset($filters['viewer_and_viewed_info'])? $filters['viewer_and_viewed_info'] : $this->getInfoAboutViewerAndViewed($account_info, $filters);
        $filters['edmodo_messages_only']       = isset($filters['edmodo_messages_only'])? $filters['edmodo_messages_only'] : false;
        $filters['message_id_upper_bound']     = isset($filters['message_id_upper_bound'])? $filters['message_id_upper_bound'] : false;

        self::initializeTranslators($language);
        $viewer_and_viewed_info = $filters['viewer_and_viewed_info'];
        $viewer_id = $viewer_and_viewed_info['viewer_id'];
        $viewer_type = $viewer_and_viewed_info['viewer_type'];
        $viewed_user_info = $viewer_and_viewed_info['viewed_user_info'];
        $viewed_user_group_ids = $viewer_and_viewed_info['viewed_user_group_ids'];
        $viewed_taught_groups = $viewer_and_viewed_info['viewed_taught_groups'];

        $messages_model = Messages::getInstance();
        switch($this->feed_type){
            case 'COMMUNITY_FEED':
                if($filters['trending_posts']){
                    $messages = $messages_model->getCommunityTrendingMessages($filters);
                }else{
                    $messages = $messages_model->getCommunityMessages($filters);
                    if( count($messages) && ($this->community_user_status == ADMINS || $this->community_user_status == FOLLOWS_CAN_POST || $this->community_user_status == FOLLOWS_CANT_POST ) ){
                        SubjectCommunityHandler::getInstance()->updateLastSeenMessageId($viewer_id, $filters['community_id'], $messages[0]['message_id']);
                    }
                }
                break;
            case 'SCHOOL_FEED':
                $messages = $messages_model->getInstitutionMessages($account_info, $filters, $this->institutional_info, 'SCHOOL');
                break;
            case 'DISTRICT_FEED':
                $messages = $messages_model->getInstitutionMessages($account_info, $filters, $this->institutional_info, 'DISTRICT');
                break;
            default:
                $messages = $messages_model->getEdmodoMessages($account_info, $filters, $viewed_user_group_ids, $this->institutional_info);
                break;
        }

        // if edmodo_messages_only option is set, return messages here
        if ($filters['edmodo_messages_only']) {
            return $messages;
        }
        $messages = $this->getMissingMessageInfos($messages, $library_button);
        $all_messages = array();
        $all_ids = array();
        $msg_count = count($messages);
        $poll_ids = array();
        $assignment_ids = array();
        $link_ids = array();
        $file_ids = array();
        $final_msg_count = 0;
        $embed_ids = array();
        $comment_ids = array();
        $quiz_ids = array();
        $app_message_ids = array();
        if ($viewer_type == USERS::TYPE_STUDENT) {
            $teacher_ids = Users::getInstance()->getTeacherIdsFromUserGroups($viewer_id);
        } else {
            $teacher_ids = array();
        }

        for($i = 0; $i < $msg_count;){
            $message = $messages[$i];
            //Notice the $i is passed by reference to the next function and will be modified there
            $message = $this->extractMessageReceivers($messages, $i, $language, $viewer_id, $viewer_type, $viewed_taught_groups, $filters, $teacher_ids);
            $this->processMessageInfo($message, $language);
            if (isset($message['posted_in'])) {
                if ($message['posted_in'] == 'connections') {
                    $message['sent_to_connections'] = 1;
                }
            }

            /* Show Library Button if Community Post */
            if ($message['sent_to_community'] || (isset($message['sent_to_connections']) && $message['sent_to_connections']) || $message['sender_type'] == 'ADMINISTRATOR') {
                $message['add-to-library'] = 1;
            }
            /* Don't show Library Button if viewer = creator */
            if ($message['creator_id'] == $viewer_id) {
                if (isset($message['add-to-library'])) {
                    unset($message['add-to-library']);
                    $message['library-items-by-me'] = 1;
                }
            } else if ($message['sender_type'] == 'ADMINISTRATOR') {
                unset($message['add-to-library']);
            }

            switch( $message['type'] ){
                case 'text':
                    $file_ids[$message['message_id']] = $final_msg_count;
                    $embed_ids[$message['message_id']] = $final_msg_count;
                    $link_ids[$message['message_id']] = $final_msg_count;
                    $message['files'] = array();
                    $message['embeds'] = array();
                    $message['links'] = array();
                    break;
                case 'link':
                case 'video':
                    // Store the message id and the index in the messages array for later
                    $link_ids[$message['message_id']] = $final_msg_count;
                    break;
                case 'file':
                    $file_ids[$message['message_id']] = $final_msg_count;
                    $message['files'] = array();
                    break;
                case 'embed':
                    // Store the message id and the index in the messages array for later
                    $embed_ids[$message['message_id']] = $final_msg_count;
                    break;
                case ASSIGNMENT:
                    $file_ids[$message['message_id']] = $final_msg_count;
                    $message['files'] = array();
                    if (UNIFIED_POST_TYPES) {
                        $embed_ids[$message['message_id']] = $final_msg_count;
                        $link_ids[$message['message_id']] = $final_msg_count;
                        $message['embeds'] = array();
                        $message['links'] = array();
                    }
                    $assignment_ids[ $message['message_id'] ] = $final_msg_count;
                    break;
                case 'feed':
                    // Store the message id and the index in the messages array for later
                    $link_ids[$message['message_id']] = $final_msg_count;
                    $message['sender_thumb'] = PATH2_IMAGES . 'rss.png';
                    // NEED new/larger RSS icon, will use small on for now:
                    $message['sender_full_avatar'] = PATH2_IMAGES . 'rss.png';
                    break;
                case 'poll':
                    $poll_ids[ $message['message_id'] ] = $final_msg_count;
                    break;
                case 'quiz':
                    $quiz_ids[ $message['message_id'] ] = $final_msg_count;
                    $message['edit_rights'] = false;
                    break;
                case MessagingHelper::MESSAGE_TYPE_APP_MESSAGE:
                    $app_message_ids[ $message['message_id'] ] = $final_msg_count;
                    break;
            }

            $all_ids[ $message['message_id'] ] = $final_msg_count;
            $message['tags'] = array();
            $message['comment_count'] = 0;
            $all_messages[] = $message;
            $final_msg_count++;

        }
        $user_id = '';

        $account_info = AccountHandler::getInstance()->getAccountInfo();
        if ($account_info)
        {
            $user_id = $account_info['user_id'];
        }

        $all_messages = EmbedHandler::getInstance()->processEmbeds($all_messages, $embed_ids, 'MESSAGE', $user_id);
        $all_messages = LinkHandler::getInstance()->processLinks($all_messages, $link_ids, 'MESSAGE', $user_id);
        $all_messages = FileHandler::getInstance()->processFiles($all_messages, $file_ids, 'MESSAGE', $user_id);
        $all_messages = AssignmentHandler::getInstance()->processAssignments($all_messages, $assignment_ids, $viewer_id, $viewed_taught_groups, $language);
        $all_messages = QuizHandler::getInstance()->processQuizzes($all_messages, $quiz_ids, $viewer_id, $viewed_taught_groups, $language);
        $all_messages = PollHandler::getInstance()->processPolls($all_messages, $poll_ids, $viewer_id, $viewer_type);
        
        if ($app_message_ids) {
            // process app messages
            $all_messages = AppsMessagesHandler::getInstance()->processAppMessages($all_messages, $app_message_ids, $user_id);
        }
        
        $all_messages = ReactionsHandler::getInstance()->processReactions($all_messages, $all_ids, $user_id);
        
        if( !$filters['public_only'] || $filters['community_id'] != null ){
            $all_messages = TagHandler::getInstance()->processTags($all_messages, $all_ids, $viewer_id, $language, $filters['community_id']);
        }
        if ( $filters['include_comments'] ){
            $all_messages = $this->processComments($all_messages, $all_ids, $language, $viewer_id, $viewer_type, $filters);
        }
        if(count($all_messages)){
            if(!empty($viewer_id) && isset($viewed_user_info['type']) && $viewed_user_info['type'] == 'PUBLISHER'){
                //Update the last seen message for this publisher/user pair
                PublisherNewMessages::getInstance()->updatePublisherLastSeenMessageId($viewer_id, $viewed_user_info['user_id'], $all_messages[0]['message_id']);
            }

            if($this->feed_type == 'HOME_FEED' && $viewer_type == 'TEACHER'){
                //update the last seen message for this community/user
                $communities = array();
                $publishers = array();

                foreach($all_messages as $message){
                    if($message['type'] != 'comment'){
                        foreach($message['receivers'] as $receiver){
                            if($receiver['type'] == 'community' && !in_array($receiver['id'], $communities)){
                                SubjectCommunityHandler::getInstance()->updateLastSeenMessageId($viewer_id, $receiver['id'], $message['message_id']);
                                $communities[] = $receiver['id'];
                                break;
                            }else if(isset($receiver['user_type']) && $receiver['user_type'] == 'PUBLISHER' && !in_array($receiver['id'], $publishers)){
                                PublisherNewMessages::getInstance()->updatePublisherLastSeenMessageId($viewer_id, $receiver['id'], $message['message_id']);
                                $publishers[] = $receiver['id'];
                                break;
                            }
                        }
                    }
                }
            }

        }
        return $all_messages;
    }

    private function getInfoAboutViewerAndViewed(&$account_info, &$filters)
    {
        $viewer_id = 0;
        $viewer_type = 'STUDENT';
        $viewed_user_info = null;
        $viewed_taught_groups = array();

        if(isset($account_info)){
            $viewer_id = $account_info['user_id'];
            $viewer_type = $account_info['type'];
            $viewed_user_info = $account_info;

            if ( $filters['user_feed_id'] ){
                if($filters['people_filter'] == 'STUDENT'){
                    //Parent viewing one of his students
                    $filters['student_id'] = $filters['user_feed_id'];
                }
                else{
                    $user_info = Users::getInstance()->find($filters['user_feed_id']);
                    if ( $account_info['type'] == 'ADMINISTRATOR' || $user_info['type'] == 'PUBLISHER' ){
                        $viewed_user_info = $account_info = $user_info;
                    }
                }
            }
            elseif ( $filters['sender_id'] && $account_info['type'] == 'PARENT' ){
                $viewed_user_info = $user_info = Users::getInstance()->find($filters['sender_id']);
            }
        } elseif ($filters['user_feed_id']) {
            $user_info = Users::getInstance()->find($filters['user_feed_id']);
            if ($user_info['type'] == 'PUBLISHER'){
                $viewed_user_info = $account_info = $user_info;
            }
        }
        if ( !empty($filters['community_id']) ){
            $this->community_user_status = SubjectCommunities::getInstance()->getUserStatus($account_info, $filters['community_id']);
        }
        switch($viewer_type){
            case 'STUDENT':
            case 'TEACHER':
            case 'PUBLISHER':
            case 'ADMINISTRATOR':
                if ( $viewer_type == 'ADMINISTRATOR' ){
                    $this->institutional_info = InstitutionalHandler::getInstance()->getInstitutionalInfo($viewed_user_info, $this->feed_type);
                }
                if(isset($viewed_user_info)){
                    $this->institutional_info = InstitutionalHandler::getInstance()->getInstitutionalInfo($viewed_user_info, $this->feed_type);

                    //-------------------------
                    // Get the groups that the user being viewed belongs to
                    $include_archived = false;
                    if($filters['tag_id'] != null){
                        $include_archived = true;
                    }

                    $viewed_user_groups_info = GroupsHandler::getInstance()->getUsersGroupsInfo($viewed_user_info['user_id'],$include_archived);
                    $this->viewed_user_groups = $viewed_user_groups_info['groups'];
                    $filters['archived_group_ids'] = $viewed_user_groups_info['archived_group_ids'];

                    $group_ids = array();

                    foreach( $viewed_user_groups_info['groups'] as $group ){
                        array_push($group_ids,$group['group_id']);
                        if( $viewer_type == 'TEACHER' ){
                            if( $group['user_owns_group'] == 1 || $group['co_teacher'] == 1){
                                $viewed_taught_groups[$group['group_id']] = true;
                            }
                        }
                    }
                }
                $this->parent_info['parents'] = $filters['parents'];
                break;
            case 'PARENT':
                $this->parent_info['students_info_for_parent'] = $filters['students_info_for_parent'];
                break;
        }
        if(empty($this->viewed_user_id) && !empty($viewed_user_info)){
            $this->viewed_user_id = $viewed_user_info['user_id'];
        }
        $viewed_user_group_ids = isset($viewed_user_groups_info['groups_ids'])? $viewed_user_groups_info['groups_ids'] : array();

        $viewer_and_viewed_info = array(
            'viewer_id' => $viewer_id,
            'viewer_type' => $viewer_type,
            'viewed_user_info' => $viewed_user_info,
            'viewed_user_group_ids' => $viewed_user_group_ids,
            'viewed_taught_groups' => $viewed_taught_groups
        );
        return $viewer_and_viewed_info;
    }

    /*
    Get additional information for the messages about: sender, recipients, avatar thumbs, etc.
    */
    private function getMissingMessageInfos($messages, $library_button){
        // -------------------------
        // Fill data from memcache
        $message_data_ids = Array();
        $user_ids = Array();
        for ($i = 0; $i < count($messages); $i++) {
            // Message content
            $message_data_ids[] = $messages[$i]['message_id'];
            // Receiver user
            if ($messages[$i]['posted_in'] == 'user') {
                $user_ids[] = $messages[$i]['posted_in_id'];
            }
            $user_ids[] = $messages[$i]['creator_id'];
        }
        $profiles = Profiles::getInstance();
        $message_data = MessageData::getInstance();
        $users = Users::getInstance();
        $matched_profiles = $profiles->find($user_ids);
        $matched_message_data = $message_data->find($message_data_ids)->toArray();
        $matched_users = $users->find($user_ids);

        // get the comments last updated timestamp for the messages
        $comments_last_updated_timestamp_by_message_id_array = NewComments::getInstance()->getLatestCommentTimestampForMessage($message_data_ids);

        for ($i = 0; $i < count($messages); $i++){
            // Users
            $matched_user = null;
            foreach ($matched_users as $user) {
                if ($user['user_id'] == $messages[$i]['creator_id']) {
                    $messages[$i]['sender_first_name'] = $user['first_name'];
                    $messages[$i]['sender_last_name'] = $user['last_name'];
                    $messages[$i]['sender_type'] = $user['type'];
                    if ($user['type'] == 'PUBLISHER') {
                        $messages[$i]['sent_to_community'] = true;
                    }
                    $messages[$i]['sender_title'] = $user['title'];
                    $matched_user = $user;
                    break;
                }
            }
            // Thumbnails
            foreach ($matched_profiles as $profile)
            {
                if ($profile->user_id == $messages[$i]['creator_id'] && !is_null($matched_user))
                {
                    $messages[$i]['sender_thumb'] = $profiles->getAvatarUrlFromProfile($profile, $matched_user['username'], 'THUMB');
                    $messages[$i]['sender_full_avatar'] = $profiles->getAvatarUrlFromProfile($profile, $matched_user['username'], '');
                    $messages[$i]['secondary_theme_enabled'] = $profile['secondary_theme_enabled'];
                    break;
                }
            }
            // Receivers
            $messages[$i]['receiver_first_name'] = null;
            $messages[$i]['receiver_last_name'] = null;
            $messages[$i]['receiver_type'] = null;
            $messages[$i]['receiver_title'] = null;
            if ($messages[$i]['posted_in'] == 'user') {
                foreach ($matched_users as $user) {
                    if ($user['user_id'] == $messages[$i]['posted_in_id']) {
                        $messages[$i]['receiver_first_name'] = $user['first_name'];
                        $messages[$i]['receiver_last_name'] = $user['last_name'];
                        $messages[$i]['receiver_type'] = $user['type'];
                        $messages[$i]['receiver_title'] = $user['title'];
                        break;
                    }
                }
            }
            // Message_data
            foreach ($matched_message_data as $md) {
                if ($md['message_id'] == $messages[$i]['message_id']) {
                    $messages[$i]['content'] = $md['content'];
                    $messages[$i]['last_updated'] = $md['last_updated'];
                    if ($library_button) {
                        $messages[$i]['add-to-library'] = 1;
                    }
                    break;
                }
            }

            // set last_updated_ts
            $messages[$i]['last_updated_ts'] = strtotime($messages[$i]['last_updated'] . ' ' . DATABASE_TIMEZONE_IDENTIFIER);

            // Comments last updated for message (-1 signifies there are no comments for this message)
            if (isset($comments_last_updated_timestamp_by_message_id_array[$messages[$i]['message_id']])
                && $comments_last_updated_timestamp_by_message_id_array[$messages[$i]['message_id']] != -1
            ) {
                $messages[$i]['comments_last_updated_ts'] = $comments_last_updated_timestamp_by_message_id_array[$messages[$i]['message_id']];
            } else {
                $messages[$i]['comments_last_updated_ts'] = null;
            }
        }
        return $messages;
    } //getMissingMessageInfos

    /**
     * Formats a message
     * @param array $message
     * @param string language viewing user's language
     */
    public function processMessageInfo(&$message, $language)
    {
        if($message['type'] == 'system'){
            $this->processSystemMessage($message, $language);
        }
        else
        {
            // only perform data string formatting if this is not an API call
            $message['content'] = MessagingHelper::formatDBString($message['content'], true, $message['creator_id'] != EDMODO_SYSTEM_USER_ID, $message['type'] == 'comment');
        }
        $message['formal_creation_date'] = date(DATE_RSS, strtotime($message['creation_date']));
        $message['creation_date'] = DateTimeHelper::getInstance($language)->formatCreationDate( $message['creation_date'] );
        if($message['sent'] && $language == 'en'){
            //This user sent the message (only translated if it's english to 'Me', for now)
            $message['sender_name'] = 'Me';
        }else{
            $sender = array('first_name' => $message['sender_first_name'],
                            'last_name' => $message['sender_last_name'],
                            'type' => $message['sender_type'],
                            'title' => $message['sender_title']
            );
            if( !isset($message['sender_name']) )
            {
                $message['sender_name'] = self::formatName($sender, $language);
            }
        }

        $message['sender_representative'] = 'none';
        if ($message['sender_type'] == 'TEACHER') {
            $representative = RepresentativeUsers::getInstance()->getRepresentativeUser($message['creator_id']);
            if (!empty($representative)) {
                $message['sender_representative'] = $representative['privilege_level'];
            }
        }

        if ( $message['sender_type'] == 'ADMINISTRATOR' ){
            $administered_entity_info = Users::getInstance()->getAdministeredEntityInfo($message['creator_id']);
            $message['administered_entity']                = $administered_entity_info['entity_name'];
            $message['administered_entity_community_name'] = $administered_entity_info['entity_community_name'];
            $message['administered_entity_type']           = $administered_entity_info['entity_type'];
        }

        //initialize a sender_and_receivers string
        $message['sender_and_receivers'] = '';

        //initialize the link to the avatar
        if ( $message['sender_type'] == 'TEACHER' || $message['sender_type'] == 'PARENT' )
        {
            $message['avatar_link'] = '/profile/' . $message['creator_id'];
        }
        elseif( $message['sender_type'] == 'PUBLISHER' ){
            $message['avatar_link'] = '/publisher?uid=' . $message['creator_id'];
        }
        elseif($message['sender_type'] == 'ADMINISTRATOR'){
            $message['avatar_link'] = '/'.$message['administered_entity_type'].'/'.$message['administered_entity_community_name'];
        }
        elseif($message['sender_type'] === 'STUDENT'){
            $message['avatar_link'] = '/user?uid=' . $message['creator_id'];
        }
    }

    /**
     * Prepares a string extracted from the DB for display
     * Optionally escapes all html characters and quotes
     * Inserts <p>...</p> tags instead of newlines
     * Special format for STORE - PUBLISHER description
     * Optionally changes all link appearances to html anchor tags (ie: 'http://www.yahoo.com' to '<a href="http://www.yahoo.com">http://www.yahoo.com</a>')
     * @param string $data the string to modify
     * @param bool $replace_links_with_anchors whether to change links to anchor tags
     * @param bool $escape whether to escape special html characters
     * @param bool $remove_newlines
     * @param bool $nl2p
     * @return modified string
     */
    public static function formatDBStringForStore($data, $replace_links_with_anchors, $escape=true, $remove_newlines=false, $nl2p=true){
        // omit formatting of data for api calls
        if (self::$is_api_call)
        {
            return $data;
        }

        $domain = ArrayHelper::elem($data, 'domain', SystemHelper::getDomain());

        if($replace_links_with_anchors){
            // get math expressions before escaping
            $thunks = array();
            preg_match_all('/\[math\](.*?)\[\/math\]/si', $data, $matches);
            for ($i=0; $i < count($matches[0]); $i++) {
                $position = strpos($data, $matches[0][$i]);
                $thunks[]  = $matches[1][$i];
            }
        }

        if( $escape ){
            //escape the content
            $data = htmlspecialchars($data, QUOTE_STYLE);
        }

        if($replace_links_with_anchors){
            $data = "!$data!";
            $data = str_replace("\nwww.", "\nhttp://www.", $data);
            $data = str_replace(" www.", ' http://www.', $data);
            $data = str_replace("!www.", ' http://www.', $data);
            $data = str_replace("\nedmo.do/j/", "\nhttps://edmo.do/j/", $data);
            $data = str_replace(" edmo.do/j/", " https://edmo.do/j/", $data);
            $data = str_replace("!edmo.do/j/", " https://edmo.do/j/", $data);

            $regexp = '/[[:alpha:]]+:\/\/[^<>[:space:]]+[[:alnum:]|\/|@][\.]?[ |\n|\r|!]/siU';

            if( strlen($data) < 20000 ){
                if(preg_match_all($regexp, $data, $matches, PREG_SET_ORDER)) {
                    foreach($matches as $match) {
                        $length   = strlen($match[0]);
                        $url      = substr($match[0], 0, $length-1);
                        $abb_url  = $url;
                        $url = rtrim($url, ".");
                        if (strlen($abb_url) > 45) {
                            $abb_url = substr($abb_url, 0, 45)."...";
                        }
                        $ending = substr($match[0], $length-1, $length);

                        $data = str_replace($match[0], "<a class=\"word-wrap\" target=\"_blank\" href=\"".$url."\">$abb_url</a>$ending", $data);
                    }
                }
            }
            $data = substr($data, 1, strlen($data)-2);

            // replace math expressions
            preg_match_all('/\[math\](.*?)\[\/math\]/si', $data, $matches);
            for ($i=0; $i < count($matches[0]); $i++) {
                $position = strpos($data, $matches[0][$i]);
                $hash     = md5($thunks[$i]);
                $url      = NEW_MATH_IMAGES_SERVER . "$hash.png";
                $data     = substr_replace($data, "<a href=\"$url\" rel=\"facebox\"><img src=\"$url\" /></a>", $position, strlen($matches[0][$i]));
            }
        }

        if ( $remove_newlines ){
            $data = preg_replace(array('/\r/m','/\n{2,}/m'), array("\n","\n"), $data);
        }
        else{
            $data = preg_replace(array('/\r/m','/\n{3,}/m'), array("\n","\n\n"), $data);
        }

        //replace newlines with <br>
        if ($nl2br){
            $data = str_replace("\n", HTML_BREAK_TAG, $data);
        }

        //replace newlines with <p>
        if ($nl2p){
            $data = preg_replace('/\n(\s*\n)+/', '</p><p>', $data);
            $data = preg_replace('/\n/', '<br>', $data);
            //$data = '<p>'.$data.'</p>';
        }

        return $data;
    }

    /**
     * Prepares a string extracted from the DB for display
     * Optionally escapes all html characters and quotes
     * Inserts <br> tags instead of newlines
     * Optionally changes all link appearances to html anchor tags (ie: 'http://www.yahoo.com' to '<a href="http://www.yahoo.com">http://www.yahoo.com</a>')
     * @param string $data the string to modify
     * @param bool $replace_links_with_anchors whether to change links to anchor tags
     * @param bool $escape whether to escape special html characters
     * @return modified string
     */
    public static function formatDBString($data, $replace_links_with_anchors, $escape=true, $remove_newlines=false, $nl2br=true){
        // omit formatting of data for api calls
        if (self::$is_api_call)
        {
            return $data;
        }

        $domain = ArrayHelper::elem($data, 'domain', SystemHelper::getDomain());

        if($replace_links_with_anchors){
            // get math expressions before escaping
            $thunks = array();
            preg_match_all('/\[math\](.*?)\[\/math\]/si', $data, $matches);
            for ($i=0; $i < count($matches[0]); $i++) {
                $position = strpos($data, $matches[0][$i]);
                $thunks[]  = $matches[1][$i];
            }
        }

        if( $escape ){
            //escape the content
            $data = htmlspecialchars($data, QUOTE_STYLE);
        }

        if($replace_links_with_anchors){
            $data = "!$data!";
            $data = str_replace("\nwww.", "\nhttp://www.", $data);
            $data = str_replace(" www.", ' http://www.', $data);
            $data = str_replace("!www.", ' http://www.', $data);
            $data = str_replace("\nedmo.do/j/", "\nhttps://edmo.do/j/", $data);
            $data = str_replace(" edmo.do/j/", " https://edmo.do/j/", $data);
            $data = str_replace("!edmo.do/j/", " https://edmo.do/j/", $data);

            $regexp = '/[[:alpha:]]+:\/\/[^<>[:space:]]+[[:alnum:]|\/|@][\.]?[ |\n|\r|!]/siU';

            if( strlen($data) < 20000 ){
                if(preg_match_all($regexp, $data, $matches, PREG_SET_ORDER)) {
                    foreach($matches as $match) {
                        $length   = strlen($match[0]);
                        $url      = substr($match[0], 0, $length-1);
                        $abb_url  = $url;
                        $url = rtrim($url, ".");
                        if (strlen($abb_url) > 45) {
                            $abb_url = substr($abb_url, 0, 45)."...";
                        }
                        $ending = substr($match[0], $length-1, $length);

                        $data = str_replace($match[0], "<a class=\"word-wrap\" target=\"_blank\" href=\"".$url."\">$abb_url</a>$ending", $data);
                    }
                }
            }
            $data = substr($data, 1, strlen($data)-2);

            // replace math expressions
            preg_match_all('/\[math\](.*?)\[\/math\]/si', $data, $matches);
            for ($i=0; $i < count($matches[0]); $i++) {
                $position = strpos($data, $matches[0][$i]);
                $hash     = md5($thunks[$i]);
                $url      = NEW_MATH_IMAGES_SERVER . "$hash.png";
                $data     = substr_replace($data, "<a href=\"$url\" rel=\"facebox\"><img src=\"$url\" /></a>", $position, strlen($matches[0][$i]));
            }
        }

        if ( $remove_newlines ){
            $data = preg_replace(array('/\r/m','/\n{2,}/m'), array("\n","\n"), $data);
        }
        else{
            $data = preg_replace(array('/\r/m','/\n{3,}/m'), array("\n","\n\n"), $data);
        }

        //replace newlines with <br>
        if ($nl2br){
            $data = str_replace("\n", HTML_BREAK_TAG, $data);
        }

        return $data;
    }

    /**
     * Formats a system message
     * @param array $message
     */
    private function processSystemMessage(&$message, $language){
        // translate the system message to the user's language
        $content_array = explode(':', $message['content']);
        $account_info = AccountHandler::getInstance()->getAccountInfo();

        // The first element is the system message identifier
        $identifier = $content_array[0];

        // Get the teacher name (used for the Teacher's Profile Feed messages)
        $teacher_name = '';
        $teacher_id = 0;

        switch($identifier){
            // New group system message
            // Expected message content:
            // new-group:group_id
            case 'new-group':
                $group_id = $content_array[1];
                $group_info = Groups::getInstance()->getGroup($group_id);
                $message['content'] = '';
                if( !empty($group_info) ){
                    $message['content'] = self::$system_msgs_translator->_($identifier, $group_info['title'], $group_info['code']);
                }
                break;

            // Teacher joined group system message
            // Expected message content:
            // teacher-joined-group:group_id-user_id
            case 'teacher-joined-group':
                $join_info  = explode('-', $content_array[1]);
                $group_info = Groups::getInstance()->getGroup($join_info[0]);
                $message['content'] = self::$system_msgs_translator->_($identifier, $group_info['title']);
                break;

            case 'new-user':
                $account_info = AccountHandler::getInstance()->getAccountInfo();
                if( isset($account_info['type']) && $account_info['type'] == 'TEACHER' )
                    if(USE_NEW_USER_XP){
                        $should_see_welcome_message = AbHandler::idInTest($account_info['user_id'], 'checklist_entry_point') == 'stream' ? true : false;
                        $should_see_welcome_message = true;
                        if ($should_see_welcome_message){
                            ActionsTracking::getInstance()->insert(array(
                                ActionsTrackingConstants::USER_AGENT => getenv('HTTP_USER_AGENT'),
                                ActionsTrackingConstants::EVENT_TYPE => 'stream-get-started-message',
                                ActionsTrackingConstants::USER_ID    => $account_info['user_id'],
                            ));
                            $message['content'] = self::$system_msgs_translator->_('get-started-alt','<br>','<a href="/get-started">', '</a>');
                        }
                        else{
                            $message['content'] = self::$system_msgs_translator->_('new-teacher-alt', '<br>', '<a id="system-group-create" a="Create group from system message clicked." href="javascript:;">', '</a>');
                        }
                    }
                    else
                        $message['content'] = self::$system_msgs_translator->_('new-teacher', '<br>');
                else if (isset($account_info['type']) && $account_info['type'] == 'STUDENT')
                    $message['content'] = self::$system_msgs_translator->_('new-student');
                else
                    $message['content'] = self::$system_msgs_translator->_('new-user', '<br>');
                break;

            // User has been added to a Small Group
            // Expected message content:
            // added-to-small-group:group_id
            case 'added-to-small-group':
                $group_info = Groups::getInstance()->getGroup( $content_array[1], false, true );
                $group_title = $group_info['title'];
                if (isset($group_info['parent_group_title'])){
                    $group_title = $group_info['title'] . ' (' . $group_info['parent_group_title'] .')';
                }
                if(count($message['receivers']) == 1 && $message['receivers'][0]['id'] == $account_info['user_id'] )
                {
                    $message['content'] = self::$system_msgs_translator->_('you-joined-small-group', $group_title);
                }
                else{
                    $message['content'] = self::$system_msgs_translator->_('user-joined-group', $group_title);
                }
                break;

            default:
                $message['content'] = self::$system_msgs_translator->_($identifier);
                break;
        }

    }

    /**
     * Formats a user's name (e.g. Mr. Jimenez if it's a teacher, Diego J. if it's a student)
     * @param array $user is the array with a user's info
     * @param string $language is the viewing user's language
     * @param bool $abbreviated whether to display student names as 'Diego J.' or 'Diego Jimenez'
     * @param bool $show_teacher_first whether to display teacher's first name with their title
     * return string $name with the formatted name
     */
    public static function formatName($user, $language, $abbreviated = true, $show_teacher_first = false){
        $name = '';
        if(isset($user['type']) && ($user['type'] == 'TEACHER' || $user['type'] == 'ADMINISTRATOR') && $user['title'] != 'NONE'){
            $first = '';
            if ($show_teacher_first) {
                $first = $user['first_name'].' ';
            }
            self::initializeTranslators($language);

            // only call translator if there is actually a title
            if (trim($user['title']) != '') {
                $translated_title = self::$layout_translator->_($user['title']);
            } else {
                $translated_title = '';
            }

            // Title is added
            if ( $language != 'zh' ){
                $name = $translated_title . ' ' . ucwords($first.$user['last_name']);
            }
            else{
                $name = ucwords($first.$user['last_name']) . $translated_title;
            }
        }else{
            if (isset($user['type']) && $user['type'] == 'PUBLISHER'){
                $name = $user['first_name'];
            }elseif($abbreviated && isset($user['type']) && $user['type'] == 'STUDENT'){
                // if mbstring extension is installed, use it to handle multibyte strings
                if (function_exists('mb_substr')) {
                    $last_initial = mb_substr($user['last_name'], 0, 1, 'UTF-8');
                } else {
                    $last_initial = substr($user['last_name'], 0, 1);
                }
                $name = $user['first_name'] . ' ' . $last_initial . '.';
            }else{
                $name = ucwords($user['first_name'].' '.$user['last_name']);
            }
        }
        return $name;
    }

    /**
     * Formats a parents's name (e.g. "Charles Li (Dad of Galen Li)")
     * @param array $parent_info is the array with a parent's info
     * @param array $student_info is the array with a student's info
     * @param string $relation is the relationship between the student and the parent ('MOM', 'DAD', 'OTHER')
     * @param string $language is the viewing user's language
     * return string $parent_name with the formatted name
     */
    public static function formatParentName($parent_info, $student_info = null, $relation, $other_relation = null, $language, $viewer_type = 'TEACHER',$has_many_students = false)
    {
        self::initializeTranslators($language);

        $translated_relation = ($relation == 'OTHER') ? $other_relation : self::$layout_translator->_($relation);

        if($viewer_type == 'TEACHER')
        {
            $more_students = ($has_many_students) ? self::$layout_translator->_('and-more') : '';
            $parent_name = self::$layout_translator->_('parent-name-as-teacher', $parent_info['first_name'], $parent_info['last_name'], $translated_relation, $student_info['first_name'], $student_info['last_name'],$more_students);

        }
        else
        {
            //student is viewer
            $parent_name = self::$layout_translator->_('parent-name-as-student', $parent_info['first_name'], $parent_info['last_name'], $translated_relation);
        }
        return $parent_name;
    }

    /**
     * Appends all receivers to a message.
     * @param $messages is the array containing all the messages
     * @param $pos is the position of the message in the $messages array.
     * @param $language is the language used by the current user
     * @param $viewer_id is the id of the current user
     * @param $viewer_type is the type of the current user
     * @param $taught_groups is a boolean flag of group_ids being taught by the user
     */
    private function extractMessageReceivers( &$messages, &$pos, $language, $viewer_id, $viewer_type, $taught_groups = array(),$filters = array(), $teacher_ids = array()){
        $message = $messages[$pos];
        $receivers = array();
        $message_receiver = $message;
        $message_count = count($messages);
        $msg_submit_rights =
        $msg_public_rights =
        $msg_delete_rights =
        $msg_hide_rights = false;
        $msg_reply_rights = !empty($viewer_id) ? true : false;
        $modified_rights = false;
        $only_student_recipients = true;
        $sent_to_community = false;
//        $sent_to_connections = false;
        $message['sent'] = ($viewer_id == $message['creator_id']);

        while($message_receiver['message_id'] == $message['message_id'])
        {
            $full_receiver_info = $this->getMessageReceiverInfo($message_receiver, $language, $viewer_id, $viewer_type, $taught_groups, $filters);

            if(isset($full_receiver_info))
            {
                //Add the receiver to the list
                $rights = $full_receiver_info['rights'];

                if(isset($rights['submit'])){
                    $msg_submit_rights = $rights['submit'];
                }
                if(isset($rights['public'])){
                    $msg_public_rights = true;
                }
                if(isset($rights['delete'])){
                    $msg_delete_rights = true;
                }else if(isset($rights['hide'])){
                    $msg_hide_rights = true;
                }

                //if a user does not have permissions in one group might want to check the rest
                if(isset($rights['reply']) && !$rights['reply'] && !$modified_rights){
                    $msg_reply_rights = false;
                    $modified_rights = true;
                }

                //if a user already has permissions to reply on this post, shouldnt overwrite it on the next while iteration
                if(isset($rights['reply']) && $rights['reply']){
                    $msg_reply_rights = true;
                    $modified_rights = true;
                }

                if(isset($full_receiver_info['only_student_recipients']) && !$full_receiver_info['only_student_recipients']){
                    $only_student_recipients = false;
                }
                if(isset($full_receiver_info['sent_to_community'])){
                    $sent_to_community = true;
                }

                if(isset($rights['edit'])){
                    $msg_edit_rights = $rights['edit'];
                }

                $receivers[] = $full_receiver_info['receiver_info'];

                // don't allow replies from students to teachers who are no longer their teacher via a group
                if ($viewer_type == 'STUDENT') {
                    $teacher_receiver_ids = array();
                    foreach ($receivers as $receiver) {
                        if (!empty($receiver['user_type']) && $receiver['user_type'] == 'TEACHER') {
                            array_push($teacher_receiver_ids,$receiver['id']);

                        }

                    }



                    // check to see if at least one of the receivers is a teacher in one of the student's groups
                    if (count($teacher_receiver_ids) > 0) {
                        $is_active_teacher = count(array_uintersect($teacher_receiver_ids,$teacher_ids,'strcasecmp')) > 0 ? true : false;
                        $temp_reply_rights = $is_active_teacher;
                    } else {
                        $temp_reply_rights = true;
                    }

                    // check to see if sender is a teacher in one of the student's groups, otherwise no replies allowed
                    if ($message['sender_type'] == 'TEACHER' && !(count(array_uintersect(array($message['creator_id']),$teacher_ids,'strcasecmp')) > 0 ? true : false)) {
                        $temp_reply_rights = false;
                    }

                    if ($msg_reply_rights) {
                        $msg_reply_rights = $temp_reply_rights;
                    }
                }


                // change the variable $message to reference the next message in the list!
                // this is needed to set the correct reciver to the original posted_in_id (for messages that are sent to multiple groups)
                // so that a check of the user has access the a message will not break
                $message = $messages[$pos];
                $message['sent'] = ($viewer_id == $message['creator_id']); // re-set the 'sent' key
            }
            // The user can't see the recipient, shouldn't have other rights either
            elseif ($viewer_type == 'STUDENT' && (
                    $message_receiver['posted_in'] == 'connections'      ||
                    $message_receiver['posted_in'] == 'school'           ||
                    $message_receiver['posted_in'] == 'school_vip'       ||
                    $message_receiver['posted_in'] == 'school_parents'   ||
                    $message_receiver['posted_in'] == 'district'         ||
                    $message_receiver['posted_in'] == 'district_vip'     ||
                    $message_receiver['posted_in'] == 'district_parents'
                )
            ) {
                $msg_reply_rights = false;
            }

            //notice this affects the "parent" function behaviour
            if (++$pos < $message_count) {
                $message_receiver = $messages[$pos];
            } else {
                $message_receiver = array('message_id' => null);
            }
        }

        $message['submit_rights'] = $msg_submit_rights;
        unset($message['receiver_first_name'], $message['receiver_last_name'], $message['receiver_type'], $message['receiver_title']);
        $message['public_rights'] = $msg_public_rights;
//        $message["submit_rights"] = ArrayHelper::elem()
        $message['delete_rights'] = ($message['sent'] && $this->feed_type != 'COMMUNITY_FEED'
                && $this->feed_type != 'SCHOOL_FEED' && $this->feed_type != 'DISTRICT_FEED') || $msg_delete_rights;
        $message['hide_rights'] =  ($message['delete_rights']) ? false : $msg_hide_rights;

        // This was mainly added for publishers,
        // that's why if edit not set, we use the same "delete rights" which was previously used for this.
        $message['edit_rights'] = (isset($msg_edit_rights)) ? $msg_edit_rights :$message['delete_rights'] ;

        // Parents have no reply rights
        $msg_reply_rights = ($viewer_type == 'PARENT') ? 0 : $msg_reply_rights;
        //new condition to never let admins to reply on user_feed, be careful of when this filter comes on
        $message['reply_rights'] = ($viewer_type == 'ADMINISTRATOR' && isset($filters['user_feed_id']) && !empty($filters['user_feed_id'])) ? 0 :  $msg_reply_rights;
        $message['only_student_recipients']  = $only_student_recipients;
        if (!isset($message['sent_to_community']))
            $message['sent_to_community']  = $sent_to_community;
        $message['receivers'] = $receivers;
        if($viewer_type == 'PARENT' && isset($this->parent_info['students_info_for_parent']['students'][$message['creator_id']]['hex'])){
            $message['sender_color'] = $this->parent_info['students_info_for_parent']['students'][$message['creator_id']]['hex'];
        }
        return $message;
    }

    /**
     * Gets required information on a message's receiver. For example, the name of a group where it was posted and its color
     * @param $message_receiver the data of the receiver
     * @param string $language viewing user's language
     * @param int $viewer_id base user id for message retrieval
     * @param string $viewer_type base user type for message retrieval
     * @param array $taught_groups a boolean map of the ids of the groups taught by the user
     * @return array $receiver_info contains the receiver's info. It is null if this receiver is not to be seen in the recipients list by the current user
     */
    private function getMessageReceiverInfo($message_receiver, $language, $viewer_id, $viewer_type, $taught_groups = array(),$filters = array() ){
        $receiver_info = null;
        $result = null;
        $receiver_id = $message_receiver['posted_in_id'];
        $receiver_info = array('type' => $message_receiver['posted_in'], 'id' => $receiver_id, 'receiver_name' => $message_receiver['posted_in'], 'receiver_color' => '#444444');
        $rights = array();
        $translator = TranslationHelper::getInstance(PATH2_LANGUAGES . 'recipients.tmx', $language);

        switch($message_receiver['posted_in'])
        {
            case 'group':
            case 'group_parents':
                $group_data = array();
                if( $this->feed_type == 'COMMUNITY_FEED' || $this->feed_type == 'SCHOOL_FEED' || $this->feed_type == 'DISTRICT_FEED' ||  $this->feed_type == 'POST_FEED' )
                {
                    if ( isset($this->community_groups[$receiver_id]) ){
                        $group_data = $this->community_groups[$receiver_id];
                    }
                    else{
                        $group_data = Groups::getInstance()->getGroup($receiver_id, false);
                        $this->community_groups[$receiver_id] = $group_data;
                        if( !isset($this->viewed_user_groups[$receiver_id])){
                            $rights['reply'] = false;
                        }
                        else
                        {
                            $rights['reply'] = true;
                        }

                    }
                    if ( $this->community_user_status == ADMINS ){
                        $rights['delete'] = true;
                    }
                    if ($this->feed_type == 'SCHOOL_FEED' || $this->feed_type == 'DISTRICT_FEED'){
                        $rights['reply'] = false;
                    }
                }
                elseif( !empty($this->parent_info['students_info_for_parent']['students_groups_by_id'][$receiver_id]) )
                {
                    //Parent feed
                    $group_data = $this->parent_info['students_info_for_parent']['students_groups_by_id'][$receiver_id];
                    //Change the title to include the children's names that belong to that group
                    if ( $message_receiver['posted_in'] == 'group_parents' ){
                        $group_data['title'] = $translator->_('group-parents', $group_data['title']);
                    }
                    else
                    {
                        //Parent can't reply to messages sent to their children only
                        $rights['reply'] = false;
                    }
                    $group_data['title'] = $this->addChildrensNames($group_data['title'], $group_data['student_ids']);
                }
                elseif( isset($this->viewed_user_groups[$receiver_id]) )
                {
                    $group_data = $this->viewed_user_groups[$receiver_id];
                    if ( $message_receiver['posted_in'] == 'group_parents' ){
                        $group_data['title'] .= $translator->_('parents');
                    }
                    if(
                        ($group_data['read_only'] && !$group_data['is_small_group']) ||
                        ($group_data['main_read_only'] && $group_data['read_only'] && $group_data['is_small_group'] )
                    ){
                        //People with "read only" status can't reply to posts
                            $rights['reply'] = false;
                    }
                }


                if( count($group_data) ) // the message was sent to a group
                {
                    //The viewed user belongs to this group
                    $receiver_info['receiver_name'] = $group_data['title'];
                    $receiver_info['group_creator_id'] = $group_data['creator_id'];
                    $receiver_info['moderated'] = $group_data['moderated'];
                    if( isset($group_data['hex']) ){
                        $receiver_info['receiver_color'] = $group_data['hex'];
                    }

                    $rights["submit"] = true; // Most users can submit/turn-in a submission for an assignment.

                    if( (
                            $receiver_info['group_creator_id'] == $viewer_id // Group-owner
                            && $this->feed_type != 'COMMUNITY_FEED'         // & it's not a community feed.
                        )
                        || (isset( $taught_groups[$receiver_id] ) // OR the group-ID's in groups-taught list.
                            &&
                            ( ($taught_groups[$receiver_id] && ($message_receiver['creator_id'] == $viewer_id || $message_receiver['creator_id'] != $receiver_info['group_creator_id']))
                                || $message_receiver['sender_type'] != 'TEACHER'
                                || $message_receiver['creator_id'] == $viewer_id))
                    )
                    {
                        // This user created the group,
                        // he may make the message public and delete it
                        $rights['public'] = true;
                        $rights['delete'] = true;
                        $rights["submit"] = false; // A group-owner or co-teacher for the group cannot turn-in a submission.
                    }

                    // A STUDENT cannot edit a STUDENT's post if a group's moderated.
                    // Or in other words: they can't edit their own post under moderation.
                    if( $viewer_type == 'STUDENT'
                        && $message_receiver['sender_type'] == 'STUDENT'
                        && $group_data['moderated'] )
                    {
                        $rights['edit'] = false;
                    }

                    if( $viewer_type == 'ADMINISTRATOR'
                        && isset($filters['user_feed_id'])
                        && ! empty($filters['user_feed_id']) )
                    {
                        if($filters['user_feed_id'] == $message_receiver['creator_id'])
                        {
                            $rights['delete'] = true;
                            $rights['edit'] = false;
                        }
                    }

                    $result = array(
                        'receiver_info' => $receiver_info,
                        'rights'        => $rights,
                        'only_student_recipients' => false
                    );
                }

                break;
            case 'user':
                $recipient_name = '';
                $format_name = true;

                // Teachers can mark as public direct messages sent to or by them
                if ( $viewer_type == 'TEACHER' && $viewer_id == $message_receiver['creator_id'] ){
                    $rights['public'] = true;
                }
                if($receiver_id == $viewer_id)
                {
                    $rights['reply'] = true;
                    if($viewer_type == 'TEACHER'){
                        //teachers can delete any direct message they receive
                        $rights['delete'] = true;
                        $rights['public'] = true;

                    }

                    if($viewer_type == 'TEACHER' && $message_receiver['sender_type'] != 'STUDENT'){
                        $rights['edit'] = false;
                    }

                    if($viewer_type == 'PUBLISHER'){
                        $rights['delete'] = true;
                        $rights['edit'] = false;
                    }
                    if($language == 'en'){
                        //This recipient is 'me'
                        $recipient_name = self::$layout_translator->_('me');
                        $format_name = false;
                    }
                }
                elseif(isset($this->parent_info['parents'][$receiver_id]))
                {
                    //It's a parent
                    $recipient_name = $this->parent_info['parents'][$receiver_id]['name'];
                    $format_name = false;
                }
                elseif( $message_receiver['receiver_type'] == 'PARENT' )
                {
                    $receiver_info['receiver_color'] = EDMODO_COLOR;
                    $parent_students = ParentsStudents::getInstance()->getStudentsInfo($receiver_id);
                    if(count($parent_students))
                    {
                        $has_many = (count($parent_students) > 1) ? true : false;

                        $a_student = Users::getInstance()->getUserInfo($parent_students[0]['student_id']) ;
                        $recipient = array('first_name' => $message_receiver['receiver_first_name'],
                                           'last_name' => $message_receiver['receiver_last_name'],
                                           'type' => $message_receiver['receiver_type'],
                                           'title' => $message_receiver['receiver_title']
                        );
                        $recipient_name = $this->formatParentName($recipient,$a_student,$parent_students[0]['relation'],$parent_students[0]['other_relation'],$language,'TEACHER',$has_many);
                        $format_name = false;
                    }
                }
                if($format_name)
                {
                    //the message was sent directly to a person
                    $recipient = array('first_name' => $message_receiver['receiver_first_name'],
                                       'last_name' => $message_receiver['receiver_last_name'],
                                       'type' => $message_receiver['receiver_type'],
                                       'title' => $message_receiver['receiver_title']
                    );
                    $recipient_name = self::formatName($recipient, $language);
                }

                $only_student_recipients = false;
                $sent_to_publisher = null;
                switch ( $message_receiver['receiver_type'] ) {
                    case 'TEACHER':
                        $receiver_info['receiver_color'] = EDMODO_COLOR;
                        break;
                    case 'PUBLISHER':
                        $receiver_info['receiver_color'] = EDMODO_COLOR;
                        // Students can't reply to posts sent to publishers
                        if ( $viewer_type == 'STUDENT' ){
                            $rights['reply'] = false;
                        }
                        elseif ( $viewer_type == 'TEACHER' ) {
                            if ( !CoppaHandler::userIsCoppaVerified($viewer_id) ){
                                $rights['reply'] = false;
                            }
                        }
                        if( $this->feed_type == 'HOME_FEED' && $message_receiver['creator_id'] != $viewer_id){
                            //The user can hide this message
                            $rights['hide'] = true;
                        }
                        $sent_to_publisher = true;
                        break;
                    case 'STUDENT':
                        if($viewer_type == 'PARENT'){
                            if(isset($this->parent_info['students_info_for_parent']['students'][$receiver_id]['hex']))
                            {
                                $receiver_info['receiver_color'] = $this->parent_info['students_info_for_parent']['students'][$receiver_id]['hex'];
                            }
                            if($message_receiver['creator_id'] != $viewer_id){
                                //Parent can't reply
                                $rights['reply'] = false;
                            }
                        }
                        $only_student_recipients = true;
                        break;
                }

                $receiver_info['receiver_name'] = $recipient_name;
                $receiver_info['user_type'] = $message_receiver['receiver_type'];

                if($viewer_type == 'ADMINISTRATOR' && isset($filters['user_feed_id']) && !empty($filters['user_feed_id']))
                {
                    if($filters['user_feed_id'] == $message_receiver['creator_id'])
                    {
                        $rights['delete'] = true;
                        $rights['edit'] = false;
                    }
                }

                //this makes sure that if user is student viewing his assig, other recipients wont show
                if(isset($filters['direct_user_id']) && !empty($filters['direct_user_id']) && $receiver_id != $viewer_id && $viewer_type == 'STUDENT')
                    $result = null;
                else
                {
                    $result = array('receiver_info' => $receiver_info,
                                    'rights' => $rights,
                                    'only_student_recipients' => $only_student_recipients,
                                    'sent_to_community' => $sent_to_publisher
                    );
                }
                break;

            case 'connections':
                if ( $viewer_type == 'STUDENT' ){
                    $rights['reply'] = false;
                }
                $receiver_info['receiver_color'] = EDMODO_COLOR;
                $receiver_info['receiver_name'] = self::$layout_translator->_('connections-receivers');
                if ( $message_receiver['sender_type'] == 'PUBLISHER' && $viewer_type == 'STUDENT' ){
                    $rights['reply'] = false;
                }
                if ( $message_receiver['sender_type'] == 'PUBLISHER' && $viewer_id == $message_receiver['creator_id'] ){
                    $rights['edit'] = true;
                }
                if ( $message_receiver['sender_type'] == 'PUBLISHER' && $viewer_type == 'TEACHER' ) {
                    if ( CoppaHandler::userIsCoppaVerified($viewer_id) ){
                        $rights['reply'] = false;
                    }
                }
                if( $this->feed_type == 'HOME_FEED' && $message_receiver['creator_id'] != $viewer_id){
                    //The user can hide this message
                    $rights['hide'] = true;
                }
                if($viewer_type == 'TEACHER' && ConnectionsHandler::getInstance()->connected($viewer_id,$message_receiver['creator_id']))
                {
                    $rights['reply'] = true;
                }
                if($viewer_id == $message_receiver['creator_id'])
                {
                    $rights['reply'] = true;
                }
                /*if ( $message_receiver['sender_type'] != 'PUBLISHER' ){
                    $receiver_info['sent_to_connections'] = true;
                } */
                $result = array('receiver_info' => $receiver_info,
                                'rights' => $rights,
                                'only_student_recipients' => false
                );
                break;

            case 'school':
                if ( $viewer_type == 'STUDENT' ){
                    $rights['reply'] = false;
                }
                if(isset($this->institutional_info['type']) && $this->institutional_info['type'] == 'STUDENT'){
                    $schools = $this->institutional_info['schools'];
                    if(isset($schools[$receiver_id])){
                        //student belongs to this school
                        if($viewer_type == 'PARENT')
                            $receiver_info['receiver_name'] = $this->addSchoolInfo($receiver_info, $rights, 'inst-all',true);
                        else
                            $receiver_info['receiver_name'] = $schools[$receiver_id]['school_name'];

                        $receiver_info['receiver_color'] = EDMODO_COLOR;
                        $rights['reply'] = false;
                        $result = array('receiver_info' => $receiver_info,
                                        'rights' => $rights,
                                        'only_student_recipients' => false
                        );
                    }
                }else {
                    $result = $this->addSchoolInfo($receiver_info, $rights, 'inst-all',($viewer_type == 'PARENT'));
                }
                break;

            case 'school_vip':
                if ( $viewer_type == 'STUDENT' ){
                    $rights['reply'] = false;
                }
                $result = $this->addSchoolInfo($receiver_info, $rights, 'inst-vip');
                break;
            case 'school_parents':
                if ( $viewer_type == 'STUDENT' ){
                    $rights['reply'] = false;
                }
                $result = $this->addSchoolInfo($receiver_info, $rights, 'inst-parents');
                break;

            case 'district':
                if ( $viewer_type == 'STUDENT' ){
                    $rights['reply'] = false;
                }
                if(isset($this->institutional_info['type']) && $this->institutional_info['type'] == 'STUDENT'){
                    $districts = $this->institutional_info['districts'];
                    //student belongs to this district
                    if(isset($districts[$receiver_id])){
                        $receiver_info['receiver_name'] = $districts[$receiver_id]['district_name'];
                        $receiver_info['receiver_color'] = EDMODO_COLOR;
                        $rights['reply'] = false;
                        $result = array('receiver_info' => $receiver_info,
                                        'rights' => $rights,
                                        'only_student_recipients' => false
                        );
                    }
                }else{
                    $result = $this->addDistrictInfo($receiver_info, $rights, 'inst-all',($viewer_type == 'PARENT'));
                }
                break;
            case 'district_vip':
                if ( $viewer_type == 'STUDENT' ){
                    $rights['reply'] = false;
                }
                $result = $this->addDistrictInfo($receiver_info, $rights, 'inst-vip');
                break;
            case 'district_parents':
                if ( $viewer_type == 'STUDENT' ){
                    $rights['reply'] = false;
                }
                $result = $this->addDistrictInfo($receiver_info, $rights, 'inst-parents');
                break;
            case 'community':
                if ( $viewer_type == 'STUDENT' ){
                    $rights['reply'] = false;
                }
                if($receiver_id == SUPPORT_COMMUNITY_ID){
                    //Support Community

                    if( $viewer_type == 'TEACHER' ){
                        if($viewer_id == EDMODO_SYSTEM_USER_ID){
                            //the all powerful edmodo user
                            $rights['delete'] = true;
                        }
                        $is_verified_teacher = CoppaHandler::userIsCoppaVerified($viewer_id);

                        if($message_receiver['creator_id'] != $viewer_id && !$is_verified_teacher){
                            $rights['reply'] = false;
                        }
                    }
                }
                else if($receiver_id == ADMIN_SUPPORT_COMMUNITY_ID){
                    //Admin Support Community

                    if($viewer_id == EDMODO_SYSTEM_USER_ID){
                        //the all powerful edmodo user
                        $rights['delete'] = true;
                    }


                }
                else if( $this->feed_type == 'HOME_FEED' && $message_receiver['creator_id'] != $viewer_id){
                    //The user can hide this message
                    $rights['hide'] = true;

                    // The user can only reply if he's verified
                    $current = Profiles::getInstance()->find($viewer_id)->current();
                    if($current!==NULL){
                        $profile=$current->toArray();
                        if ( !CoppaHandler::userIsCoppaVerified($viewer_id) ){
                            $rights['reply'] = false;
                        }
                    }
                }
                else {
                    // The user can only reply if he's verified
                    $current = Profiles::getInstance()->find($viewer_id)->current();
                    if($current!==NULL){
                        $profile=$current->toArray();
                        if ( !CoppaHandler::userIsCoppaVerified($viewer_id) ){
                            $rights['reply'] = false;
                        }
                    }
                }

                if(  $message_receiver['creator_id'] == $viewer_id || SubjectCommunities::getInstance()->userAdminsCommunity($viewer_id, $receiver_id) ){
                    $rights['delete'] = true;
                }

                if($viewer_type == 'STUDENT')
                {
                    $rights['reply'] = false;
                }

                $result = array('receiver_info' => SubjectCommunityHandler::getInstance($language)->getReceiverInfo($receiver_id),
                                'rights' => $rights,
                                'only_student_recipients' => false,
                                'sent_to_community' => true
                );
                break;
        }

        return $result;
    }

    private function addDistrictInfo($receiver_info, $rights, $translation_key, $use_key = false){
        $result = null;
        $receiver_id = $receiver_info['id'];
        if(isset($this->institutional_info['district_id']) && $this->institutional_info['district_id'] == $receiver_id){
            //user belongs to this district
            $receiver_info['receiver_name'] = self::$layout_translator->_($translation_key, $this->institutional_info['district_name']);
            $receiver_info['receiver_color'] = EDMODO_COLOR;
            //check if the current user is a district admin and has rights to delete the message
            if(isset($this->institutional_info['is_district_admin'])){
                $rights['delete'] = true;
            }
            $result = array('receiver_info' => $receiver_info,
                            'rights' => $rights,
                            'only_student_recipients' => false
            );
        }
        elseif( isset($this->parent_info['students_info_for_parent']['students_districts_by_id'][$receiver_id]) ){
            if($use_key)
                $receiver_info['receiver_name'] = self::$layout_translator->_($translation_key, $this->parent_info['students_info_for_parent']['students_districts_by_id'][$receiver_id]['district_name']);
            else
                $receiver_info['receiver_name'] = self::$layout_translator->_('inst-parents-for-parent', $this->parent_info['students_info_for_parent']['students_districts_by_id'][$receiver_id]['district_name']);
            $receiver_info['receiver_name'] = $this->addChildrensNames($receiver_info['receiver_name'], $this->parent_info['students_info_for_parent']['students_districts_by_id'][$receiver_id]['student_ids']);
            $receiver_info['receiver_color'] = $this->parent_info['students_info_for_parent']['students_districts_by_id'][$receiver_id]['hex'];
            $rights['reply'] = false;
            $result = array(
                'receiver_info' => $receiver_info,
                'rights' => $rights
            );
        }
        return $result;
    }

    private function addSchoolInfo($receiver_info, $rights, $translation_key,$use_key = false){
        $result = null;
        $receiver_id = $receiver_info['id'];
        if(isset($this->institutional_info['school_id']) && $this->institutional_info['school_id'] == $receiver_id){
            //user belongs to this school (teacher/school admin)
            $receiver_info['receiver_name'] = self::$layout_translator->_($translation_key, $this->institutional_info['school_name']);
            $receiver_info['receiver_color'] = EDMODO_COLOR;
            if(isset($this->institutional_info['is_school_admin'])){
                $rights['delete'] = true;
            }
            $result = array('receiver_info' => $receiver_info,
                            'rights' => $rights,
                            'only_student_recipients' => false
            );
        }else if(isset($this->institutional_info['is_district_admin'])){
            if(isset($this->institutional_info['district_schools'][$receiver_id])){
                $school_name = $this->institutional_info['district_schools'][$receiver_id];
            }else{
                $school = Schools::getInstance()->find($receiver_id)->current();
                $school_name = $school['school_name'];
            }
            $receiver_info['receiver_name'] = self::$layout_translator->_($translation_key,  $school_name);
            $receiver_info['receiver_color'] = EDMODO_COLOR;
            $rights['delete'] = true;
            $result = array('receiver_info' => $receiver_info,
                            'rights' => $rights,
                            'only_student_recipients' => false
            );
        }
        elseif( isset($this->parent_info['students_info_for_parent']['students_schools_by_id'][$receiver_id]) ){
            if($use_key)
                $receiver_info['receiver_name'] = self::$layout_translator->_($translation_key, $this->parent_info['students_info_for_parent']['students_schools_by_id'][$receiver_id]['school_name']);
            else
                $receiver_info['receiver_name'] = self::$layout_translator->_('inst-parents-for-parent', $this->parent_info['students_info_for_parent']['students_schools_by_id'][$receiver_id]['school_name']);
            $receiver_info['receiver_name'] = $this->addChildrensNames($receiver_info['receiver_name'], $this->parent_info['students_info_for_parent']['students_schools_by_id'][$receiver_id]['student_ids']);
            $receiver_info['receiver_color'] = $this->parent_info['students_info_for_parent']['students_schools_by_id'][$receiver_id]['hex'];
            $rights['reply'] = false;
            $result = array(
                'receiver_info' => $receiver_info,
                'rights' => $rights
            );
        }
        else{
            $school = Schools::getInstance()->find($receiver_id)->current();
            $school_name = $school['school_name'];
            $receiver_info['receiver_name'] = self::$layout_translator->_($translation_key,  $school_name);
            $receiver_info['receiver_color'] = EDMODO_COLOR;
            $result = array('receiver_info' => $receiver_info,
                            'rights' => $rights,
                            'only_student_recipients' => false
            );
        }
        return $result;
    }

    private function getModelInstance($type){
        $model = null;
        switch($type){
            case GROUP:
                $model = Groups::getInstance();
                break;
        }
        return $model;

    }

    public function processNewComments($messages, $comment_ids, $user_id)
    {
        if( count($comment_ids) )
        {
            $spotlight_map = $this->getSpottedReplies($user_id);

            //-------------------------------
            // Set a flag to see if the Spotlight should be refreshed
            foreach( $comment_ids as $comment_id => $i)
            {
                $messages[$i]['spotlight'] = isset($spotlight_map[$comment_id]);
            }
        }
        return $messages;
    }

    /**
     * Creates a boolean map where the key is the message_id of the reply.
     * It stores the resulting array in memory so that when called again, it doesn't have to do another DB call
     * @param int $user_id id of the user which spotted replies should be fetched from
     */
    public function getSpottedReplies($user_id)
    {
        if( !isset($this->spotted_replies[$user_id]) )
        {
            //-------------------------------
            // Get the comment ids in the Spotlight for this user
            $cids = Spotlights::getInstance()->getSpottedIds($user_id, 'REPLY');
            $spotted_replies = array();
            foreach( $cids as $cid )
            {
                $spotted_replies[$cid] = true;
            }
            $this->spotted_replies[$user_id] = $spotted_replies;
        }
        return $this->spotted_replies[$user_id];
    }

    /**
     * Prepares messages array for displaying. Each message is succeeded by its respective comments
     * @param array $messages with the main messages
     * @param array $message_ids contains the ids of the main messages along with their index in the $messages array
     * @param string $language viewing user's language
     * @param int $user_id base user id for message retrieval
     * @param string $user_type viewing user's type
     * @param array $filters
     * @return array: list with all the messages and comments properly arranged for display
     */
    private function processComments( $messages, $message_ids, $language, $user_id, $user_type, $filters = array() ){
        $processed_messages = $messages;
        $final_msg_count    = count($messages);
        $profiles_model     = Profiles::getInstance();

        if(count($message_ids)){
            $limit = MAX_REPLIES_FOR_POST;

            // NOTE:: Currently this logic only sets the limit for the Apps-API,
            // but we enable the comments-limit for both the website & the Mobile-API.
            if ( ( ! self::$is_api_call && SECONDARY_THEME_ENABLED) || self::$limit_comments_query ){
                $limit = DISPLAYED_COMMENT_COUNT;
            }
            $comments = NewComments::getInstance()->getCommentsForMessagesSafe(array_keys($message_ids), true, $limit);

            // Sender thumbs are fetched a max of once per different user
            $sender_thumbs = array();
            foreach ($comments as $comment){
                if ( empty( $sender_thumbs[$comment['creator_id']] ) ){
                    $sender_thumbs[$comment['creator_id']] = $profiles_model->getUserThumbnailSafe( $comment['creator_id'], $comment['username'] );
                }
            }

            foreach( $comments as $comment ){
                // Get the message_id
                $msg_id = $comment['comment_to'];

                //-------------------------------
                // Get the message array index in the "messages" array
                $i = $message_ids[$msg_id];

                if( $user_type != 'PARENT' || $messages[$i]['type'] != 'assignment'){
                    // Parents shouldn't see replies to assignments

                    $comment['sent'] = ($user_id == $comment['creator_id']);
                    $comment['sender_thumb'] = $sender_thumbs[$comment['creator_id']];
                    unset( $comment['username'] );

                    $comment['delete_rights'] =
                        $comment['sent']                          //I sent the reply
                        || $messages[$i]['public_rights']            //I created a group where the original message was sent to
                        || (isset($this->institutional_info['is_school_admin']) && $messages[$i]['delete_rights']) //I'm a school admin and can delete the original message
                        || (isset($this->institutional_info['is_district_admin']) && $messages[$i]['delete_rights']) //I'm a district admin and can delete the original message
                        || ($user_type == 'ADMINISTRATOR' && $comment['creator_id'] == $filters['user_feed_id']) //I'm a school admin and can delete the original message
                        || ($user_type == 'TEACHER' && $messages[$i]['only_student_recipients']) //I'm a teacher and the original message was only sent to individual users
                        || $user_id == EDMODO_SYSTEM_USER_ID;        //I'm Mr.Edmodo!

                    //Introducing New Edit Rights for Comments, basic case, use the same delete
                    $comment['edit_rights'] = $comment['delete_rights'];

                    if(($user_id != $comment['creator_id']) && $comment['sender_type'] == 'PARENT'){
                        $comment['edit_rights'] = false;
                    }

                    if(($user_id != $comment['creator_id']) && $user_type == 'ADMINISTRATOR'){
                        $comment['edit_rights'] = false;
                    }

                    foreach($messages[$i]['receivers'] as $receiver){
                        if($user_type == 'STUDENT' && $receiver['type'] == 'group' && $receiver['moderated'] ){
                            $comment['edit_rights'] = false;
                            break;
                        }
                        if(($user_id != $comment['creator_id']) && $comment['sender_type'] == 'TEACHER'){
                            //what about co-teacher?
                            if($receiver['type'] == 'group' && $receiver['group_creator_id'] != $user_id){
                                $comment['edit_rights'] = false;
                            }
                            if($receiver['type'] == 'group' && $receiver['group_creator_id'] == $user_id){
                                $comment['edit_rights'] = true;
                                break;
                            }
                            if($receiver['type'] == 'user'){
                                $comment['edit_rights'] = false;
                            }
                        }
                    }

                    $this->processMessageInfo($comment, $language);

                    //Get the position of the next message in the feed
                    $comment_pos = $final_msg_count;

                    foreach($processed_messages as $key => $proc_message){
                        if(isset($proc_message['message_id']) && $proc_message['message_id'] == $msg_id){
                            //original message
                            $processed_messages[$key]['comment_count'] = $comment['comment_count'];
                            if($processed_messages[$key]['comment_count'] >= MAX_REPLIES_FOR_POST){
                                //This post has reached the max number of replies
                                $processed_messages[$key]['reply_rights'] = 0;
                            }
                        }
                        if(isset($messages[$i + 1]) && isset($messages[$i + 1]['message_id'], $proc_message['message_id']) && $messages[$i + 1]['message_id'] == $proc_message['message_id']){
                            //next message
                            $comment_pos = $key;
                        }
                    }

                    //insert the comment into the $processed_messages array
                    array_splice($processed_messages, $comment_pos, 0, array($comment));
                    $final_msg_count++;
                }
            }
        }

        return $processed_messages;
    }

    public static function getMaxIdsInStream($all_messages){
        $max_message_id = 0;
        $max_comment_id = 0;
        $message_ids = array();
        if(isset($all_messages)){
            foreach($all_messages as $message){
                if(isset($message['message_id'])){
                    $message_ids[] = $message['message_id'];

                    if($message['message_id'] > $max_message_id){
                        $max_message_id = $message['message_id'];
                    }
                }else if(isset($message['comment_id']) && $message['comment_id'] > $max_comment_id){
                    $max_comment_id = $message['comment_id'];
                }
            }
        }
        return array('message_ids_in_stream' => $message_ids, 'max_message_id' => $max_message_id, 'max_comment_id' => $max_comment_id);
    }

    public function userHasRightsToAlterMessage($user_info, $message_id, $is_comment = false, $viewed_id = 0){
        $user_has_alter_rights = false;


        if( $user_info['user_id'] == EDMODO_SYSTEM_USER_ID )
        {
            $user_has_alter_rights = true;
        }
        else
        {
            if ( $is_comment ){
                $message_info = NewComments::getInstance()->getCommentInfo($message_id);
            }
            else{
                $message_info = Messages::getInstance()->getMessageInfo($message_id);
            }

            if( $message_info['creator_id'] == $user_info['user_id'] ){
                $user_has_alter_rights = true;
            }
            elseif( $user_info['type'] != 'STUDENT' ){
                if ( $is_comment ){
                    $message_recipients = Messages::getInstance()->getMessageRecipients($message_info['comment_to']);
                }
                else{
                    $message_recipients = Messages::getInstance()->getMessageRecipients($message_id);
                }
                $groups = Groups::getInstance();

                for( $i = 0; $i < count($message_recipients) && !$user_has_alter_rights; $i++ ){
                    $message_recipient = $message_recipients[$i];

                    switch($message_recipient['posted_in']){
                        case 'user':
                            if( $message_recipient['posted_in_id'] == $user_info['user_id'] ){
                                //direct message to me (a teacher)
                                $user_has_alter_rights = true;
                            }
                            else {
                                // original message was sent by me (a teacher)
                                $original_message_info = Messages::getInstance()->find($message_info['comment_to'])->current();
                                if ( $original_message_info['creator_id'] == $user_info['user_id'] ){
                                    $user_has_alter_rights = true;
                                }
                            }
                            if($user_info['type'] == 'ADMINISTRATOR' && UsersHandler::getInstance()->userAdminsUser( $user_info, $message_info['creator_id']))
                            {
                                $user_has_alter_rights = true;
                            }

                            break;
                        case 'group':
                        case 'group_parents':
                            $group_info = $groups->getGroupInfoForPossibleMember($message_recipient['posted_in_id'], $user_info['user_id']);
                            if( $group_info['user_owns_group'] == 1 || $group_info['co_teacher'] == 1 ){
                                $user_has_alter_rights = true;
                            }
                            if($user_info['type'] == 'ADMINISTRATOR' && UsersHandler::getInstance()->userAdminsUser( $user_info, $message_info['creator_id'] ) )
                            {
                                $user_has_alter_rights = true;
                            }
                            break;
                        case 'school':
                        case 'school_vip':
                        case 'school_parents':
                            if($user_info['type'] != 'STUDENT'){
                                //check if the teacher/admin is a member of the school
                                $user_school = Profiles::getInstance()->getIfUserVerifiedForSchool($user_info['user_id'], $message_recipient['posted_in_id']);
                                if( !empty($user_school) && ($user_school['code_verified'] == 1 || $user_school['admin_rights'] == 'SCHOOL') ){
                                    $user_has_alter_rights = true;
                                }else{
                                    //might be a district admin
                                    $district_admins = Schools::getInstance()->getDistrictAdminForSchool($message_recipient['posted_in_id'], false);
                                    foreach ( $district_admins as $district_admin ){
                                        if ($district_admin['user_id'] == $user_info['user_id']){
                                            $user_has_alter_rights = true;
                                            break;
                                        }
                                    }
                                }
                            }
                            break;
                        case 'district':
                        case 'district_vip':
                        case 'district_parents':
                            if($user_info['type'] != 'STUDENT'){
                                //check if the teacher/admin is a member of the district
                                $user_school = Profiles::getInstance()->getIfUserVerifiedForSchoolsWithinDistrict($user_info['user_id'], $message_recipient['posted_in_id']);
                                if( !empty($user_school) && $user_school['code_verified'] == 1 ){
                                    $user_has_alter_rights = true;
                                }else{
                                    //might be a district admin
                                    $district_admins = Districts::getInstance()->getDistrictAdmin($message_recipient['posted_in_id'], false);
                                    foreach ( $district_admins as $district_admin ){
                                        if ($district_admin['user_id'] == $user_info['user_id']){
                                            $user_has_alter_rights = true;
                                            break;
                                        }
                                    }
                                }
                            }
                            break;
                        case 'community':
                            // check if the user is an admin of the community
                            if (SubjectCommunities::getInstance()->userAdminsCommunity($user_info['user_id'], $message_recipient['posted_in_id'])){
                                $user_has_alter_rights = true;
                            }
                            break;
                    }
                }
            }
        }

        return $user_has_alter_rights;
    }

    /**
     * Returns true if the user given by $user_info has the rights to send a message to all of the receivers in $receivers
     * @param array $user_info information of a user that's trying to send a message
     * @param array $receivers all the receivers the user is trying to send a message to
     * @return bool: true if the user has rights to send a message to every one of the receivers
     */
    public function userHasRightsToSendMessage($user_info, $receivers, $message_data, $is_comment = false){
        $user_has_send_rights = true;

        if ( !$is_comment ) {
            $params = array('language' => 'en', 'account_info' => $user_info, 'exclude_publishers_from_results' => false );
            $possible_receivers = ShareboxHandler::getInstance()->getPossibleReceivers($params);

            // Right to send a message directly to each individual are checked
            if(isset($receivers['people']))
            {
                foreach( $receivers['people'] as $person ){
                    $user_found = false;
                    foreach ( $possible_receivers as $possible_receiver ){
                        if ( $possible_receiver['type'] == 'user' && $possible_receiver['id'] == $person ){
                            $user_found = true;
                        }
                    }
                    if (!$user_found) {
                        // Check if the user is actually a publisher and the sender a verified teacher
                        if ( !Users::getInstance()->userCanSendMessageToPublisher( $user_info['user_id'], $person ) ){
                            $user_has_send_rights = false;
                            break;
                        }
                    }
                }
            }


            // Right to send a message to other locations is checked
            if ( $user_has_send_rights ){
                for( $i = 0; $i < count($receivers['locations']) && $user_has_send_rights; $i++ ){
                    $location = $receivers['locations'][$i];
                    switch( $location['type'] ){
                        case 'group':
                        case 'group_parents':
                            $group_found = false;
                            foreach ( $possible_receivers as $possible_receiver ){
                                if ( $possible_receiver['type'] == 'group' && $possible_receiver['id'] == $location['id'] ){
                                    if ( $message_data['type'] == 'text' ){
                                        $group_found = true;
                                    }
                                    else if ( $user_info['type'] != 'STUDENT' ){
                                        $group_found = true;
                                    }
                                }
                            }
                            if (!$group_found) {
                                $user_has_send_rights = false;
                            }
                            break;
                        case 'school':
                        case 'school_parents':
                            if($user_info['type'] == 'STUDENT'){
                                $user_has_send_rights = false;
                            }else{
                                $school_found = false;
                                foreach ( $possible_receivers as $possible_receiver ){
                                    if ( $possible_receiver['type'] == 'school' && $possible_receiver['id'] == $location['id'] ){
                                        $school_found = true;
                                    }
                                }
                                if (!$school_found) {
                                    $user_has_send_rights = false;
                                }
                            }
                            break;
                        case 'school_vip':
                            if($user_info['type'] == 'STUDENT'){
                                $user_has_send_rights = false;
                            }else{
                                $school_found = false;
                                foreach ( $possible_receivers as $possible_receiver ){
                                    if ( $possible_receiver['type'] == 'school_vip' && $possible_receiver['id'] == $location['id'] ){
                                        $school_found = true;
                                    }
                                }
                                if (!$school_found) {
                                    $user_has_send_rights = false;
                                }
                            }
                            break;
                        case 'district':
                        case 'district_parents':
                            if($user_info['type'] == 'STUDENT'){
                                $user_has_send_rights = false;
                            }else{
                                $district_found = false;
                                foreach ( $possible_receivers as $possible_receiver ){
                                    if ( $possible_receiver['type'] == 'district' && $possible_receiver['id'] == $location['id'] ){
                                        $district_found = true;
                                    }
                                }
                                if (!$district_found) {
                                    $user_has_send_rights = false;
                                }
                            }
                            break;
                        case 'district_vip':
                            if($user_info['type'] == 'STUDENT'){
                                $user_has_send_rights = false;
                            }else{
                                $district_found = false;
                                foreach ( $possible_receivers as $possible_receiver ){
                                    if ( $possible_receiver['type'] == 'district_vip' && $possible_receiver['id'] == $location['id'] ){
                                        $district_found = true;
                                    }
                                }
                                if (!$district_found) {
                                    $user_has_send_rights = false;
                                }
                            }
                            break;
                        case 'edmodo':
                        case 'all-groups':
                        case 'connections':
                            if ($user_info['type'] != 'TEACHER' && $user_info['type'] != 'PUBLISHER' ){
                                $user_has_send_rights = false;
                            }
                            break;
                        case 'community':
                            $user_status = SubjectCommunities::getInstance()->getUserStatus($user_info, $location['id']);
                            if ( $user_status != ADMINS && $user_status != FOLLOWS_CAN_POST && $user_status != CAN_POST ){
                                $user_has_send_rights = false;
                            }
                            break;
                    }
                }
            }
        }
        else{
            // For a comment type message, we only check if the current sender can send to one of the comment's recipients
            $user_has_send_rights = false;
            $params = array('language' => 'en', 'account_info' => $user_info, 'exclude_publishers_from_results' => false );
            $possible_receivers = ShareboxHandler::getInstance()->getPossibleReceivers($params);
            // Right to send a message directly to each individual are checked

            foreach( $receivers['people'] as $person ){
                $receiver_info = Users::getInstance()->find($person);
                if ( $receiver_info['type'] == 'PUBLISHER' ){
                    $user_has_send_rights = true;
                    break;
                }
                foreach ( $possible_receivers as $possible_receiver ){
                    if ( $person == $user_info['user_id'] ){
                        $user_has_send_rights = true;
                        break;
                    }
                    if ( $possible_receiver['type'] == 'user' && $possible_receiver['id'] == $person ){
                        $user_has_send_rights = true;
                        break;
                    }
                }
            }

            // Right to send a message to other locations is checked
            $keep_checking = true;
            for( $i = 0; $i < count($receivers['locations']) && $keep_checking; $i++ ){
                $location = $receivers['locations'][$i];
                switch( $location['type'] ){
                    case 'group':
                    case 'group_parents':
                        if ( $user_info['type'] != 'PARENT' ){
                            foreach ( $possible_receivers as $possible_receiver ){
                                if ( $possible_receiver['type'] == 'group' && $possible_receiver['id'] == $location['id'] ){
                                    $user_has_send_rights = true;
                                    break;
                                }
                            }
                        }
                        elseif( $location['type'] == 'group_parents' ){
                            $parents_groups = ParentsStudents::getInstance()->getParentsGroups($user_info['user_id']);
                            foreach ( $parents_groups as $group ){
                                if ( $group['group_id'] == $location['id'] ){
                                    $user_has_send_rights = true;
                                    break;
                                }
                            }
                        }
                        break;
                    case 'school':
                    case 'school_vip':
                    case 'school_parents':
                        if($user_info['type'] != 'STUDENT'){
                            if ($user_info['type'] != 'PARENT'){
                                foreach ( $possible_receivers as $possible_receiver ){
                                    if ( ($possible_receiver['type'] == 'school' || $possible_receiver['type'] == 'school_vip') && $possible_receiver['id'] == $location['id'] ){
                                        $user_has_send_rights = true;
                                        break;
                                    }
                                }
                            }
                            else{
                                $students_info = ParentsHandler::getInstance()->getParentStudentsInfo($user_info);
                                if(count($students_info['student_ids'])){
                                    $students_info = ParentsHandler::getInstance()->getStudentsGroupsForParent($students_info);
                                }
                                if ( isset($students_info['students_schools_by_id'][$location['id']]) ){
                                    $user_has_send_rights = true;
                                    break;
                                }
                            }
                        }else{
                            $user_has_send_rights = false;
                            $keep_checking = false;
                        }
                        break;
                    case 'district':
                    case 'district_vip':
                    case 'district_parents':
                        if($user_info['type'] != 'STUDENT'){
                            if ( $user_info['type'] != 'PARENT' ){
                                foreach ( $possible_receivers as $possible_receiver ){
                                    if ( ($possible_receiver['type'] == 'district' || $possible_receiver['type'] == 'district_vip') && $possible_receiver['id'] == $location['id'] ){
                                        $user_has_send_rights = true;
                                    }
                                }
                            }
                            else {
                                $students_info = ParentsHandler::getInstance()->getParentStudentsInfo($user_info);
                                if(count($students_info['student_ids'])){
                                    $students_info = ParentsHandler::getInstance()->getStudentsGroupsForParent($students_info);
                                }
                                if ( isset($students_info['students_districts_by_id'][$location['id']]) ){
                                    $user_has_send_rights = true;
                                    break;
                                }
                            }
                        }else{
                            $user_has_send_rights = false;
                            $keep_checking = false;
                        }
                        break;
                    case 'all-groups':
                    case 'connections':
                    case 'community':
                        if ($user_info['type'] == 'TEACHER' || $user_info['type'] == 'PUBLISHER' ){
                            $is_verified_teacher = CoppaHandler::userIsCoppaVerified($user_info['user_id']);

                            if ($is_verified_teacher) {
                                $user_has_send_rights = true;
                            }

                            else if ($location['id'] == SUPPORT_COMMUNITY_ID) {
                                $original_message = Messages::getInstance()->find($message_data['comment_to'])->current()->toArray();
                                if ($original_message['creator_id'] == $user_info['user_id']) {
                                    $user_has_send_rights = true;
                                } else {
                                    $user_has_send_rights = false;
                                    $keep_checking = false;
                                }
                            }

                            else if($user_info['type'] == 'PUBLISHER' && $location['type'] === 'connections'){

                                $user_has_send_rights = true;
                                $keep_checking = false;

                            } else {
                                $user_has_send_rights = false;
                                $keep_checking = false;
                            }

                        }elseif($user_info['type'] == 'ADMINISTRATOR' && $location['id'] == ADMIN_SUPPORT_COMMUNITY_ID )
                        {
                            $user_has_send_rights = true;
                            $keep_checking = false;
                        }
                        else{
                            $user_has_send_rights = false;
                            $keep_checking = false;
                        }
                        break;
                }
            }
        }
        return $user_has_send_rights;
    }

    /**
     * Returns a group title with the names of the students appended to it
     * @param string $group_title the title of the group
     * @param string $language the viewing user's language
     * @param array $student_ids the ids of the students
     * @return string the modified group title
     */
    public function addChildrensNames($group_title, $student_ids){
        //Students' full info should already be cached
        $students = Users::getInstance()->find($student_ids);
        $group_title .= ' (';
        $students_count = count($students);
        for($i = 0; $i < $students_count; $i++){
            $group_title .= $students[$i]['first_name'];
            if($i < $students_count - 1){
                $group_title .= ', ';
            }
        }
        $group_title .= ')';
        return $group_title;
    }

    public static function formatReceivers($receivers, $language){
        $users_db = Users::getInstance();
        $account_info = AccountHandler::getInstance()->getAccountInfo();
        $user_id = $account_info['user_id'];
        $user_groups = GroupsHandler::getInstance()->getUsersGroupsInfo($user_id);
        $messaging_translator = TranslationHelper::getInstance(PATH2_LANGUAGES . 'recipients.tmx', $language);

        foreach($receivers as $receiver){
            $receiver_info = array('type' => $receiver['posted_in'], 'id' => $receiver['posted_in_id'], 'receiver_name' => $receiver['posted_in'], 'receiver_color' => '#444444');
            switch ($receiver['posted_in']) {
                case 'group':
                    $group = array();
                    if(isset($user_groups['groups'][$receiver['posted_in_id']])){
                        $group = $user_groups['groups'][$receiver['posted_in_id']];
                    }
                    elseif(in_array($receiver['posted_in_id'],$user_groups['archived_group_ids'])){
                        $group = Groups::getInstance()->getGroup($receiver['posted_in_id'],false,false,$user_id);
                    }

                    if( !empty($group) ){
                        $receiver_info['receiver_name']  = $group['title'];
                        $receiver_info['receiver_color'] = $group['hex'];
                        $all_receivers[] = $receiver_info;
                    }
                    break;
                case 'user':
                    $user = $users_db->getUserInfo($receiver['posted_in_id'],true);
                    $receiver_info['receiver_name'] = self::formatName($user,$language);
                    $all_receivers[] = $receiver_info;
                    break;
                case 'group_parents':
                    $group = array();
                    if(isset($user_groups['groups'][$receiver['posted_in_id']])){
                        $group = $user_groups['groups'][$receiver['posted_in_id']];
                    }
                    elseif(in_array($receiver['posted_in_id'],$user_groups['archived_group_ids'])){
                        $group = Groups::getInstance()->getGroup($receiver['posted_in_id'],false,false,$user_id);
                    }

                    if( !empty($group) ){
                        $receiver_info['receiver_name']  = $messaging_translator->_('inst-parents', $group['title']);
                        $receiver_info['receiver_color'] = $group['hex'];
                        $all_receivers[] = $receiver_info;
                    }
                    break;
                case 'school':
                    if(isset($account_info['school_id']) && $account_info['school_id'] == $receiver['posted_in_id']){
                        $school_name_tmp = $account_info['school_name'];
                    }
                    else{
                        $school = Schools::getInstance()->getSchoolById($receiver['posted_in_id']);
                        $school_name_tmp = $school['school_name'];
                    }
                    $receiver_info['receiver_name'] = $messaging_translator->_('inst-all', $school_name_tmp);
                    $receiver_info['type']          = 'group';
                    $all_receivers[] = $receiver_info; //array('type'=>'group','name'=>$school_name,'hex'=> EDMODO_COLOR);
                    break;
                case 'school_vip':
                    if(isset($account_info['school_id']) && $account_info['school_id'] == $receiver['posted_in_id']){
                        $school_name_tmp = $account_info['school_name'];
                    }
                    else{
                        $school = Schools::getInstance()->getSchoolById($receiver['posted_in_id']);
                        $school_name_tmp = $school['school_name'];
                    }
                    $receiver_info['receiver_name'] = $messaging_translator->_('inst-vip', $school_name_tmp);
                    $receiver_info['type']          = 'group';
                    $all_receivers[] = $receiver_info;
                    break;
                case 'district':
                    if(isset($account_info['district_id']) && $account_info['district_id'] == $receiver['posted_in_id']){
                        $school_name_tmp = $account_info['district_name'];
                    }
                    else{
                        $school = Districts::getInstance()->getDistrictById($receiver['posted_in_id']);
                        $school_name_tmp = $school['name'];
                    }
                    $receiver_info['receiver_name'] = $messaging_translator->_('inst-all', $school_name_tmp);
                    $receiver_info['type']          = 'group';
                    $all_receivers[] = $receiver_info;
                    break;
                case 'district_vip':
                    if(isset($account_info['district_id']) && $account_info['district_id'] == $receiver['posted_in_id']){
                        $school_name_tmp = $account_info['district_name'];
                    }
                    else{
                        $school = Districts::getInstance()->getDistrictById($receiver['posted_in_id']);
                        $school_name_tmp = $school['name'];
                    }
                    $receiver_info['receiver_name'] = $messaging_translator->_('inst-vip', $school_name_tmp);
                    $receiver_info['type']          = 'group';
                    $all_receivers[] = $receiver_info;
                    break;
            }
        }
        return $all_receivers;
    }

    /**
     * determines if a message is considered "long"
     * @param string $message the message to check
     * @return boolean the message is long or not
     */
    public function isLongMessage( $message )
    {
        $result = false;

        if(strlen($message) > MAX_CHAR_LENGTH_FOR_MESSAGES || substr_count($message, HTML_BREAK_TAG) > MAX_LINE_BREAKS_FOR_MESSAGES)
        {
            $result = true;
        }

        return $result;
    }

    /**
     * Formats a long message returning the cutted version, looks for the ritght place
     * to cut, tries to avoid cutting anchors in the middle
     * @param string $message the message to format
     * @param boolean $add_dots if we need to add "..." in the end
     * @return string the cutted message
     */
    public function formatLongMessage( $message, $add_dots = true)
    {
        $cutted_message = '';
        if(substr_count($message, HTML_BREAK_TAG) > MAX_LINE_BREAKS_FOR_MESSAGES && strlen($message) < MAX_CHAR_LENGTH_FOR_MESSAGES)
        {
            $position = strposOffset(HTML_BREAK_TAG,$message,MAX_LINE_BREAKS_FOR_MESSAGES);
        }
        else
        {
            $position = MAX_CHAR_LENGTH_FOR_MESSAGES;
            $correct_space = false;
            $start_searching = (MAX_CHAR_LENGTH_FOR_MESSAGES - 8);
            while(!$correct_space)
            {
                $space_pos = 0;
                if($start_searching < strlen($message) )
                    $space_pos = strpos($message, " ", $start_searching);

                if(empty($space_pos))
                {
                    if(strlen($message > MAX_CHAR_LENGTH_FOR_MESSAGES + 2))
                        $position = MAX_CHAR_LENGTH_FOR_MESSAGES + 2;
                    else
                        $position = MAX_CHAR_LENGTH_FOR_MESSAGES - 3;
                    $correct_space = true;

                    if(substr($message,$position - 3,6) == HTML_BREAK_TAG)
                    {
                        $position = $position - 3;
                    }
                }
                else if(substr($message,$space_pos - 3,6) != HTML_BREAK_TAG)
                {
                    $position = $space_pos;
                    $correct_space = true;
                }
                else
                    $start_searching = $start_searching + 9;
            }

            if(substr_count($message, HTML_BREAK_TAG) > MAX_LINE_BREAKS_FOR_MESSAGES && strlen($message) > MAX_CHAR_LENGTH_FOR_MESSAGES)
            {
                $position_temp = strposOffset(HTML_BREAK_TAG,$message,MAX_LINE_BREAKS_FOR_MESSAGES);
                if($position > $position_temp)
                    $position = $position_temp;
            }

        }

        //preventing of cutting a link in the middle
        $is_link = strrpos($message,"<a");
        $is_link2 = strrpos($message,"/a>");

        if(!empty($is_link) && !empty($is_link2) &&  $is_link < $is_link2 /*&&   $is_link < $position*/)
        {
            //$position = $is_link;
            $all_positions = strallpos($message,"<a");

            foreach($all_positions as $link_pos)
            {
                $end_pos = strpos($message,"/a>",$link_pos);
                if($link_pos < $position && $position < $end_pos)
                {
                    $position = $link_pos;
                    break;
                }
            }
        }

        $cutted_message = substr($message, 0, $position);

        if($add_dots)
            $cutted_message .= "...";

        return $cutted_message ;
    }

    /**
     * Determines if a user has rights to SEE a message
     * @param array $viewer_info the information of the viewer
     * @param array $message_data the information from the message
     * @param array $creator_info the informatation of the message creator
     * @return boolean has rights or not
     */
    public function userHasRightsToReadMessage($viewer_info,$message_data,$creator_info)
    {
        $has_rights = false;
        //$receiver_id =  $message_data['posted_in_id'];
        $user_id = $viewer_info['user_id'];
        $user_type = $viewer_info['type'];

        if($message_data['public'] == 1 || (!empty($viewer_info) && ($viewer_info['type'] == 'SUPER_USER' || $viewer_info['user_id'] == EDMODO_SYSTEM_USER_ID )))
        {
            $has_rights = true;
        }
        else if(isset($viewer_info['user_id']) && $viewer_info['user_id'] == $message_data['creator_id'])
        {
            $has_rights = true;
        }
        else
        {
            //hack for when a message brings no receivers but it does bring posted_in
            if(count($message_data['receivers']) == 0 && !empty($message_data['posted_in']) && !empty($message_data['posted_in_id']))
            {
                $message_data['receivers'][] = array ('type' => $message_data['posted_in'], 'id' => $message_data['posted_in_id']);
            }

            foreach($message_data['receivers'] as $receiver)
            {
                $message_receiver_type = $receiver['type'];
                $message_receiver_id = (isset($receiver['id']) ? $receiver['id'] : 0) ;

                if($message_receiver_type == 'community')
                {
                    //edit rights!!
                    $has_rights = true;
                    break;
                }
                else if ($message_receiver_type == 'user')
                {
                    $receiver_info = Users::getInstance()->getUserInfo($message_receiver_id,true);
                    if($receiver_info['type'] == 'PUBLISHER')
                    {
                        $has_rights = true;
                        break;
                    }
                }
                else if ($message_receiver_type == 'connections')
                {
                    if($creator_info['type'] == 'PUBLISHER')
                    {
                        $has_rights = true;
                        break;
                    }
                }

                if(!$has_rights && !empty($viewer_info))
                {
                    //$receiver_id =  $message_data['posted_in_id'];

                    switch($message_receiver_type){
                        case 'user':
                            if($message_receiver_id == $user_id)
                            {
                                $has_rights = true;
                            }
                            break;
                        case 'group':
                            $groups = Groups::getInstance()->getUserGroupsIds($user_id,false); //GroupsEditorHelper::getInstance()->getGroupsInfo($user_id);
                            if(in_array($message_receiver_id,$groups)) //array_keys($groups)
                            {
                                $has_rights = true;
                            } else if ($viewer_info['type'] == 'PARENT') {
                                $students_info = ParentsStudents::getInstance()->getParentStudents($viewer_info['user_id']);
                                foreach ($students_info as $si) {
                                    $su = array('user_id' => $si['student_id'], 'type' => "STUDENT");
                                    if ($this->userHasRightsToReadMessage($su,$message_data,$creator_info)) {
                                        $has_rights = true;
                                        break;
                                    }
                                }
                            }
                            break;
                        case 'group_parents':
                            if($user_type == 'PARENT')
                            {
                                $students_info = ParentsHandler::getInstance()->getParentStudentsInfo($viewer_info);
                                if(count($students_info['student_ids']))
                                {
                                    $groups = ParentsHandler::getInstance()->getStudentsGroupsForParent($students_info);
                                    if( in_array($message_receiver_id,$groups['students_group_ids']))
                                    {
                                        $has_rights = true;
                                    }
                                }
                            }
                            break;
                        case 'school':
                            if(isset($viewer_info['school_id']) && $viewer_info['school_id'] == $message_receiver_id)
                            {
                                $has_rights = true;
                            }
                            if(isset($viewer_info['schools']) && in_array($message_receiver_id,array_keys($viewer_info['schools'])))
                            {
                                $has_rights = true;
                            }
                            break;
                        case 'school_vip':
                            if(($user_type == 'TEACHER' || $user_type == 'ADMINISTRATOR') && isset($viewer_info['school_id']) && $viewer_info['school_id'] == $message_receiver_id)
                            {
                                $has_rights = true;
                            }
                            break;
                        case 'school_parents':
                            if($user_type == 'PARENT')
                            {
                                $students_info = ParentsHandler::getInstance()->getParentStudentsInfo($viewer_info);
                                if(count($students_info['student_ids']))
                                {
                                    $groups = ParentsHandler::getInstance()->getStudentsGroupsForParent($students_info);
                                    if( in_array($message_receiver_id,$groups['students_school_ids']))
                                    {
                                        $has_rights = true;
                                    }
                                }
                            }
                            break;
                        case 'district':
                            if(isset($viewer_info['district_id']) && $viewer_info['district_id'] == $message_receiver_id)
                            {
                                $has_rights = true;
                            }
                            if(isset($viewer_info['districts']) && in_array($message_receiver_id,array_keys($viewer_info['districts'])))
                            {
                                $has_rights = true;
                            }
                            break;
                        case 'district_vip':
                            if(($user_type == 'TEACHER' || $user_type == 'ADMINISTRATOR') && isset($viewer_info['district_id']) && $viewer_info['district_id'] == $message_receiver_id)
                            {
                                $has_rights = true;
                            }
                            break;
                        case 'district_parents':
                            if($user_type == 'PARENT')
                            {
                                $students_info = ParentsHandler::getInstance()->getParentStudentsInfo($viewer_info);

                                if(count($students_info['student_ids']))
                                {
                                    $groups = ParentsHandler::getInstance()->getStudentsGroupsForParent($students_info);

                                    if( in_array($message_receiver_id,$groups['students_district_ids']))
                                    {
                                        $has_rights = true;
                                    }
                                }
                            }
                            break;
                        case 'connections':
                            $connections = ConnectionsHandler::getInstance()->getConnections($user_id);
                            foreach($connections as $connection)
                            {
                                if($connection['user_id'] == $message_data['creator_id'])
                                {
                                    $has_rights = true;
                                    break;
                                }
                            }
                            break;
                        case 'community':
                            $has_rights = true;
                            break;
                    }

                    if($has_rights == true)
                    {
                        //break foreach
                        break;
                    }
                }//closes switch
            }//close foreach
        }//close else

        return $has_rights;
    }

    private function preProcessPossibleGdocs(&$message_data,$attached_resources)
    {
        if(isset($message_data['original_message_id']) && !empty($message_data['original_message_id']))
        {
            $google_permisions = array();
            if (!empty($attached_resources))
            {
            foreach($attached_resources as $resource)
            {
                $item = LibraryItems::getInstance()->getLibraryItem($resource);
                if($item['library_item_type'] == 'LINK')
                {
                    $google_info = GdocsMessagesPermissions::getInstance()->getPermissions($item['library_item_resource_id'],$message_data['original_message_id']);

                    if(!empty($google_info))
                    {
                        $extra_info = GdocsLinks::getInstance()->getGoogleDoc($google_info['link_id']);
                        $google_permisions[] = array_merge($google_info, $extra_info);
                    }
                }
            }
            }

            if(count($google_permisions) > 0)
            {
                $role = 'reader';
                $scope = 'user';

                foreach($google_permisions as $godc)
                {
                    if($godc['role'] != 'reader')
                        $role = $godc['role'];
                    if($godc['scope'] != 'user')
                        $scope = $godc['scope'];

                    $google_docs[] = $godc;
                }

                $message_data['google_docs']['role']      = $role;
                $message_data['google_docs']['scope']     = $scope;
                $message_data['google_docs']['docs_info'] = $google_docs;
            }
        }
    }

    /**
     * Check if a user can read a particular message.
     *
     * This is a wrapper for the userHasRightsToReadMessage() function, by accepting the $account_info for the user to check and a message_id.
     *
     * @param array $account_info, the account info for the user to check
     * @param string $language
     * @param int $message_id
     * @return array|bool
     */
    public function userCanReadMessage($account_info, $language, $message_id) {
        // get the original message
        $msg_info = MessagingHelper::getInstance()->
            checkMessages($language, $account_info, array('message_id' => $message_id, 'include_special_groups' => true));

        if (isset($msg_info[0])) {
            $msg_data = $msg_info[0];

            $sender_info = array(
                'user_id'    => $msg_data['creator_id'],
                'type'       => $msg_data['sender_type'],
                'title'      => $msg_data['sender_title'],
                'first_name' => $msg_data['sender_first_name'],
                'last_name'  => $msg_data['sender_last_name']
            );

            // make sure that this user can read this assignment message
            if (MessagingHelper::getInstance()->userHasRightsToReadMessage($account_info, $msg_data, $sender_info)) {
                return $msg_data;
            }
        }

        return false;
    }
   
    /**
    * Checks if the post is being shared and is a snapshot post from the input params
    * Because there is no difference in the params sent for sharing snapshot posts, nor does our system require any difference
    * we need to run a series of checks to determine if it is indeed a snapshot post
    *
    * @param array $params - an array of values being sent for the message
    * @returns bool
    */

    public function isSharingSnapshotPost($params){

        $result = false;

        //if we're sharing a post we need to make sure it's not a snapshot post
        if(isset($params['share']) && isset($params['new_links'])){

            if(is_string($params['new_links'])){
                try{
                    $links = Zend_Json::decode($params['new_links']);
                }catch(Exception $e){}
            }else{
                $params['links'];
            }

            //if it's not an array don't do anything
            if(is_array($links)){
                
                $links = $links[0];

                if(isset($links['url']) && is_string($links['url'])){
                        
                    $parts      = explode('/', $links['url']);
                    $message_id = 0;                         

                    //any valid shared url would need to take the format /posts/:message_id
                    for($i=0; $i<count($parts); $i++){

                        if($parts[$i] === 'post'){
                            $message_id = isset($parts[$i + 1]) ? $parts[$i + 1] : 0;
                            break;
                        }

                    }

                    if(is_numeric($message_id) && $message_id){
                                
                        $message = Messages::getInstance()->getMessageInfo($message_id);
                        if($message['type'] === 'app_message') $result = true;

                    }

                }
            
            }

        }
        
        return $result;

    }
 
    /**
     * Helper to prepare various receivers (a work in progress) for message sending.
     * Constructs a receivers array that is compatible with MessagingHelper
     * 
     * @param array/int $receiver_user_ids
     * @param array/int $receiver_group_ids
     * @param array/int $receiver_parent_group_ids
     * @return array
     */
    public function prepareReceivers($receiver_user_ids = array(), $receiver_group_ids = array(), $receiver_parent_group_ids = array()) {
        // prepare receivers
        $receivers = array('locations' => array(), 'people' => array());

        if ($receiver_user_ids) {
            $receivers['people'] = $receiver_user_ids;
        }

        if ($receiver_group_ids) {
            foreach ($receiver_group_ids as $group_id) {
                $receivers['locations'][] = array(
                    'type' => 'group',
                    'id' => $group_id,
                );
            }
        }

        if ($receiver_parent_group_ids) {
            foreach ($receiver_parent_group_ids as $parent_group_id) {
                $receivers['locations'][] = array(
                    'type' => 'group_parents',
                    'id' => $parent_group_id,
                );
            }
        }
        
        return $receivers;
    }
}


class NotificationTestJob
{
    public $data;

    public function workload()
    {
        return $this->data;
    }

    public function handle()
    {
        return __CLASS__;
        <tu tuid="get-started-alt">
    <tuv xml:lang="en"><seg><![CDATA[Welcome! %1$s %1$s Now you can connect to all your classes, students, and colleagues—all in one place. %1$s %1$s Setting up your account is simple. Just complete our %2$sGet Started Checklist%3$s and voila! You’re set for anytime, anywhere learning. %1$s %1$s Now if only grading all that homework was this easy!%1$s !%1$s Sincerely, %1$s The Edmodo Team]]></seg></tuv>
    <tuv xml:lang="fr"><seg><![CDATA[Bienvenue ! %1$s %1$s Vous pouvez maintenant vous connecter à toutes vos classes, tous vos étudiants et collègues—en un seul endroit.%1$s %1$s La configuration de votre compte est simple. Terminez simplement notre %2$s liste de contrôle de démarrage %3$s et voilà ! Vous êtes paré pour un apprentissage à tout moment, partout.%1$s %1$s Si seulement la notation de tous ces devoirs était aussi facile que ça !%1$s Cordialement, %1$s l'Équipe Edmodo,]]></seg></tuv>
    <tuv xml:lang="es"><seg><![CDATA[¡Bienvenido! %1$s %1$s Ahora puedes conectarte a todas tus clases, alumnos y colegas... todos en un solo lugar.%1$s %1$s Configurar tu cuenta es simple. Solo completa nuestra %2$sLista para comenzar%3$s y ¡listo! Estás listo para comenzar a aprender en todo momento y lugar.%1$s¡Si solo corregir fuera así de fácil!%1$s %1$s Saludos, %1$sEl equipo Edmodo]]></seg></tuv>
  </tu>
    }

    public function __construct($data)
    {
        $this->data = $data;
    }
}
