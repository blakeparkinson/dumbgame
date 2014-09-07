<?php

define('CONSOLE', 1); //constant indicates its running from console to index.php
require_once (dirname(__FILE__) . '/../../public/index.php');

$user_ids = array();
foreach ($argv as $arg) {
    if ((int) $arg !== 0) {
        $user_ids[] = (int) $arg;
    }
}

echo "Starting restore process at: " . date("Y-m-d H:i:s") . "\n";

$restored_count = 0;
foreach($user_ids as $user_id) {
    try {
        $user_info = DelUsers::getInstance()->fetchDeletedUserById($user_id);
        AccountHandler::getInstance()->loaded_account_info = $user_info;

        $result_obj = AccountHandler::getInstance()->restoreUser($user_id, $user_info);

        if($result_obj->isSuccessful()) {
            $restored_count++;
            echo "Restored UID: $user_id\n";
        } else {
            echo "Failed to restore UID: $user_id\n";
            echo "\n".implode($result_obj->getErrors());
            echo "\n";
        }
    } catch(exception $e) {
        echo "Exception when restoring UID: $user_id, " . $e->getMessage() . "\n";
    }
}

echo "Finished restore process at: " . date("Y-m-d H:i:s") . ", users restored: $restored_count/" . count($user_ids) . "\n";
