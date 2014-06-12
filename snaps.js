function formatResult(data) {
        var statement_tag = '',
            long_statement_tag = '',
            statement_code = data.statement_code_short;
        console.log(data);
        /*if (data.children.length < 1 ){
            long_statement_tag = '<div class="statement">No Standards Found</div>';

        }
        else{*/
           /* if (data.statement_code_short !== undefined){

                statement_tag = '<div class="statement">' + statement_code + '</div>';
            }*/
        if (data.title !== undefined){
            var render = '<div class="statement">' + data.title + '</div>';
        }

            if (data.statement_html != undefined){
                long_statement_tag = '<div class="long-statement"> ' + data.statement_html + '</div>';
                var render = '<div class="standard-selections">'+ statement_tag + long_statement_tag + '</div>';

            }
        //}
        return render
    };
