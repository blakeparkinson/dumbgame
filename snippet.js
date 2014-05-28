$.getJSON("js_v2/libraries/standards.json", function(data) {
        for (var key in data){
            if (data.hasOwnProperty(key)){
                if (data[key].subcategory.indexOf(string) > -1){
                    match.push(data[key]);
                }
            }
               
        }
        console.log(match);


    });
