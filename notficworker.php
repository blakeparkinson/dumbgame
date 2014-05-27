$reply_to_address = $contents['sender_title'] . ' via Edmodo';

                $recipients = array (array ('email' => $recipientInfo['email'], 'username' => $recipientInfo ['username'] ) );

                $emailValidator = new Zend_Validate_EmailAddress();
                if ($emailValidator->isValid($recipientInfo['email'])) {
                    $mail_handler  = new MailHandler($body_text);
                    $tracking_data = TrackingHelper::makeTrackingData($template_name, $messageLink['type'], null, TrackingHelper::TYPE_SYSTEM, $recipientInfo['user_id'], TrackingHelper::TYPE_USER, $recipientInfo['email'], self::$msgID, TrackingHelper::TYPE_MESSAGE, null, $messageData[TrackingHelper::DATA_DATE_REQUESTED], $misc_data_tracking);
                    $response      = $mail_handler->setTrackingData($tracking_data)->sendEmailWrapper( $recipients, $contents['subject'], $values2insert, self::$replyTo, $reply_to_address, self::$replyTo, 'UTF-8',true );
