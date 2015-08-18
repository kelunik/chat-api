<?php

use Aerys\Request;
use Aerys\Router;
use Amp\Mysql\Pool;
use Amp\Redis\Client;
use Auryn\Injector;
use function Aerys\router;
use function Amp\resolve;

$mysqlConfig = sprintf(
    "host=%s;user=%s;pass=%s;db=%s",
    config("database.host"),
    config("database.user"),
    config("database.pass"),
    config("database.name")
);

$redisUri = config("redis.protocol") . "://" . config("redis.host") . ":" . config("redis.port");

$injector = new Injector;
$injector->share(new Pool($mysqlConfig));
$injector->share(new Client($redisUri));
$injector->share($injector); // YOLO

$injector->alias("Kelunik\\Chat\\Storage\\MessageStorage", "Kelunik\\Chat\\Storage\\MysqlMessageStorage");
$injector->alias("Kelunik\\Chat\\Storage\\PingStorage", "Kelunik\\Chat\\Storage\\MysqlPingStorage");
$injector->alias("Kelunik\\Chat\\Storage\\RoomStorage", "Kelunik\\Chat\\Storage\\MysqlRoomStorage");
$injector->alias("Kelunik\\Chat\\Storage\\UserStorage", "Kelunik\\Chat\\Storage\\MysqlUserStorage");
$injector->alias("Kelunik\\Chat\\Events\\EventHub", "Kelunik\\Chat\\Events\\NullEventHub");
$injector->alias("Kelunik\\Chat\\RateLimit\\RateLimit", "Kelunik\\Chat\\RateLimit\\Redis");
$injector->define("Kelunik\\Chat\\RateLimit\\Redis", [":ttl" => 300]);

$dispatcher = $injector->make("Kelunik\\ChatApi\\Dispatcher");

/** @var Router $router */
$router = router();
$routes = json_decode(file_get_contents(__DIR__ . "/../res/routes.json"));

foreach ($routes as $route) {
    $router->route($route->method, $route->uri, function (Request $request) use ($route) {
        $request->setLocalVar("chat.api.endpoint", $route->endpoint);
    }, [$dispatcher, "handleApiCall"]);
}

/** @var Kelunik\Chat\RateLimit\Redis $limit */
$rateLimit = $injector->make("\\Kelunik\\Chat\\RateLimit\\Redis", [":ttl" => 300]);

$api = (new Aerys\Host)
    ->expose("*", config("app.port"))
    ->name(config("app.host"))
    ->use([$dispatcher, "handleAuthorization"])
    ->use([$dispatcher, "handleRateLimit"])
    ->use($router);