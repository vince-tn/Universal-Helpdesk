<?php

namespace App\Commands;

use App\Libraries\Activity;
use App\Libraries\OpenRouter;
use App\Libraries\Settings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

// lists openrouter models that take images, flags the ones that may train on data, probes and picks the image model
class Models extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'helpdesk:models';
    protected $description = 'Shows image capable OpenRouter models, flags models that may train on our data, and picks the image model.';
    protected $usage       = 'helpdesk:models [--probe 8] [--apply] [--all]';

    // small test image that says error code 4271, a model passes only if it reads the code back
    private const PROBE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAQQAAABGCAAAAAAPhLUFAAAFTklEQVR42u2aa2wVRRTHf9s33LYWGwpYaQFTaZBAIgZaaBURFQEVsCUWkKcIBDERii0WiAhGhSBKAAnxC4EQI9CIgaKABRtiqzQUWxWqYqVUeRRJ4Pb9On7YR/fevbxKa/kw59M5O/+ZOfe3OztnNlcTlPkpBAqCgqAgKAgKgoKgICgICoKCoCAoCAqCgqAgKAgKgoKgICgICoKC0AEQEjS7je/YuR/U0m8tqh+kLTf9/S/1Dg4fnHkBgBR7pvst/Wrt4ztOJOBev0uZJRaOKdlAQ3Hxti9Ge6s008lb04Y5HBB6fN7qR3Y+g28/sdxF2bySHu8+sPTyhJMPw6rXjevls5uTTCp54xraMot42DBi5X+zaJbcSnI1GsgSEZHfNeaLiMiZLqTZJA0J9Lykuy3rAoANd5zIPf5inPd3istws0V7B4D+Uzhgk2QVsD0KgO+GLm1K6pDd4ZSm1azqFZawz3KAY2kxwd0SP6zyVNh6/Tinf9f7hm9u1CMPOTTveiIqLCm7VV00u1+XiGFrqx2Tb9/da6vp1/WN66F7/bhea0lKPmLWM7o7rjDq02/oiOVQBK8B7LEcaZhu9I0u9FBYfZoyDMGwqyLecnEbOWeYy2GF8VqL/dlr7rJwcsRlLIdWm4mrNRhJRKWZ/MZqqW3LcrgdCEw+f3Vnk+XITJhYWHdxUwTdztoVVp/VMCa/pjxLY5KIt1xSIL2sNj8ZPx3C+zA2v/bKjmh6XfKYujmJBeKEUBnK81awB9bZGzsMQp9GD+c4vCoiIidDeMHeYFp5MJOaRUSWQYlDngfviojUJcISETkfyLQWEZFzYczzmHoNcVU+IKTCMSt4lMiq9odgM5fx298QD2cGYW5dnY5WbmswbQPaXyIicjnysf0O+XzurxcRke91CCsJuqK3Z9KlzjZMYaB/vjghLIc5VpBrEL07CLezOwz2dI7xbKgepyB5ngoAjjAwFoDuV06Mc8hzeTIIgMTuxnBDjHokmdrC1lFqpja+neBMZuUahm+yovWELbr7PehmxZK/d80UCSAVxBtxPJz3UVVVEGd78XrLK3jIjCsB/iDfqveM4QBYUjpkhSPdpoXbSMwJMcPKr0mJ6AAIISMdmmAPp6aZUHO9QJWnAoB/6Wq7oV7yhhqrNRyA6/aubsvL2RqyI9A7k2uphxm7u3X0L5tJ65RTZFc/qlpTdvlQuKi5sTzIhVkP1OkCZrWuzrlWv13UDdA0TdOqeU/TCgA4N+Iw8/bZCO8lalSnQNBiOG24v0KsD0Vv/jS80fFrHPIYSo1YV8VQfJszn074xW/9VtuzW3+UCf6d8z0hmUNu80Yw3IcgiRL9tHvteGmQQz6GXP3RKC0D4HFO/uZjkJ3mw+EiSyQBODvqYsiexXZNUQPJnfRRZS7uN/UctvBUHx+CGf7NmQB8UB+Q5pDP9KvKAGgxPibM1WS+fvRbGDmw7CYfFlIu+n810eNSAYxolyOKd53Q46jNqkSK4KBZJ+iOTNdLwM0RhJ/1aDBtGUw+VVe6UCPDKZfFMP1M/U/j0esESYeEI9XugpfhRR+buFknrIVUK7F6s2LxVrd7sQQlPiHUmm/kPj+ITwhNCwzBtCanXBrn6GHcIB1Ck7XTj3HfGELLA/bELoiIyGgeaRcIbTlKh+w6lBodFJW0qXiob4H/lqOTowO7Pb13h78PecBnOc/1DI5760R3Q72xYEbfkPABU3MPht540op/nNcuENMuq0FT/21WX5sVBAVBQVAQFAQFQUFQEBQEBUFBUBAUBAVBQVAQFAQFQUFQEBQEBUFBUBDa1f4DyJdhzMyKFd4AAAAASUVORK5CYII=';

    private const PROVIDERS = ['google', 'openai', 'anthropic', 'mistralai', 'qwen', 'meta-llama', 'x-ai', 'amazon', 'microsoft'];

    public function run(array $params)
    {
        if (! OpenRouter::configured()) {
            CLI::error('OPENROUTER_API_KEY is not set');

            return;
        }

        $client = new OpenRouter();
        $models = $client->models();

        $vision = array_values(array_filter($models, [OpenRouter::class, 'takesImages']));
        $flagged = array_values(array_filter($vision, [OpenRouter::class, 'mayTrain']));
        $clean   = array_values(array_filter($vision, static fn ($m) => ! OpenRouter::mayTrain($m) && self::eligible($m)));

        usort($clean, static fn ($a, $b) => self::price($a) <=> self::price($b));

        CLI::write(count($models) . ' models, ' . count($vision) . ' take image input, ' . count($flagged) . ' flagged as may train on data (excluded)', 'yellow');
        CLI::write('text router: ' . OpenRouter::textModel() . ' with data_collection=deny');
        CLI::write('image model now: ' . (OpenRouter::visionModel() ?: 'none') . ', vetted fallback: ' . (Settings::get('openrouter_image_fallback') ?: 'none'));

        if (CLI::getOption('all')) {
            foreach ($flagged as $m) {
                CLI::write('  flagged  ' . $m['id'], 'dark_gray');
            }
        }

        $limit  = max(1, (int) (CLI::getOption('probe') ?? 8));
        $picked = '';

        CLI::newLine();
        CLI::write('probing the cheapest image models with training refused:');

        foreach (array_slice($clean, 0, $limit) as $m) {
            [$ok, $text, $error] = $client->chat((string) $m['id'], [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'What error code is shown in the image? Reply with the number only.'],
                    ['type' => 'image_url', 'image_url' => ['url' => self::PROBE]],
                ],
            ]], 20, 45);

            if ($ok && ! str_contains($text, '4271')) {
                [$ok, $error] = [false, 'could not read the test image (said: ' . mb_substr($text, 0, 30) . ')'];
            }

            $cost = number_format(self::price($m) * 1_000_000, 2);
            CLI::write(sprintf('  %-55s $%s per 1M in  %s', $m['id'], $cost, $ok ? 'ok' : 'no: ' . mb_substr($error, 0, 70)), $ok ? 'green' : 'red');

            if ($ok && $picked === '') {
                $picked = (string) $m['id'];
            }
        }

        if ($picked === '') {
            CLI::error('no image model passed with training refused, check credit and the account privacy settings');

            return;
        }

        CLI::newLine();
        CLI::write('best passing image model: ' . $picked, 'green');

        if (CLI::getOption('apply')) {
            Settings::set('openrouter_image_fallback', $picked);
            Activity::audit('vision_model', 'image fallback set to ' . $picked, null, ['id' => null, 'name' => 'System']);
            CLI::write('saved as the image fallback after the image-processing preset', 'green');
        }
    }

    // real models from known providers only, no routers, batch or variant ids
    private static function eligible(array $m): bool
    {
        $id = (string) ($m['id'] ?? '');

        return in_array(strtok($id, '/'), self::PROVIDERS, true)
            && ! str_contains($id, ':')
            && (float) ($m['pricing']['prompt'] ?? 0) > 0;
    }

    private static function price(array $m): float
    {
        return (float) ($m['pricing']['prompt'] ?? 0) + (float) ($m['pricing']['image'] ?? 0) / 1000;
    }
}
