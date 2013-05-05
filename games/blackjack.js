var deck = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 10, 10, 10, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 10, 10, 10, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 10, 10, 10, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 10, 10, 10].sort(function(a, b) { return 0.5 - Math.random(); });

function deal(){
    var rankDeal = deck[Math.floor(Math.random()*52+1)];
    return rankDeal;
}

function dealHand(){
    var cardHolderDealer = [];
    var cardHolderPlayer = [];
    cardHolderDealer[0] = deal();
    cardHolderDealer[1] = deal();
    cardHolderPlayer[0] = deal();
    cardHolderPlayer[1] = deal();
    var playerIndex = 1;
    var dealerIndex = 1;
    var playerSum = cardHolderPlayer[0] + cardHolderPlayer[1];
    var dealerSum = cardHolderDealer[0] + cardHolderDealer[1];
    alert("dealer shows one card: " + cardHolderDealer[0]);
    console.log("you have been dealt a total of "+ playerSum + " " + "[" + cardHolderPlayer + "]");
    var total = 0;
    for (var i in cardHolderPlayer)
        total += cardHolderPlayer[i];
        function nextMove(){
            var hitOrStay = prompt('Hit or Stay');
                    function Hit(){  
                        playerIndex++;
                        cardHolderPlayer[playerIndex] = deal();
                        hitTotal = 0;
                        for(var i in cardHolderPlayer){
                            hitTotal += cardHolderPlayer[i];
                        }
                        if (hitTotal === 21){
                            return console.log(hitTotal + ' you got blackjack');
                        }
                        else if (hitTotal > 21){
                            return console.log(hitTotal + ' you busted');
                        }
                        else{
                        console.log("After hitting, your new hand total is " + hitTotal + " " + "[" + cardHolderPlayer + "]");
                        return nextMove();
                        }
                      }
                    function dealerHit(){  
                        dealerIndex++;
                        cardHolderDealer[dealerIndex] = deal();
                        dealerHitTotal = 0;
                        for(var i in cardHolderDealer){
                            dealerHitTotal += cardHolderDealer[i];
                        }
                        if (dealerHitTotal === 21){
                            return console.log(dealerHitTotal + " " + "[" + cardHolderDealer + "]" + ' dealer has blackjack. You lose');
                        }
                        else if (dealerHitTotal > 21){
                            return console.log(dealerHitTotal + " " + "[" + cardHolderDealer + "]" + ' dealer busted. You Win');
                        }
                        else if (dealerHitTotal > 16){
                            if(dealerHitTotal > hitTotal){
                                return console.log('dealer has ' + dealerHitTotal + ' You lose');
                            }
                            else if(dealerHitTotal === hitTotal){
                                return console.log('you push');
                            }
                            else{
                                return console.log('dealer has ' + dealerHitTotal + ' You Win');
                            }
                
                        }
                        else{
                            console.log("dealer hits and his new hand is " + dealerHitTotal + " " + "[" + cardHolderDealer + "]");
                            return dealerHit();                            
                        }
                      }                      
            if (total > 21){
                console.log("you busted");
            }
            else if (hitOrStay === "Hit" || hitOrStay === "hit"){
                Hit();
            }
            else{
                console.log("you have chosen to stay");
                console.log("dealer shows "+ dealerSum + " " + "[" + cardHolderDealer + "]");
                return dealerHit();
            }
        }
    if (playerSum === 21){
        return console.log(playerSum + "[" + cardHolderPlayer + "]" + ' you got blackjack');
    }
    else {
         return nextMove();
 
    }
}
    

dealHand();
