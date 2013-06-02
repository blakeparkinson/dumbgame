__author__ = 'blakeparkinson'
import random

def main():
    print '>> New game started.\n>> Good luck!\n'
    answer = generateAnswer()
    while True:
        userGuess = getUserGuess()
        if userGuess == answer:
            print '>> Congratulations, you won!'
            return
        print '>> The answer you provided is incorrect.\n>> Perhaps this hint will help you: '
        giveFeedback(answer, userGuess)

def generateAnswer():
    digits = [str(x) for x in range(10)]
    answer = ''
    for i in range(4):
        digit = random.sample(digits, 1)[0]
        digits.remove(digit)
        answer += digit
    return answer

def getUserGuess():
    while True:
        guess = raw_input('>> Please enter a 4-digit number: ').strip()
        if len(guess) != 4:
            continue
        guessIsValid = True
        for x in guess:
            if guess.count(x) != 1 or ord(x) not in range(48, 58):
                guessIsValid = False
                break
        if guessIsValid:
            return guess

def giveFeedback(answer, guess):
    for i in range(4):
        if guess[i] == answer[i]:
            print 'X',
            continue
        if guess[i] in answer:
            print 'O',
            continue
        print '-',
    print '\n'

if __name__ == '__main__':
    try:
        main()
    except Exception, e:
        print '>> Fatal error: %s' % e
