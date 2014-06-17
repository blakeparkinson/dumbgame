            fb.find('.snapshot-time-limit option[value='+time_limit+']').attr('selected', 'selected')


var getTimeLimit = function(count){

        var default_seconds = 600,
            time_limit = default_seconds + (300 * (count -1));

        return time_limit
    }
