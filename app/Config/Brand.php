<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

// picks the look and name of this copy from the BRAND env value
class Brand extends BaseConfig
{
    public string $key = 'chikiting';

    public string $name = 'Chikiting System';

    public string $short = 'Chikiting';

    public string $tagline = 'Support & Ticketing';

    public string $company = '';

    public string $logo = '';

    public bool $logoHasWordmark = false;

    public string $icon = '';

    public string $stylesheet = '';

    public string $timezoneLabel = 'PHT';

    private const BRANDS = [
        'chikiting' => [
            'name'    => 'Chikiting System',
            'short'   => 'Chikiting',
            'tagline' => 'Support & Ticketing',
            'company' => '',
        ],
        'kinect' => [
            'name'            => 'Kinect Service Desk',
            'short'           => 'Service Desk',
            'tagline'         => 'IT, HR and team requests',
            'company'         => 'Kinect',
            'logo'            => 'brand/kinect/logo-reverse.png',
            'logoHasWordmark' => true,
            'icon'            => 'brand/kinect/icon.png',
            'stylesheet'      => 'css/brand-kinect.css',
        ],
        'nais' => [
            'name'       => 'NaisCorp Service Desk',
            'short'      => 'Service Desk',
            'tagline'    => 'IT, HR and team requests',
            'company'    => 'Nais Corp',
            'logo'       => 'brand/nais/logo.png',
            'icon'       => 'brand/nais/icon.png',
            'stylesheet' => 'css/brand-nais.css',
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        $key = strtolower(trim((string) (env('BRAND') ?: 'chikiting')));
        $key = array_key_exists($key, self::BRANDS) ? $key : 'chikiting';

        $this->key = $key;

        foreach (self::BRANDS[$key] as $field => $value) {
            $this->{$field} = $value;
        }

        $this->name = (string) (env('BRAND_NAME') ?: $this->name);

        $this->timezoneLabel = (string) (env('BRAND_TZ_LABEL') ?: $this->timezoneLabel);
    }
}
