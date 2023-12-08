# channext-rabbitmq
The Channext x RabbitMQ integration is a package to simplify the publishing and consuming of messages to and from a <a href="https://www.rabbitmq.com/">RabbitMQ</a> server. This package expects you to use a <a href="https://www.rabbitmq.com/tutorials/tutorial-five-php.html">topic exchange</a> and bases its routing on the messages' routing keys.    

**Installation**
1. Run `composer require channext/channext-rabbitmq` in the root of your project
2. **Lumen only:** add the following line under the Service Providers in the bootstrap/app.php file:<br> 
`$app->register(Channext\ChannextRabbitmq\Providers\RabbitMQServiceProvider::class);`
3.**Lumen only:** also this of you want to use RabbitMQAuth facade:<br> 
`$app->register(Channext\ChannextRabbitmq\Providers\EventAuthServiceProvider::class);`
4. **Laravel only:** Run `php artisan vendor:publish` and choose the option "Provider: Channext\ChannextRabbitmq\Providers\RabbitMQServiceProvider"
5. **Laravel only:** if you want to use RabbitMQAuth facade and run `php artisan vendor:publish` then choose the option "Provider: Channext\ChannextRabbitmq\Providers\EventAuthServiceProvider"
6. Implement your logic inside EventAuthServiceProvider boot()
7. **Lumen only:** move `vendor/channext/channext-rabbitmq/src/config/rabbitmq.php` to `/config` and then move `vendor/channext/channext-rabbitmq/src/routes/topics.php` to `/routes`
8. **Lumen only:** add the following line under the Config Files in the bootstrap/app.php file:<br>
`$app->configure('rabbitmq');`
9. Add the correct RabbitMQ credentials to your .env file (see config/rabbitmq.php for the available variables. Also be sure to use different queue names for different services, otherwise only one service will act on the event other ones will miss it.)
10. **Laravel only:** Run `php artisan config:cache`
11. Run `php artisan cache:clear`
12. Run `php artisan migrate`
13. Update the routes/topics.php file with the routes you want to listen to 
14. Run `php artisan rabbitmq:consume` to start consuming messages

**Examples**

This package enables you to use a facade for executing simple publishing and consuming of RabbitMQ messages. To start using the facade simply import it in the class where you want to use it:<br>
`use Channext\ChannextRabbitmq\Facades\RabbitMQ;`

To publish a message simply use the publish method in the RabbitMQ facade:<br>
`RabbitMQ::publish($body, $routingKey);`<br>
Where the body parameter is the data that you want to send and the routing key is the route that consumers can listen to. E.g. the moment a user is created the body should contain all the user data and the routing key could be "user.created".

To start consuming messages you should update the routes/topics.php file with all the routing keys you want your service to listen to. This is actually very similar to the way Laravel usually handles routing: <br>
`RabbitMQ::route('user.login', 'Controller@test', expiresAt: 0);`<br>
This route would only listen to messages with a routing key "user.login" and would execute the "test"-method in the controller.

The functions in the controllers called by the router should always accept the message as the first parameter:<br>
`public function test(AMQPMessage $message) { $messageBody = json_decode($message->getBody(), true) }`

