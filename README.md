# channext-rabbitmq
The Channext x RabbitMQ integration is a package to simplify the publishing and consuming of messages to and from a <a href="https://www.rabbitmq.com/">RabbitMQ</a> server. This package expects you to use a <a href="https://www.rabbitmq.com/tutorials/tutorial-five-php.html">topic exchange</a> and bases its routing on the messages' routing keys.    

**Installation**
1. Since this package is (for now) private you should first add the following code to your composer.json:<br>
`"repositories": [{"type": "git","url": "https://github.com/Channext/channext-rabbitmq"}]`
2. Run `composer require channext/channext-rabbitmq` in the root of your project
3. **Lumen only:** add the following line under the Service Providers in the bootstrap/app.php file:<br> 
`$app->register(Channext\ChannextRabbitmq\Providers\RabbitMQServiceProvider::class);`
4. Run `php artisan vendor:publish` and choose the option "Provider: Channext\ChannextRabbitmq\Providers\RabbitMQServiceProvider"
5. **Lumen only:** add the following line under the Config Files in the bootstrap/app.php file:<br>
`$app->configure('rabbitmq');`
6. Add the correct RabbitMQ credentials to your .env file (see config/rabbitmq.php for the available variables)
7. **Laravel only:** Run `php artisan config:cache`
8. Run `php artisan cache:clear`
9. Run `php artisan migrate`
10. Update the routes/topics.php file with the routes you want to listen to 
11. Run `php artisan rabbitmq:consume` to start consuming messages

**Examples**

This package enables you to use a facade for executing simple publishing and consuming of RabbitMQ messages. To start using the facade simply import it in the class where you want to use it:<br>
`use Channext\ChannextRabbitmq\Facades\RabbitMQ;`

To publish a message simply use the publish method in the RabbitMQ facade:<br>
`RabbitMQ::publish($body, $routingKey);`<br>
Where the body parameter is the data that you want to send and the routing key is the route that consumers can listen to. E.g. the moment a user is created the body should contain all the user data and the routing key could be "user.created".

To start consuming messages you should update the routes/topics.php file with all the routing keys you want your service to listen to. This is actually very similar to the way Laravel usually handles routing: <br>
`RabbitMQ::route('user.login', 'Controller@test');`<br>
This route would only listen to messages with a routing key "user.login" and would execute the "test"-method in the controller.

