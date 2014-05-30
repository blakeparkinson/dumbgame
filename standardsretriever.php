public function ajaxFilterStandardsAction(){
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();
        $q = $this->_getParam('q');
        $url = 'https://blake-west.edmobranch.com/js_v2/libraries/standards.json';
        $standards = file_get_contents($url);
        $standards = json_decode($standards);
        $standards_to_return = array();
        if (empty($q)){
            foreach ($standards as $standard){
                if (count($standards_to_return) > 3){
                    break;
                }
                if ($standard->id > 10){
                    $standards_to_return[] = $standard;
                }
            }
        }
        else{
            foreach ($standards as $standard){
                if (strpos($standard->subcategory, $q) !==false){
                    $standards_to_return[] = $standard;
                }
            }
        }

        echo Zend_Json::encode($standards_to_return);


    }
