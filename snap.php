$app_launch_request_obj = AppLaunchRequests::getInstance()->createLaunchRequest($user_id, 1118);
                error_log(' alrob: ' . print_r($app_launch_request_obj, true));

                $snapshot_url = 'https://snapshot.edmodoqabranch.com/oauth/token';
                $curl = curl_init($snapshot_url);
                $curl_post_data = array(
                    'client_id'   => '98d1383ba16673040578d67fe5a495c067b948f19089236228f95d2576b323b3',
                    'ln'          => $this->view->language,
                    'grant_type'  => 'launch_key',
                    'launch_key'  => $app_launch_request_obj->launch_key,
                );
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $curl_post_data);
                $curl_response = curl_exec($curl);
                $curl_response = json_decode($curl_response);

                $standards = 'https://snapshot.edmodoqabranch.com/standards.json?access_token='.$curl_response->access_token;
                error_log(' stan: ' . print_r($standards), true);

                $curl = curl_init($standards);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $curl_response2 = curl_exec($curl);
                error_log(' response2: ' . print_r(json_decode($curl_response2), true));
