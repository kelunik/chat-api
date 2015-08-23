<?php

namespace Kelunik\ChatApi;

use Kelunik\Chat\Boundaries\User;
use Kelunik\Chat\Storage\UserStorage;
use RuntimeException;

class Authentication {
    private $tokenRepository;
    private $userStorage;

    public function __construct(TokenRepository $tokenRepository, UserStorage $userStorage) {
        $this->tokenRepository = $tokenRepository;
        $this->userStorage = $userStorage;
    }

    public function authenticateWithToken(string $token) {
        $auth = explode(":", $token, 2);

        if (count($auth) !== 2) {
            throw new AuthenticationException("provided token didn't match the required format");
        }

        list($id, $hash) = $auth;

        if (preg_match("~^([0-9a-f]{2})+$~", $hash) !== 1) {
            throw new AuthenticationException("provided token contained invalid characters");
        }

        $user = yield $this->tokenRepository->get($id);

        if (!$user || !hash_equals($user->token, hex2bin($hash))) {
            throw new AuthenticationException("user not found or wrong token");
        }

        $userData = yield $this->userStorage->get($id);

        if (!$userData) {
            throw new RuntimeException("user with valid token, but user record does not exist");
        }

        return new User($userData->id, $userData->name, $userData->avatar);
    }
}