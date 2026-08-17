<?php
namespace Plugins\Whatsapp\AI;

enum MarvinTool: string
{
    case TrackOrder      = 'track_order';
    case GetUsualForUser = 'get_usual_for_user';
    case GreetWithUsual         = 'greet_with_usual';
    case FilterProducts         = 'filter_products';
    case AddToOrder      = 'add_to_order';
    case RemoveFromOrder = 'remove_from_order';
    case CheckoutOrder   = 'checkout_order';
}

?>