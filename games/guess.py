

import random
def generatesecretcode():  #creates seceret code
	secretcode=[]
	i=0
	while i<4:
		number=random.randint(0,5)
		secretcode.append(validcolors[number])
		i=i+1
	return secretcode	


def getguess():		#user guess
	raw_input("press *enter and enter your colors")
	i=0
	guess=[]
	while i<4:
		color = raw_input()
		guess.append(color)
		i=i+1
	return guess

def computerguess(player):	#guesses the code; it doesn't work however
	i=0
	correctguess=[]
	count=0
	
	
	while i<4:
		color=0
		number=random.randint(0,5)
		while color<6:
			guess=validcolors[number]
			guess[i]==validcolors[color]
			print 'guess',guess
			black=computeExactMatches(secretcode,guess)
			if black==1:
				correctguess[i]=validcolors[i]
				break
			color=color+1
		i=i+1
	return correctguess

def computeExactMatches(secretcode,guess):	#deciphers exact matches
	black=0
	sccopy=secretcode[:]
	i=0
	while i<4:
		if guess[i]==sccopy[i]:
			black=black+1
			sccopy[i]='AA'
			guess[i]='BB'
		i=i+1
	return black
	

def computePartialMatches(secretcode,guess):	#deciphers partial matches
	i=0
	white=0
	sccopy=secretcode[:]
	while i<4:
		j=0
		while j<4:
			if guess[i]==sccopy[j]:
				white=white+1
				sccopy[i]='AA'
				guess[i]='BB'
			j=j+1
		i=i+1
	return white
		


#main program

validcolors=["red","green","blue","yellow","purple","orange"]
secretcode=generatesecretcode()

black=0
numGuess=1
while black!=4:
	AI=[]
	correctguess=computerguess(AI)
	print "secret:",secretcode
	guess=getguess()
	black=computeExactMatches(secretcode,guess)
	white=computePartialMatches(secretcode,guess)
	print black, "blacks"
	print white, 'whites'
	if black is 4:
		print 'Congratulations buddy, you needed',numGuess,'guesses'
	numGuess=numGuess+1
