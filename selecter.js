rootNode.one('click', '.snapshot-assign', getValue);



    }

    var getValue = function(){
        var y =_$("#grades option:selected").attr('value');
        console.log(y);
    }
