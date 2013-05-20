def hotel_cost(nights):
    x=nights *140
    return x
def plane_ride_cost(city):
    if city == "Charlotte":
        cost=183
        return cost
    elif city == "Tampa":
        cost=220
        return cost
    elif city == "Pittsburgh":
        cost = 222
        return cost
    else:
        cost =475
        return cost
def rental_car_cost(days):
    if days < 3:
        cost= 40 * days
        return cost
    elif days >2 and days < 7:
        cost= (40 * days) -20
        return cost
    else:
        cost=40 * days -50
        return cost
def trip_cost(city,days,spending_money):
    return hotel_cost(days) + plane_ride_cost(city) +rental_car_cost(days)+spending_money

print trip_cost("Los Angeles",5,600)
