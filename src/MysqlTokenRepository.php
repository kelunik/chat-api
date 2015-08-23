<?php

namespace Kelunik\ChatApi;

use Amp\Mysql\Pool;
use Amp\Mysql\ResultSet;
use function Amp\pipe;

class MysqlTokenRepository implements TokenRepository {
    private $mysql;

    public function __construct(Pool $mysql) {
        $this->mysql = $mysql;
    }

    public function get(int $id) {
        return pipe($this->mysql->prepare("SELECT `token` FROM `auth_token` WHERE `user_id` = ?", [$id]), function (ResultSet $stmt) {
            return $stmt->fetchObject();
        });
    }
}