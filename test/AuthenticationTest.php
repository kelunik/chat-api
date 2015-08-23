<?php

namespace Kelunik\ChatApi;

use PHPUnit_Framework_TestCase;
use function Amp\resolve;
use function Amp\wait;

class AuthenticationTest extends PHPUnit_Framework_TestCase {
    private $authentication;

    public function setUp() {
        $tokenRepository = $this->getMock("Kelunik\\ChatApi\\TokenRepository");
        $userRepository = $this->getMock("Kelunik\\Chat\\Storage\\UserStorage");
        $this->authentication = new Authentication($tokenRepository, $userRepository);
    }

    /**
     * @test
     * @expectedException \Kelunik\ChatApi\AuthenticationException
     */
    public function throwOnInvalidFormat() {
        wait(resolve($this->authentication->authenticateWithToken("")));
    }

    /**
     * @test
     * @expectedException \Kelunik\ChatApi\AuthenticationException
     */
    public function throwOnInvalidCharacters() {
        wait(resolve($this->authentication->authenticateWithToken("za")));
    }

    /**
     * @test
     * @expectedException \Kelunik\ChatApi\AuthenticationException
     */
    public function throwOnInvalidHexLength() {
        wait(resolve($this->authentication->authenticateWithToken("a")));
    }

    /**
     * @test
     * @expectedException \Kelunik\ChatApi\AuthenticationException
     */
    public function throwOnUnknownUser() {
        wait(resolve($this->authentication->authenticateWithToken("123:abcd")));
    }
}