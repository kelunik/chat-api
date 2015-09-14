<?php

namespace Kelunik\ChatApi;

use Amp\Success;
use Kelunik\Chat\Boundaries\User;
use PHPUnit_Framework_TestCase;
use function Amp\resolve;
use function Amp\wait;

class AuthenticationTest extends PHPUnit_Framework_TestCase {
    private $authentication;
    private $userMock;
    private $tokenMock;

    public function setUp() {
        $this->tokenMock = $this->getMock("Kelunik\\ChatApi\\TokenRepository");
        $this->userMock = $this->getMock("Kelunik\\Chat\\Storage\\UserStorage");
        $this->authentication = new Authentication($this->tokenMock, $this->userMock);
    }

    /**
     * @test
     */
    public function authenticateSuccessfully() {
        $this->tokenMock->method("get")->willReturn(new Success((object) [
            "token" => hex2bin("abcd"),
        ]));

        $this->userMock->method("get")->willReturn(new Success((object) [
            "id" => 42,
            "name" => "foobar",
            "avatar" => null,
        ]));

        $result = wait(resolve($this->authentication->authenticateWithToken("42:abcd")));

        $this->assertEquals(new User(42, "foobar", null), $result);
    }

    /**
     * @test
     * @expectedException \Kelunik\ChatApi\AuthenticationException
     * @expectedExceptionMessage user not found or wrong token
     */
    public function throwOnWrongToken() {
        $this->tokenMock->method("get")->willReturn(new Success((object) [
            "token" => hex2bin("abcdef"),
        ]));

        $this->userMock->method("get")->willReturn(new Success((object) [
            "id" => 42,
            "name" => "foobar",
            "avatar" => null,
        ]));

        wait(resolve($this->authentication->authenticateWithToken("42:abcd")));
    }

    /**
     * @test
     * @expectedException \RuntimeException
     * @expectedExceptionMessage user with valid token, but user record does not exist
     */
    public function throwOnUserRecordMissing() {
        $this->tokenMock->method("get")->willReturn(new Success((object) [
            "token" => hex2bin("abcd"),
        ]));

        $this->userMock->method("get")->willReturn(new Success(null));

        wait(resolve($this->authentication->authenticateWithToken("42:abcd")));
    }

    /**
     * @test
     * @expectedException \Kelunik\ChatApi\AuthenticationException
     * @expectedExceptionMessage provided token didn't match the required format
     */
    public function throwOnInvalidFormat() {
        $this->tokenMock->method("get")->willReturn(new Success(null));
        $this->userMock->method("get")->willReturn(new Success(null));

        wait(resolve($this->authentication->authenticateWithToken("")));
    }

    /**
     * @test
     * @expectedException \Kelunik\ChatApi\AuthenticationException
     * @expectedExceptionMessage provided token contained invalid characters
     */
    public function throwOnInvalidHexLength() {
        $this->tokenMock->method("get")->willReturn(new Success(null));
        $this->userMock->method("get")->willReturn(new Success(null));

        wait(resolve($this->authentication->authenticateWithToken("1:a")));
    }

    /**
     * @test
     * @expectedException \Kelunik\ChatApi\AuthenticationException
     * @expectedExceptionMessage user not found or wrong token
     */
    public function throwOnUnknownUser() {
        $this->tokenMock->method("get")->willReturn(new Success(null));
        $this->userMock->method("get")->willReturn(new Success(null));

        wait(resolve($this->authentication->authenticateWithToken("123:abcd")));
    }

    /**
     * @test
     * @expectedException \Kelunik\ChatApi\AuthenticationException
     * @expectedExceptionMessage provided token contained invalid characters
     */
    public function throwOnInvalidCharactersInToken() {
        $this->tokenMock->method("get")->willReturn(new Success(null));
        $this->userMock->method("get")->willReturn(new Success(null));

        wait(resolve($this->authentication->authenticateWithToken("123:zz")));
    }
}