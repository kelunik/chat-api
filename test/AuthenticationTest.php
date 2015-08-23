<?php

namespace Kelunik\ChatApi;

use Amp\Promise;
use Amp\Success;
use Kelunik\Chat\Storage\UserStorage;
use PHPUnit_Framework_TestCase;
use function Amp\resolve;
use function Amp\wait;

class AuthenticationTest extends PHPUnit_Framework_TestCase {
    /** @var Authentication */
    private $authentication;

    public function setUp() {
        $tokenRepository = new class implements TokenRepository {
            public function get(int $id) {
                return new Success(null);
            }
        };

        $userRepository = new class implements UserStorage {
            public function get(int $id): Promise {
                return new Success(null);
            }

            public function getByName(string $name): Promise {
                return new Success(null);
            }

            public function getAll(int $cursor = 0, bool $asc = true, int $limit = 51): Promise {
                return new Success([]);
            }

            public function getByNames(array $names): Promise {
                return new Success([]);
            }

            public function getByIds(array $ids, bool $asc = true): Promise {
                return new Success([]);
            }
        };

        $this->authentication = new Authentication($tokenRepository, $userRepository);
    }

    /**
     * @test
     * @expectedException AuthenticationException
     */
    public function throwInvalidFormat() {
        wait(resolve($this->authentication->authenticateWithToken("")));
    }

    /**
     * @test
     * @expectedException AuthenticationException
     */
    public function throwOnUnknownUser() {
        wait(resolve($this->authentication->authenticateWithToken("123:abc")));
    }
}