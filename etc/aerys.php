<?php

use Aerys\Request;
use Aerys\Response;
use Amp\Mysql\Pool;
use Amp\Redis\Client;
use Auryn\Injector;
use Kelunik\Chat\Boundaries\Error;
use Kelunik\Chat\Boundaries\StandardRequest;
use Kelunik\ChatApi\AuthenticationException;
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

$chat = $injector->make("Kelunik\\Chat\\Chat");
$authentication = $injector->make("Kelunik\\ChatApi\\Authentication");

$apiCallable = function ($endpoint) use ($chat, $authentication) {
    return function (Request $request, Response $response, array $args) use ($endpoint, $chat, $authentication) {
        if (!$request->getHeader("authorization")) {
            $response->setStatus(401);
            $response->setHeader("www-authenticate", "Basic realm=\"use your ID and token\"");
            $response->send("");

            return;
        }

        $response->setHeader("content-type", "application/json");
        $apiResponse = null;

        try {
            foreach ($args as $key => $arg) {
                if (is_numeric($arg)) {
                    $args[$key] = (int) $arg;
                }
            }

            foreach ($request->getQueryVars() as $key => $value) {
                // Don't allow overriding URL parameters
                if (isset($args[$key])) {
                    continue;
                }

                if (is_numeric($value)) {
                    $args[$key] = (int) $value;
                } else if (is_string($value)) {
                    $args[$key] = $value;
                } else {
                    $apiResponse = new Error("bad_request", "invalid query parameter types", 400);

                    return;
                }
            }

            $auth = $request->getHeader("authorization");
            $auth = explode(" ", $auth);

            if (count($auth) !== 2) {
                $apiResponse = new Error("bad_request", "invalid authorization header", 400);

                return;
            }

            switch (strtolower($auth[0])) {
                case "token":
                    break;

                case "basic":
                    $auth[1] = base64_decode($auth[1]);
                    break;

                default:
                    $apiResponse = new Error("bad_request", "invalid authorization header", 400);

                    return;
            }

            $args = $args ? (object) $args : new stdClass;

            $body = yield $request->getBody();
            $payload = $body ? json_decode($body) : null;

            try {
                $user = yield resolve($authentication->authenticateWithToken($auth[1]));
            } catch (AuthenticationException $e) {
                $apiResponse = new Error("bad_authentication", "invalid token in authorization header", 403);

                return;
            }

            $request = new StandardRequest($endpoint, $args, $payload);

            $apiResponse = yield $chat->process($request, $user);
        } finally {
            if ($apiResponse === null) {
                $apiResponse = new Error("internal_error", "there was an internal error", 500);
            }

            $links = $apiResponse->getLinks();

            if ($links) {
                $elements = [];

                foreach ($links as $rel => $params) {
                    $uri = strtok($request->getUri(), "?");
                    $uri .= "?" . http_build_query($params);
                    $elements[] = "<{$uri}>; rel=\"{$rel}\"";
                }

                $response->addHeader("link", implode(", ", $elements));
            }

            $response->setStatus($apiResponse->getStatus());
            $response->send(json_encode($apiResponse->getData(), JSON_PRETTY_PRINT));
        }
    };
};

$router = Aerys\router();
$routes = json_decode(file_get_contents(__DIR__ . "/../res/routes.json"));

foreach ($routes as $route) {
    $router->route($route->method, $route->uri, $apiCallable($route->endpoint));
}

$api = (new Aerys\Host)
    ->expose("*", config("app.port"))
    ->name(config("app.host"))
    ->use($router);