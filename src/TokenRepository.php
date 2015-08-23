<?php

namespace Kelunik\ChatApi;

interface TokenRepository {
    public function get(int $id);
}