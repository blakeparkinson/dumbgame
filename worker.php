                        switch ($test_subject_line){
                            case 'sub1':
                                if ($messageData['type'] == 'alert'){
                                    //default alert is simply the set string + 'alert'
                                    $subject = '[Alert] ' . $contents['content'];
                                    //alert for through group with parent recipient
                                    if (!empty($contents['group_name']) && $recipientInfo['type'] == 'PARENT'){
                                        $student_info = ParentsStudents::getInstance()->getStudentsInfoByParentId($recipientInfo['user_id']);
                                        if (!empty($student_info['first_name'])){
                                            $subject = '[' . $student_info['first_name'] .' - Alert to ' . $contents['group_name'] . '] ' . $message_string;
                                        }
                                    }
                                    if (strlen($subject) > 84){
                                        $spliced_subject = substr($subject['content'], 0, 82);
                                        $subject = $spliced_subject . '...';
                                    }

                                    //alert through group with non-parent recipient
                                    elseif (!empty($contents['group_name']) && $recipientInfo['type'] != 'PARENT'){
                                        $subject = '[Alert to ' . $contents['group_name'] . '] ' . $message_string;
                                    }

                                }
                                else{
                                    $subject = $contents['subject'];
                                }
                                break;

                            case 'sub5':

                                if ($messageData['type'] == 'text'){
                                    //default message is simply the set string
                                    $subject = $contents['content'];
                                    //message for through group with parent recipient
                                    if (!empty($contents['group_name']) && $recipientInfo['type'] == 'PARENT'){
                                        $student_info = ParentsStudents::getInstance()->getStudentsInfoByParentId($recipientInfo['user_id']);
                                        if (!empty($student_info['first_name'])){
                                            $subject = '[' . $student_info['first_name'] .' - ' . $contents['group_name'] . '] ' . $message_string;
                                        }
                                    }
                                    //message through group with non-parent recipient
                                    elseif (!empty($contents['group_name']) && $recipientInfo['type'] != 'PARENT'){
                                        $subject = '[' . $contents['group_name'] . '] ' . $message_string;
                                    }
                                    if (strlen($subject) > 84){
                                        $spliced_subject = substr($subject['content'], 0, 82);
                                        $subject = $spliced_subject . '...';
                                    }

                                }
                                else{
                                    $subject = $contents['subject'];
                                }
                                break;

                            default:
                                $subject = $contents['subject'];
                        }
