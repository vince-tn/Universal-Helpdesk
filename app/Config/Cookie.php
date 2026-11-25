<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use DateTimeInterface;

class Cookie extends BaseConfig
{

    public string $prefix = '';

    public $expires = 0;

    public string $path = '/';

    public string $domain = '';

    public bool $secure = false;

    public bool $httponly = true;

    public string $samesite = 'Lax';

    public bool $raw = false;

    public function __construct()
    {
        parent::__construct();

        $this->httponly = true;
        $this->samesite = 'Lax';

        // secure only over https, the office lan copy is plain http
        $this->secure = PHP_SAPI !== 'cli' && \App\Libraries\Https::isSecure();
    }
}
