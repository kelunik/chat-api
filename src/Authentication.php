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

        // use @ so we don't have to check for invalid strings manually
        $hash = (string) @hex2bin($hash);

        $user = yield $this->tokenRepository->get($id);

        if (!$user || !hash_equals($user->token, $hash)) {
            throw new AuthenticationException("user not found or wrong token");
        }

        $userData = yield $this->userStorage->get($id);

        if (!$userData) {
            throw new RuntimeException("user with valid token, but record does not exist");
        }

        $user = new User;
        $user->id = $userData->id;
        $user->name = $userData->name;
        $user->avatar = $userData->avatar;

        return $user;
    }
}