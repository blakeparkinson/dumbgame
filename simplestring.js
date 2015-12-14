function SimpleSymbols(str) { 
  
  var alp  = 'abcdefghijklmnopqrstuvwxyz';
  //var continue = true;
  for (var i = 0; i < str.length; i ++){
    if (alp.indexOf(str.charAt(i).toLowerCase()) > -1){
      	if (i == 0){
          return false;
        }
        if (str.charAt(i-1) != '+'){
          return false;
        }
      if (str.charAt(i+1) != '+'){
          return false;
        }
    }
  }

  // code goes here  
  return true; 
         
}
   
// keep this function call here 
// to see how to enter arguments in JavaScript scroll down
SimpleSymbols(readline());           
