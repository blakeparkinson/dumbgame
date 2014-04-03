 		$should_see_join_url = false;
        if ($account_info['type'] == 'TEACHER' && (AbHandler::idInTest($user_id, 'GroupJoinTab') != AbHandler::CONTROL)){
            $ten_offer = OffersHandler::getInstance()->eligiblePlusTenOffer($account_info);
            if(!empty($ten_offer['show_hint']) && $ten_offer['show_hint'] == 'NO_HINT'){
                $should_see_join_url = true;
            }

        }
