$.getJSON("js_v2/libraries/standards.json", function(data) {
        for (var key in data){
            if (data.hasOwnProperty(key)){
                if (data[key].subcategory.indexOf(string) > -1){
                    match.push(data[key]);
                }
            }
               /* for (var i = 0; i < data.length;){
                    console.log(data[i]);
                }*/
        }
        console.log(match);


        // data is a JavaScript object now. Handle it as such

    });
