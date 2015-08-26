<?php

namespace Kelunik\ChatApi;

use Aerys\Request;
use Aerys\Response;
use Kelunik\Chat\Boundaries\Error;
use Kelunik\Chat\Boundaries\Response as ApiResponse;
use Kelunik\Chat\Boundaries\StandardRequest;
use Kelunik\Chat\Chat;
use Kelunik\Chat\RateLimit\RateLimit;
use stdClass;
use function Amp\resolve;

class Dispatcher {
    const RATE_LIMIT = 60;

    private $authentication;
    private $rateLimit;
    private $chat;

    public function __construct(Authentication $authentication, RateLimit $rateLimit, Chat $chat) {
        $this->authentication = $authentication;
        $this->rateLimit = $rateLimit;
        $this->chat = $chat;
    }

    private function writeResponse(Request $request, Response $response, ApiResponse $result) {
        $response->setStatus($result->getStatus());
        $response->setHeader("content-type", "application/json");

        foreach ($result->getLinks() as $rel => $params) {
            $uri = strtok($request->getUri(), "?");
            $uri .= "?" . http_build_query($params);
            $elements[] = "<{$uri}>; rel=\"{$rel}\"";
        }

        if (isset($elements)) {
            $response->addHeader("link", implode(", ", $elements));
        }

        $response->send(json_encode($result->getData(), JSON_PRETTY_PRINT));
    }

    public function handleAuthorization(Request $request, Response $response) {
        if (!$request->getHeader("authorization")) {
            $response->setStatus(401);
            $response->setHeader("www-authenticate", "Basic realm=\"Use your ID as username and token as password!\"");
            $response->send("");

            return;
        }

        $authorization = $request->getHeader("authorization");
        $authorization = explode(" ", $authorization, 2);

        if (count($authorization) < 2) {
            $result = new Error("bad_request", "invalid authorization header", 400);
            $this->writeResponse($request, $response, $result);

            return;
        }

        switch (strtolower($authorization[0])) {
            case "token":
                break;

            case "basic":
                $authorization[1] = (string) @base64_decode($authorization[1]);
                break;

            default:
                $result = new Error("bad_request", "invalid authorization header", 400);
                $this->writeResponse($request, $response, $result);

                return;
        }

        try {
            $user = yield resolve($this->authentication->authenticateWithToken($authorization[1]));
            $request->setLocalVar("chat.api.user", $user);
        } catch (AuthenticationException $e) {
            $result = new Error("bad_authentication", "invalid token in authorization header", 403);
            $this->writeResponse($request, $response, $result);
        }

        // a callable further down the chain will send the body
    }

    public function handleRateLimit(Request $request, Response $response) {
        $user = $request->getLocalVar("chat.api.user");

        if (!$user) {
            // if this happens, something's really wrong, e.g. wrong order of callables
            $response->setStatus(500);
            $response->send("");

            return;
        }

        $count = yield resolve($this->rateLimit->increment("limit:u:{$user->id}"));
        $ttl = yield resolve($this->rateLimit->ttl("limit:u:{$user->id}"));

        $remaining = self::RATE_LIMIT - $count;

        $response->setHeader("x-rate-limit-limit", self::RATE_LIMIT);
        $response->setHeader("x-rate-limit-remaining", max(0, $remaining));
        $response->setHeader("x-rate-limit-reset", $ttl);

        if ($remaining < 0) {
            $response->setHeader("retry-after", $ttl);

            $error = new Error("too_many_requests", "your application exceeded its rate limit", 429);
            $this->writeResponse($request, $response, $error);
        }

        // a callable further down the chain will send the body
    }

    public function handleApiCall(Request $request, Response $response, array $args) {
        $endpoint = $request->getLocalVar("chat.api.endpoint");
        $user = $request->getLocalVar("chat.api.user");

        if (!$endpoint || !$user) {
            // if this happens, something's really wrong, e.g. wrong order of callables
            $response->setStatus(500);
            $response->send("");
        }

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
                $result = new Error("bad_request", "invalid query parameter types", 400);
                $this->writeResponse($request, $response, $result);

                return;
            }
        }

        $args = $args ? (object) $args : new stdClass;

        $body = yield $request->getBody();
        $payload = $body ? json_decode($body) : null;

        $result = yield $this->chat->process(new StandardRequest($endpoint, $args, $payload), $user);
        $this->writeResponse($request, $response, $result);
    }
}