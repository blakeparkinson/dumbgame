    private function _getLighthouseRecommendations($account_info, $limit = 20) {

        // 1) check if valid user
        if (empty($account_info)) {
            return null;
        }
        try{
            // 2) Users with a high SED score won't get these suggestions
            $user_scores = EdmodoScores::getInstance()->getScoresByIds(array($account_info));
            $user_sed_score = $user_scores[$account_info['user_id']]['edmodo_score'];
        }
        catch ( Exception $e ){
                // general exception
                error_log(__CLASS__ . '::' . __FUNCTION__ . '() !WARNING: Problem attempting to get user_score for user_id: ' .$account_info['user_id'].' : '.$e->getMessage());
                return array();
        }

        if (empty($user_sed_score)) {
            $user_sed_score = 0;
        }
        if ($user_sed_score > 900) {

            ActionsTracking::getInstance()->insert(array(
                ActionsTrackingConstants::USER_AGENT => getenv('HTTP_USER_AGENT'),
                ActionsTrackingConstants::EVENT_TYPE => 'nag-lht',
                ActionsTrackingConstants::EVENT_NAME => 'no reco: hi sed score'
            ));
            return null;
        }

        // 3) check to see if the has dismissed the Recommendations Nagbar
        //   if so, we wait 14 days ....
        $user_events = (array) json_decode(UsersProperties::get($account_info['user_id'], UsersProperties::USER_EVENTS));
        if (!empty($user_events[NagBarHandler::LIGHTHOUSE_SUGGESTIONS])) {

            $last_closed_recommendations_bar = (int) $user_events[self::LIGHTHOUSE_SUGGESTIONS];
            if ($last_closed_recommendations_bar) {
                $max_time_elapsed = 60 * 60 * 24 * 14; // 2 weeks
                $now = time();
                if (($now - $last_closed_recommendations_bar) < $max_time_elapsed) {
                    ActionsTracking::getInstance()->insert(array(
                        ActionsTrackingConstants::USER_AGENT => getenv('HTTP_USER_AGENT'),
                        ActionsTrackingConstants::EVENT_TYPE => 'nag-lht',
                        ActionsTrackingConstants::EVENT_NAME => 'no reco: in 14 day window'
                    ));
                    return null;
                }
            }
        }

        $title          = 'Connect with these teachers from your school to get tips on how to use Edmodo with your classroom!';

        $reccomended_suggestions = ConnectionsHandler::getInstance()->getRecommendedUsers($account_info, $limit);
        error_log(' $reccomended_suggestions: ' . print_r($reccomended_suggestions, true));



    }
