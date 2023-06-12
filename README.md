# channext-rabbitmq
The Channext x RabbitMQ integration

**Installation guide**
1. Since this package is (for now) private you should first add the following code to your composer.json:
`"repositories": [{"type": "git","url": "https://github.com/Channext/channext-rabbitmq"}]`
2. Execute "composer require channext/channext-rabbitmq" in the root of your project
3. Execute "php artisan migrate"
4. Execute "php artisan vendor:publish --provider=Channext\"
