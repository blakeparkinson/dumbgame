pyg = 'ay'


original = raw_input('Enter a word:')

if len(original) > 0 and original.isalpha():
    word=original.lower()
    first=word[0]
    vowels= ["a","e","i","o","u"]
    if first in vowels:
        new_word= word + pyg
        print new_word
    else:
        new_word=word[1:]+first+pyg
        print new_word
        
else:
    print 'empty'
