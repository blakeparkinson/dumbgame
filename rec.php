<?php
/**
 * User: blake
 * Date: 11/17/14
 * Time: 2:44 PM
 */

class SnapshotRecommendationsHandler
{
    /**
     * Implements the "singleton" pattern for this class
     * @return LinkHandler
     */
    static function getInstance(){
        static $instance;
        if( !isset($instance)){
            $instance = new self();
        }
        return $instance;
    }

    public function processSnapshotRecommendations($all_messages, $message_ids){

        $recommendations = SnapshotRecommendations::getInstance()->getSnapshotRecommendations(array_keys($message_ids));
        foreach ($recommendations as $recommendation){
            $msg_id = $recommendation['message_id'];

            // Get the message array index in the "all_messages" array
            $i = $message_ids[$msg_id];

            $all_messages[$i]['snapshot_recommendations']['due_date'] = $recommendation['due_date'];

        }


        return $all_messages;


    }



}
