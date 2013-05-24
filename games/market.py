groceries = ["banana", "orange", "apple"]

stock = { "banana": 6,
    "apple": 0,
    "orange": 32,
    "pear": 15
}
    
prices = { "banana": 4,
    "apple": 2,
    "orange": 1.5,
    "pear": 3
}

# Write your code below!
def compute_bill(food):
    total = 0
    for fruit in food:
        if stock[fruit] >0:
            total = total + prices[fruit]
            stock[fruit] = stock[fruit]-1
    return total
